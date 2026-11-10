<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Testing;

use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\BrowserKit\Exception\BadMethodCallException;
use Symfony\Component\BrowserKit\Response;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * Does in a test what the approval page's script does in a browser.
 *
 * It reads the passkey panel off a page (the element marked data-passkey-panel, with the attributes the bundle's
 * approval.js reads), asks the application for a WebAuthn ceremony, and sends back what the authenticator answered.
 * Each request goes as the script's fetch() sends it: the CSRF header, a JSON body, and the headers of a
 * same-origin request.
 *
 *     $answer = PasskeyPanel::open($browser, '/approval/'.$id)->approve($passkey);
 *     self::assertSame('completed', $answer->status, (string) $answer);
 *
 * It serves the bundle's review page and any page of yours with the same attributes, such as an enrollment page in
 * the registration mode. What the application refuses comes back as a PanelAnswer for the test to assert. An
 * exception means something else: a LogicException that the test used the panel wrongly, a RuntimeException that
 * the page, or an answer, is not one the script could work with.
 *
 * After a JSON answer the helper reloads the page, including after a refusal, so a test can assert its current
 * rendered state. The production script may keep the current page after a refusal. BrowserKit runs no JavaScript.
 */
final readonly class PasskeyPanel
{
    private const POLICIES = ['no-referrer', 'no-referrer-when-downgrade', 'same-origin', 'origin', 'strict-origin', 'origin-when-cross-origin', 'strict-origin-when-cross-origin', 'unsafe-url'];

    /**
     * @param AbstractBrowser<*, *> $browser
     */
    private function __construct(
        private AbstractBrowser $browser,
        private string $page,
        private string $origin,
        private string $referrer,
        private string $mode,
        private string $csrf,
        private string $optionsUrl,
        private string $completeUrl,
        private ?string $cancelUrl,
        private bool $cancelButton,
    ) {
    }

    /**
     * Requests a page, as the person opening it does, and takes its passkey panel.
     *
     * @param AbstractBrowser<*, *> $browser a browser in which the person is signed in
     * @param string $url the review page, or a page of yours that holds a panel
     *
     * @throws \RuntimeException when the page has no panel, naming what the application answered instead
     */
    public static function open(AbstractBrowser $browser, string $url): self
    {
        $browser->request('GET', $url);

        return self::on($browser);
    }

    /**
     * Takes the passkey panel of the page the browser is on.
     *
     * @param AbstractBrowser<*, *> $browser
     * @param Crawler|null $panel the panel's element, on a page with more than one: see find()
     *
     * @throws \RuntimeException when the page has no panel, naming what the application answered instead
     */
    public static function on(AbstractBrowser $browser, ?Crawler $panel = null): self
    {
        if (null !== $found = self::find($browser, $panel)) {
            return $found;
        }
        $request = $browser->getInternalRequest();
        $response = $browser->getInternalResponse();
        $redirect = self::redirect($response);

        throw new \RuntimeException(\sprintf('No passkey panel ([data-passkey-panel]) on the page: %s %s answered HTTP %d%s. Is the person signed in in this browser, and is the request still pending?', $request->getMethod(), $request->getUri(), $response->getStatusCode(), null === $redirect ? '' : ', redirecting to '.$redirect));
    }

    /**
     * The passkey panel of the page the browser is on, or null when that page has none.
     *
     * The review page shows the panel only while the request waits for the person, so null is how a test sees
     * that a request can no longer be approved.
     *
     * The script serves every panel on a page; this takes the first. For another, give its element:
     *
     *     PasskeyPanel::on($browser, $browser->getCrawler()->filter('#add-a-passkey [data-passkey-panel]'));
     *
     * @param AbstractBrowser<*, *> $browser
     * @param Crawler|null $panel the panel's element, taken from the browser's crawler since the page was
     *                            last loaded; an element that matched nothing gives null
     *
     * @throws \RuntimeException when the page has a panel the script could not work with
     * @throws \LogicException   when the browser has requested nothing, or the element is from another page
     */
    public static function find(AbstractBrowser $browser, ?Crawler $panel = null): ?self
    {
        try {
            $crawler = $browser->getCrawler();
            $response = $browser->getInternalResponse();
        } catch (BadMethodCallException $unrequested) {
            throw new \LogicException('The browser has not requested a page yet: request the page that holds the passkey panel first, or use PasskeyPanel::open().', 0, $unrequested);
        }
        $panel ??= $crawler->filterXPath('descendant-or-self::*[@data-passkey-panel]');
        $node = $panel->getNode(0);
        if (null === $node) {
            return null;
        }
        $page = $crawler->getUri();
        // The same address would not be enough: once the page is loaded again, an element taken before is from a
        // page that is gone, and may show a panel the page no longer has.
        if ($node->ownerDocument !== $crawler->getNode(0)?->ownerDocument) {
            throw new \LogicException(\sprintf('The element given as the passkey panel is not from the page the browser is on now (%s): take it from $browser->getCrawler() once that page is loaded.', $page ?? 'no page'));
        }
        if (!$node instanceof \DOMElement || !$node->hasAttribute('data-passkey-panel')) {
            throw new \RuntimeException(\sprintf('The element given as the passkey panel on %s (<%s>) is not marked data-passkey-panel, so the script leaves it alone.', $page ?? 'the page', $node->nodeName));
        }
        $panel = $panel->first();
        $origin = null === $page ? null : self::origin($page);
        if (null === $page || null === $origin) {
            throw new \RuntimeException(\sprintf('The page with the passkey panel has no absolute address (%s), so the addresses in the panel cannot be resolved.', $page ?? 'none'));
        }

        // The script takes these from inside the panel, as querySelector does: the panel itself does not count.
        if (0 === $panel->filterXPath('descendant-or-self::*/*[@data-approve]')->count()) {
            throw new \RuntimeException(\sprintf('The passkey panel on %s has no approve button ([data-approve]): the person would have nothing to start the passkey step with.', $page));
        }
        if (0 === $panel->filterXPath('descendant-or-self::*/*[@data-message]')->count()) {
            throw new \RuntimeException(\sprintf('The passkey panel on %s has no message line ([data-message]): the script writes its progress and every refusal there, and stops without one.', $page));
        }

        $base = $crawler->getBaseHref() ?? $page;
        $cancelUrl = trim($panel->attr('data-cancel-url') ?? '');
        $referrer = match (self::referrerPolicy($response->getHeader('Referrer-Policy', false))) {
            // An empty value stops BrowserKit from adding the page it was last on. It cannot leave the header out,
            // so the application sees an empty Referer where a browser sends none.
            'no-referrer' => '',
            'origin', 'strict-origin' => $origin.'/',
            default => explode('#', $page, 2)[0],
        };

        return new self(
            $browser,
            $page,
            $origin,
            $referrer,
            trim($panel->attr('data-mode') ?? ''),
            self::required($panel, 'data-csrf', $page),
            self::address(self::required($panel, 'data-options-url', $page), 'data-options-url', $base, $origin, $page),
            self::address(self::required($panel, 'data-complete-url', $page), 'data-complete-url', $base, $origin, $page),
            '' === $cancelUrl ? null : self::address($cancelUrl, 'data-cancel-url', $base, $origin, $page),
            0 !== $panel->filterXPath('descendant-or-self::*/*[@data-cancel]')->count(),
        );
    }

    /**
     * The panel's data-mode: "approval" on the review page, "registration" on a page that adds a passkey, or another
     * word of the page's own (the demo confirms a change with "confirm"). Empty when the panel does not say.
     */
    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Whether the page offers to cancel the request. The review page leaves that out for a visit that came through
     * a phone code, which may approve the one request and nothing else.
     */
    public function canCancel(): bool
    {
        return $this->cancelButton;
    }

    /**
     * Approves with the passkey, as pressing the button and answering the device's prompt does: asks for the
     * ceremony, signs it, sends the signature, and loads the page again.
     *
     * The answer is the application's to the signature, or to the request for a ceremony when it refused that one
     * (no passkey enrolled, a request that changed, a rate limit). Assert its status: an HTTP 200 answer can still
     * say "invalidated".
     *
     * @throws \LogicException on a panel that registers a passkey
     */
    public function approve(VirtualAuthenticator $passkey): PanelAnswer
    {
        if ('registration' === $this->mode) {
            throw new \LogicException('This panel registers a passkey (data-mode="registration"): call register() on it.');
        }

        return $this->ceremony($passkey->assertion(...));
    }

    /**
     * Registers the passkey on a page in the registration mode: asks for the ceremony, answers it with the new
     * credential, sends that, and loads the page again.
     *
     * @throws \LogicException on a panel that asks for a signature
     */
    public function register(VirtualAuthenticator $passkey): PanelAnswer
    {
        if ('registration' !== $this->mode) {
            throw new \LogicException(\sprintf('This panel asks an enrolled passkey for a signature (data-mode="%s"): call approve() on it.', $this->mode));
        }

        return $this->ceremony($passkey->registration(...));
    }

    /**
     * The first half of approve() and register(): asks the application for a ceremony.
     *
     * Unless it was refused, the answer holds "publicKey", the options to give the authenticator, and on the
     * bundle's routes the "ceremony_id" that names the ceremony. Use the halves to do something in between, or to
     * sign with changes:
     *
     *     $ceremony = $panel->begin();
     *     // ... what happens while the person looks at their device ...
     *     $answer = $panel->complete($ceremony->data['ceremony_id'], $passkey->assertion($ceremony->data['publicKey']));
     *
     * The browser is left on the JSON answer, as the page is left alone between the two requests.
     *
     * @throws \RuntimeException when the application answered something else than a refusal or a ceremony
     */
    public function begin(): PanelAnswer
    {
        $answer = $this->send($this->optionsUrl, '{}');
        if (!$answer->refused()) {
            $this->optionsIn($answer);
        }

        return $answer;
    }

    /**
     * The second half: sends what the authenticator answered, then loads the page again.
     *
     * @param string|null          $ceremonyId the "ceremony_id" of begin()'s answer. Null for a page of yours whose
     *                                         answer names none: then none is sent, which is what the script does
     * @param array<string, mixed> $credential from VirtualAuthenticator::assertion() or registration()
     */
    public function complete(?string $ceremonyId, array $credential): PanelAnswer
    {
        return $this->finish((null === $ceremonyId ? [] : ['ceremony_id' => $ceremonyId]) + ['credential' => $credential]);
    }

    /**
     * Cancels the request, as pressing the panel's cancel button does, then loads the page again.
     *
     * @throws \LogicException when the page offers no cancel button: see canCancel()
     */
    public function cancel(): PanelAnswer
    {
        if (!$this->cancelButton) {
            throw new \LogicException(\sprintf('The passkey panel on %s has no cancel button ([data-cancel]), so the person cannot cancel there: check canCancel() first.', $this->page));
        }
        if (null === $this->cancelUrl) {
            throw new \RuntimeException(\sprintf('The passkey panel on %s has a cancel button and no data-cancel-url: the script would have no address to send the cancellation to.', $this->page));
        }
        $answer = $this->send($this->cancelUrl, '{}');
        $this->reload();

        return $answer;
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $respond the authenticator's answer to the options
     */
    private function ceremony(\Closure $respond): PanelAnswer
    {
        $ceremony = $this->begin();
        if ($ceremony->refused()) {
            $this->reload();

            return $ceremony;
        }
        // The script sends back whatever the answer called its ceremony, and no name when the answer gave none.
        $named = \array_key_exists('ceremony_id', $ceremony->data) ? ['ceremony_id' => $ceremony->data['ceremony_id']] : [];

        return $this->finish($named + ['credential' => $respond($this->optionsIn($ceremony))]);
    }

    /**
     * @return array<string, mixed> the WebAuthn options of a ceremony
     */
    private function optionsIn(PanelAnswer $answer): array
    {
        $options = $answer->data['publicKey'] ?? null;
        if (!\is_array($options)) {
            throw new \RuntimeException(\sprintf('POST %s answered %s, which is not a ceremony: the script reads a "publicKey" object from it, the options it gives the passkey.', $this->optionsUrl, $answer));
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function finish(array $body): PanelAnswer
    {
        $answer = $this->send($this->completeUrl, json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        $this->reload();

        return $answer;
    }

    /** Sends one request as the script's fetch() does, leaving the browser's history as it was. */
    private function send(string $url, string $body): PanelAnswer
    {
        $this->browser->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $this->origin,
            'HTTP_REFERER' => $this->referrer,
            'HTTP_SEC_FETCH_DEST' => 'empty',
            'HTTP_SEC_FETCH_MODE' => 'cors',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_X_CSRF_TOKEN' => $this->csrf,
        ], $body, false);
        $response = $this->browser->getInternalResponse();
        $content = $response->getContent();
        if (!json_decode($content) instanceof \stdClass || !\is_array($data = json_decode($content, true))) {
            $landed = $this->browser->getInternalRequest()->getUri();
            $redirect = self::redirect($response);
            $text = self::text($content);

            throw new \RuntimeException(\sprintf('POST %s did not answer with a JSON object, which is all the script reads: HTTP %d%s%s%s.', $url, $response->getStatusCode(), $landed === $url ? '' : ' from '.$landed.', where a redirect led', null === $redirect ? '' : ', redirecting to '.$redirect, '' === $text ? '' : ', "'.$text.'"'));
        }

        return new PanelAnswer($response->getStatusCode(), $data);
    }

    /** Loads the panel's page again for rendered-state assertions, including after a refusal the script may show without reloading. */
    private function reload(): void
    {
        $this->browser->request('GET', $this->page, [], [], [], null, false);
    }

    private static function required(Crawler $panel, string $attribute, string $page): string
    {
        $value = trim($panel->attr($attribute) ?? '');
        if ('' === $value) {
            throw new \RuntimeException(\sprintf('The passkey panel on %s has no %s: the script sends every request with it.', $page, $attribute));
        }

        return $value;
    }

    /** An address from the panel, made absolute as the browser resolves it. The script's requests carry the person's session only to the page's own origin. */
    private static function address(string $url, string $attribute, string $base, string $origin, string $page): string
    {
        $absolute = UriResolver::resolve($url, $base);
        $parts = parse_url($absolute);
        if (!\is_array($parts) || self::origin($absolute) !== $origin) {
            throw new \RuntimeException(\sprintf('The passkey panel on %s has %s="%s", which is not on the page\'s origin (%s): the script\'s request would arrive there without the person\'s session.', $page, $attribute, $url, $origin));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException(\sprintf('The passkey panel on %s has a user name or a password in its %s, an address fetch() refuses to request.', $page, $attribute));
        }

        // As the browser writes the address it requests: the origin in lower case, and no fragment.
        return $origin.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** scheme://host[:port] as a browser writes an origin: lower case, and no port when it is the scheme's own. */
    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $origin = $scheme.'://'.strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        return null === $port || $port === (['http' => 80, 'https' => 443][$scheme] ?? null) ? $origin : $origin.':'.$port;
    }

    /**
     * The policy a browser applies: the last word it recognises across the page's Referrer-Policy headers.
     *
     * @param array<mixed>|string|null $headers
     */
    private static function referrerPolicy(array|string|null $headers): ?string
    {
        $policy = null;
        foreach ((array) $headers as $header) {
            foreach (explode(',', \is_string($header) ? $header : '') as $word) {
                $word = strtolower(trim($word));
                if (\in_array($word, self::POLICIES, true)) {
                    $policy = $word;
                }
            }
        }

        return $policy;
    }

    /** Where a response redirects to, when it is a redirect. */
    private static function redirect(Response $response): ?string
    {
        $location = $response->getHeader('Location');

        return $response->getStatusCode() >= 300 && $response->getStatusCode() < 400 && \is_string($location) && '' !== $location ? $location : null;
    }

    /** What a body that is not JSON says, in one line: an error page's title, or the start of its text. */
    private static function text(string $content): string
    {
        if (1 === preg_match('~<title[^>]*>(.*?)</title>~is', $content, $title)) {
            $content = $title[1];
        }
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($content), \ENT_QUOTES | \ENT_HTML5)));
        // No more than 200 characters, or 200 bytes of a text that is not UTF-8.
        $start = 1 === preg_match('/^.{0,200}/su', $text, $match) ? $match[0] : substr($text, 0, 200);

        return $start === $text ? $text : $start.'…';
    }
}
