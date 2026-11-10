<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Testing;

use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response;
use VerifiedHumanApproval\Testing\PasskeyPanel;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;

/**
 * The passkey panel helper against a scripted browser: what it puts on the wire, and what it says when a page or an
 * answer is not one the bundle's script could work with.
 */
final class PasskeyPanelTest extends TestCase
{
    private const ORIGIN = 'https://app.test';
    private const PAGE = self::ORIGIN.'/approval/apr_1';
    private const PANEL = 'data-mode="approval" data-csrf="csrf-1" data-options-url="/approval/apr_1/options" data-complete-url="/approval/apr_1/complete" data-cancel-url="/approval/apr_1/cancel"';
    private const INSIDE = '<button type="button" data-approve>Approve</button><button type="button" data-cancel>Cancel</button><p data-message aria-live="polite"></p>';
    private const CEREMONY = ['ceremony_id' => 'ceremony-1', 'publicKey' => ['challenge' => 'Y2hhbGxlbmdl', 'rpId' => 'app.test', 'userVerification' => 'required'], 'expires_at' => 1790000000];
    private const NOT_JSON = 'POST https://app.test/approval/apr_1/options did not answer with a JSON object, which is all the script reads: ';

    public function testEachRequestGoesAsTheScriptsFetchSendsIt(): void
    {
        $browser = self::reviewing();
        $panel = PasskeyPanel::open($browser, self::PAGE);
        self::assertSame('approval', $panel->mode());
        self::assertTrue($panel->canCancel());

        $ceremony = $panel->begin();
        self::assertFalse($ceremony->refused());
        self::assertSame(self::CEREMONY, $ceremony->data);
        $answer = $panel->complete('ceremony-1', ['id' => 'credential-1', 'response' => ['signature' => 'a/b']]);
        self::assertSame(200, $answer->httpStatus);
        self::assertSame('completed', $answer->status);
        self::assertNull($answer->reason);
        self::assertFalse($answer->refused());
        self::assertSame('HTTP 200 {"status":"completed"}', (string) $answer);

        self::assertSame(['GET '.self::PAGE, 'POST '.self::PAGE.'/options', 'POST '.self::PAGE.'/complete', 'GET '.self::PAGE], self::sent($browser));
        [, $options, $complete, $reload] = $browser->requests;
        self::assertSame('{}', $options->getContent());
        self::assertSame('{"ceremony_id":"ceremony-1","credential":{"id":"credential-1","response":{"signature":"a/b"}}}', $complete->getContent());
        foreach ([$options, $complete] as $post) {
            self::assertSame([], $post->getParameters(), 'The body is JSON, not form fields.');
            self::assertSame([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => 'https://app.test',
                'HTTP_REFERER' => self::PAGE,
                'HTTP_SEC_FETCH_DEST' => 'empty',
                'HTTP_SEC_FETCH_MODE' => 'cors',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
                'HTTP_X_CSRF_TOKEN' => 'csrf-1',
            ], self::fetchHeaders($post));
        }
        // The page is then loaded again as a page: none of the fetch's headers, and no body.
        self::assertNull($reload->getContent());
        foreach (['CONTENT_TYPE', 'HTTP_ACCEPT', 'HTTP_ORIGIN', 'HTTP_SEC_FETCH_MODE', 'HTTP_X_CSRF_TOKEN'] as $header) {
            self::assertArrayNotHasKey($header, $reload->getServer());
        }
    }

    public function testTheBrowsersHistoryIsLeftAsAVisitToThePageLeavesIt(): void
    {
        $browser = self::reviewing(['GET '.self::ORIGIN.'/requests' => new Response('<html><body><a href="/approval/apr_1">Review</a></body></html>')]);
        $browser->request('GET', self::ORIGIN.'/requests');

        $answer = PasskeyPanel::open($browser, self::PAGE)->approve(new VirtualAuthenticator(self::ORIGIN));

        self::assertSame('completed', $answer->status, (string) $answer);
        self::assertSame(self::PAGE, $browser->getInternalRequest()->getUri(), 'The browser ends on the page, loaded again.');
        self::assertSame('Review', $browser->getCrawler()->filterXPath('descendant-or-self::title')->text());
        $browser->back();
        self::assertSame(
            ['GET '.self::ORIGIN.'/requests', 'GET '.self::PAGE, 'POST '.self::PAGE.'/options', 'POST '.self::PAGE.'/complete', 'GET '.self::PAGE, 'GET '.self::ORIGIN.'/requests'],
            self::sent($browser),
            'Back leaves the page for the one before it: the script\'s requests are no visits.',
        );
    }

    #[DataProvider('addresses')]
    public function testAnAddressInThePanelIsResolvedAsTheBrowserResolvesIt(string $page, string $head, string $optionsUrl, string $expected): void
    {
        $browser = new ScriptedBrowser([
            'GET '.$page => self::page('data-mode="approval" data-csrf="csrf-1" data-options-url="'.$optionsUrl.'" data-complete-url="/complete"', self::INSIDE, $head),
            'POST '.$expected => self::json(self::CEREMONY),
        ]);

        PasskeyPanel::open($browser, $page)->begin();

        self::assertSame('POST '.$expected, self::sent($browser)[1]);
    }

    public static function addresses(): iterable
    {
        yield 'a path from the root' => [self::PAGE, '', '/approval/apr_1/options', self::ORIGIN.'/approval/apr_1/options'];
        yield 'a path beside the page' => [self::ORIGIN.'/account/passkey', '', 'passkey/options', self::ORIGIN.'/account/passkey/options'];
        yield 'a path that climbs, with a query' => [self::ORIGIN.'/account/passkey', '', '../account/passkey/options?step=1', self::ORIGIN.'/account/passkey/options?step=1'];
        yield 'a query alone' => [self::PAGE, '', '?do=options', self::PAGE.'?do=options'];
        yield 'a path under the page\'s base element' => [self::ORIGIN.'/account/passkey', '<base href="/app/">', 'passkey/options', self::ORIGIN.'/app/passkey/options'];
        yield 'the origin in capitals, its default port and a fragment' => [self::PAGE, '', 'HTTPS://APP.TEST:443/approval/apr_1/options#top', self::ORIGIN.'/approval/apr_1/options'];
        yield 'the origin alone' => [self::PAGE, '', 'https://app.test', self::ORIGIN.'/'];
    }

    #[DataProvider('addressesOffThePagesOrigin')]
    public function testAnAddressOffThePagesOriginIsRefusedBeforeAnythingIsSent(string $completeUrl): void
    {
        $browser = new ScriptedBrowser(['GET '.self::PAGE => self::page('data-mode="approval" data-csrf="csrf-1" data-options-url="/approval/apr_1/options" data-complete-url="'.$completeUrl.'"')]);

        $thrown = self::thrown(\RuntimeException::class, static fn () => PasskeyPanel::open($browser, self::PAGE));

        self::assertStringContainsString('has data-complete-url="'.$completeUrl.'", which is not on the page\'s origin (https://app.test)', $thrown->getMessage());
        self::assertSame(['GET '.self::PAGE], self::sent($browser));
    }

    public static function addressesOffThePagesOrigin(): iterable
    {
        yield 'another host' => ['https://elsewhere.test/complete'];
        yield 'another scheme' => ['http://app.test/complete'];
        yield 'another port' => ['https://app.test:8443/complete'];
        yield 'another host, on the page\'s scheme' => ['//elsewhere.test/complete'];
        yield 'not an address a request goes to' => ['javascript:void(0)'];
        yield 'a host that only starts like the page\'s' => ['https://app.test.elsewhere.test/complete'];
        yield 'the page\'s host as a user name' => ['https://app.test@elsewhere.test/complete'];
        yield 'no host at all' => ['http:///complete'];
    }

    public function testAnAddressWithAUserNameIsRefusedAsFetchRefusesIt(): void
    {
        $browser = new ScriptedBrowser(['GET '.self::PAGE => self::page('data-mode="approval" data-csrf="csrf-1" data-options-url="/approval/apr_1/options" data-complete-url="https://person@app.test/approval/apr_1/complete"')]);

        $thrown = self::thrown(\RuntimeException::class, static fn () => PasskeyPanel::open($browser, self::PAGE));

        self::assertStringContainsString('has a user name or a password in its data-complete-url', $thrown->getMessage());
    }

    #[DataProvider('incompletePanels')]
    public function testAPanelTheScriptCouldNotWorkWithIsNamedForWhatItLacks(string $panel, string $inside, string $lacks): void
    {
        $browser = new ScriptedBrowser(['GET '.self::PAGE => self::page($panel, $inside)]);

        $thrown = self::thrown(\RuntimeException::class, static fn () => PasskeyPanel::open($browser, self::PAGE));

        self::assertStringStartsWith('The passkey panel on https://app.test/approval/apr_1 '.$lacks, $thrown->getMessage());
        self::assertSame(['GET '.self::PAGE], self::sent($browser));
    }

    public static function incompletePanels(): iterable
    {
        $addresses = ' data-options-url="/approval/apr_1/options" data-complete-url="/approval/apr_1/complete"';
        yield 'no approve button' => [self::PANEL, '<p data-message></p>', 'has no approve button ([data-approve])'];
        yield 'the panel itself marked as the button' => [self::PANEL.' data-approve', '<p data-message></p>', 'has no approve button ([data-approve])'];
        yield 'no message line' => [self::PANEL, '<button type="button" data-approve>Approve</button>', 'has no message line ([data-message])'];
        yield 'no token' => ['data-mode="approval"'.$addresses, self::INSIDE, 'has no data-csrf'];
        yield 'a blank token' => ['data-mode="approval" data-csrf=" "'.$addresses, self::INSIDE, 'has no data-csrf'];
        yield 'no address for the ceremony' => ['data-mode="approval" data-csrf="csrf-1" data-complete-url="/approval/apr_1/complete"', self::INSIDE, 'has no data-options-url'];
        yield 'no address for the answer' => ['data-mode="approval" data-csrf="csrf-1" data-options-url="/approval/apr_1/options"', self::INSIDE, 'has no data-complete-url'];
    }

    public function testAPageWithoutAPanelGivesNullOrSaysWhatTheApplicationAnswered(): void
    {
        $settled = new Response('<!DOCTYPE html><html><head><title>Review</title></head><body><main><p>Approved.</p></main></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $browser = new ScriptedBrowser(['GET '.self::PAGE => $settled]);
        $browser->request('GET', self::PAGE);
        $message = 'No passkey panel ([data-passkey-panel]) on the page: GET https://app.test/approval/apr_1 answered HTTP 200. Is the person signed in in this browser, and is the request still pending?';

        self::assertNull(PasskeyPanel::find($browser));
        self::assertSame($message, self::thrown(\RuntimeException::class, static fn () => PasskeyPanel::on($browser))->getMessage());
        self::assertSame($message, self::thrown(\RuntimeException::class, static fn () => PasskeyPanel::open($browser, self::PAGE))->getMessage());
    }

    public function testAPageThatSendsThePersonToSignInSaysSo(): void
    {
        $script = [
            'GET '.self::PAGE => new Response('', 302, ['Location' => self::ORIGIN.'/login']),
            'GET '.self::ORIGIN.'/login' => new Response('<html><head><title>Sign in</title></head><body><form method="post"></form></body></html>'),
        ];

        $followed = new ScriptedBrowser($script);
        self::assertSame(
            'No passkey panel ([data-passkey-panel]) on the page: GET https://app.test/login answered HTTP 200. Is the person signed in in this browser, and is the request still pending?',
            self::thrown(\RuntimeException::class, static fn () => PasskeyPanel::open($followed, self::PAGE))->getMessage(),
        );

        // A kernel browser does not follow a redirect by itself.
        $unfollowed = new ScriptedBrowser($script);
        $unfollowed->followRedirects(false);
        self::assertSame(
            'No passkey panel ([data-passkey-panel]) on the page: GET https://app.test/approval/apr_1 answered HTTP 302, redirecting to https://app.test/login. Is the person signed in in this browser, and is the request still pending?',
            self::thrown(\RuntimeException::class, static fn () => PasskeyPanel::open($unfollowed, self::PAGE))->getMessage(),
        );
        self::assertNull(PasskeyPanel::find($unfollowed));
    }

    public function testABrowserThatHasRequestedNothingIsAMistakeInTheTest(): void
    {
        $browser = new ScriptedBrowser([]);

        foreach ([static fn () => PasskeyPanel::find($browser), static fn () => PasskeyPanel::on($browser)] as $use) {
            self::assertStringStartsWith('The browser has not requested a page yet', self::thrown(\LogicException::class, $use)->getMessage());
        }
        self::assertSame([], $browser->requests);
    }

    public function testThePanelsModeDecidesWhatThePasskeyIsAskedFor(): void
    {
        $passkey = new VirtualAuthenticator(self::ORIGIN);

        $registering = self::reviewing(['GET '.self::PAGE => self::page('data-mode="registration" data-csrf="csrf-1" data-options-url="/approval/apr_1/options" data-complete-url="/approval/apr_1/complete"')]);
        $panel = PasskeyPanel::open($registering, self::PAGE);
        self::assertSame('registration', $panel->mode());
        self::assertSame('This panel registers a passkey (data-mode="registration"): call register() on it.', self::thrown(\LogicException::class, static fn () => $panel->approve($passkey))->getMessage());
        self::assertSame(['GET '.self::PAGE], self::sent($registering), 'Nothing is sent for the wrong call.');

        $approving = self::reviewing();
        $panel = PasskeyPanel::open($approving, self::PAGE);
        self::assertSame('This panel asks an enrolled passkey for a signature (data-mode="approval"): call approve() on it.', self::thrown(\LogicException::class, static fn () => $panel->register($passkey))->getMessage());
        self::assertSame(['GET '.self::PAGE], self::sent($approving));

        // The script registers only where the mode says so: any other word, or none, asks for a signature.
        $unmarked = self::reviewing(['GET '.self::PAGE => self::page('data-csrf="csrf-1" data-options-url="/approval/apr_1/options" data-complete-url="/approval/apr_1/complete"')]);
        $panel = PasskeyPanel::open($unmarked, self::PAGE);
        self::assertSame('', $panel->mode());
        self::assertSame('This panel asks an enrolled passkey for a signature (data-mode=""): call approve() on it.', self::thrown(\LogicException::class, static fn () => $panel->register($passkey))->getMessage());
        self::assertSame('completed', $panel->approve($passkey)->status);
    }

    #[DataProvider('answersThatAreNotAJsonObject')]
    public function testAnAnswerTheScriptCouldNotReadSaysWhatCameInstead(Response $answer, bool $followRedirects, string $expected): void
    {
        $browser = self::reviewing([
            'POST '.self::PAGE.'/options' => $answer,
            'GET '.self::ORIGIN.'/login' => new Response('<html><head><title>Sign in</title></head><body><form method="post"></form></body></html>'),
        ]);
        $panel = PasskeyPanel::open($browser, self::PAGE);
        $browser->followRedirects($followRedirects);

        $thrown = self::thrown(\RuntimeException::class, static fn () => $panel->begin());

        self::assertSame(self::NOT_JSON.$expected, $thrown->getMessage());
    }

    public static function answersThatAreNotAJsonObject(): iterable
    {
        yield 'an error page' => [new Response('<html><head><title>Internal Server Error</title></head><body><h1>Oops</h1></body></html>', 500), true, 'HTTP 500, "Internal Server Error".'];
        yield 'a redirect the browser followed' => [new Response('', 302, ['Location' => '/login']), true, 'HTTP 200 from https://app.test/login, where a redirect led, "Sign in".'];
        yield 'a redirect it did not follow' => [new Response('', 302, ['Location' => '/login']), false, 'HTTP 302, redirecting to /login.'];
        yield 'a JSON list' => [self::json(['a']), true, 'HTTP 200, "["a"]".'];
        yield 'a JSON string' => [new Response('"completed"', 200, ['Content-Type' => 'application/json']), true, 'HTTP 200, ""completed"".'];
        yield 'a JSON null' => [new Response('null', 200, ['Content-Type' => 'application/json']), true, 'HTTP 200, "null".'];
        yield 'nothing' => [new Response('', 204), true, 'HTTP 204.'];
        yield 'plain text' => [new Response("Too many\nrequests", 429, ['Content-Type' => 'text/plain']), true, 'HTTP 429, "Too many requests".'];
        yield 'a long text' => [new Response(str_repeat('é', 250), 502, ['Content-Type' => 'text/plain; charset=UTF-8']), true, 'HTTP 502, "'.str_repeat('é', 200).'…".'];
    }

    #[DataProvider('referrerPolicies')]
    public function testTheRefererFollowsThePagesReferrerPolicy(array $headers, string $referer): void
    {
        $browser = self::reviewing(['GET '.self::PAGE => self::page(headers: $headers)]);

        PasskeyPanel::open($browser, self::PAGE)->begin();

        self::assertSame($referer, $browser->requests[1]->getServer()['HTTP_REFERER']);
    }

    public static function referrerPolicies(): iterable
    {
        yield 'no policy' => [[], self::PAGE];
        yield 'no-referrer' => [['Referrer-Policy' => 'no-referrer'], ''];
        yield 'origin' => [['Referrer-Policy' => 'origin'], 'https://app.test/'];
        yield 'strict-origin' => [['Referrer-Policy' => 'strict-origin'], 'https://app.test/'];
        yield 'same-origin' => [['Referrer-Policy' => 'same-origin'], self::PAGE];
        yield 'strict-origin-when-cross-origin' => [['Referrer-Policy' => 'strict-origin-when-cross-origin'], self::PAGE];
        yield 'the last word the browser knows' => [['Referrer-Policy' => 'no-referrer, made-up-policy, Origin , another'], 'https://app.test/'];
        yield 'the last of two headers, as a kernel browser gives them' => [['referrer-policy' => ['origin', 'no-referrer']], ''];
        yield 'only words it does not know' => [['Referrer-Policy' => 'made-up-policy'], self::PAGE];
    }

    public function testAFragmentInThePagesAddressIsNotSentAsTheReferer(): void
    {
        $page = self::PAGE.'#return=handoff';
        $browser = self::reviewing(['GET '.$page => self::page()]);

        $answer = PasskeyPanel::open($browser, $page)->approve(new VirtualAuthenticator(self::ORIGIN));

        self::assertSame('completed', $answer->status, (string) $answer);
        self::assertSame(['GET '.$page, 'POST '.self::PAGE.'/options', 'POST '.self::PAGE.'/complete', 'GET '.$page], self::sent($browser));
        self::assertSame(self::PAGE, $browser->requests[1]->getServer()['HTTP_REFERER']);
        self::assertSame(self::PAGE, $browser->requests[2]->getServer()['HTTP_REFERER']);
    }

    public function testAnOriginKeepsAPortThatIsNotItsSchemesOwn(): void
    {
        $page = 'http://localhost:8080/approval/apr_1';
        $browser = new ScriptedBrowser([
            'GET '.$page => self::page(),
            'POST '.$page.'/options' => self::json(self::CEREMONY),
        ]);

        PasskeyPanel::open($browser, $page)->begin();

        self::assertSame('http://localhost:8080', $browser->requests[1]->getServer()['HTTP_ORIGIN']);
        self::assertSame($page, $browser->requests[1]->getServer()['HTTP_REFERER']);
    }

    public function testCancellingIsOnlyWhatThePageOffers(): void
    {
        $offered = self::reviewing(['POST '.self::PAGE.'/cancel' => self::json(['status' => 'cancelled'])]);
        $answer = PasskeyPanel::open($offered, self::PAGE)->cancel();
        self::assertSame('cancelled', $answer->status, (string) $answer);
        self::assertSame(['GET '.self::PAGE, 'POST '.self::PAGE.'/cancel', 'GET '.self::PAGE], self::sent($offered));
        self::assertSame('{}', $offered->requests[1]->getContent());
        self::assertSame('csrf-1', $offered->requests[1]->getServer()['HTTP_X_CSRF_TOKEN']);

        // A visit that came through a phone code gets the panel without the button.
        $withheld = self::reviewing(['GET '.self::PAGE => self::page(inside: '<button type="button" data-approve>Approve</button><p data-message></p>')]);
        $panel = PasskeyPanel::open($withheld, self::PAGE);
        self::assertFalse($panel->canCancel());
        self::assertStringContainsString('has no cancel button ([data-cancel])', self::thrown(\LogicException::class, static fn () => $panel->cancel())->getMessage());
        self::assertSame(['GET '.self::PAGE], self::sent($withheld));

        $broken = self::reviewing(['GET '.self::PAGE => self::page('data-mode="approval" data-csrf="csrf-1" data-options-url="/approval/apr_1/options" data-complete-url="/approval/apr_1/complete"')]);
        $panel = PasskeyPanel::open($broken, self::PAGE);
        self::assertTrue($panel->canCancel());
        self::assertStringContainsString('has a cancel button and no data-cancel-url', self::thrown(\RuntimeException::class, static fn () => $panel->cancel())->getMessage());
        self::assertSame(['GET '.self::PAGE], self::sent($broken));
    }

    public function testARefusedCeremonyIsAnAnswerAndThePageIsLoadedAgain(): void
    {
        $refusal = ['status' => 'rejected', 'reason' => 'credential_required', 'message' => 'Add a passkey first.', 'request_status' => 'pending'];
        $browser = self::reviewing(['POST '.self::PAGE.'/options' => self::json($refusal, 409)]);

        $answer = PasskeyPanel::open($browser, self::PAGE)->approve(new VirtualAuthenticator(self::ORIGIN));

        self::assertTrue($answer->refused());
        self::assertSame(409, $answer->httpStatus);
        self::assertSame('rejected', $answer->status);
        self::assertSame('credential_required', $answer->reason);
        self::assertSame($refusal, $answer->data);
        self::assertSame(['GET '.self::PAGE, 'POST '.self::PAGE.'/options', 'GET '.self::PAGE], self::sent($browser), 'Nothing is signed, and the browser ends on the page.');

        // Half by half, the test decides what comes next.
        $browser = self::reviewing(['POST '.self::PAGE.'/options' => self::json($refusal, 409)]);
        self::assertSame('credential_required', PasskeyPanel::open($browser, self::PAGE)->begin()->reason);
        self::assertSame(['GET '.self::PAGE, 'POST '.self::PAGE.'/options'], self::sent($browser));
    }

    public function testAnAnswerThatIsNotACeremonyIsNotSigned(): void
    {
        $message = 'POST https://app.test/approval/apr_1/options answered HTTP 200 {}, which is not a ceremony: the script reads a "publicKey" object from it, the options it gives the passkey.';

        $browser = self::reviewing(['POST '.self::PAGE.'/options' => new Response('{}', 200, ['Content-Type' => 'application/json'])]);
        $panel = PasskeyPanel::open($browser, self::PAGE);
        self::assertSame($message, self::thrown(\RuntimeException::class, static fn () => $panel->begin())->getMessage());
        self::assertSame($message, self::thrown(\RuntimeException::class, static fn () => $panel->approve(new VirtualAuthenticator(self::ORIGIN)))->getMessage());
        self::assertSame(['GET '.self::PAGE, 'POST '.self::PAGE.'/options', 'POST '.self::PAGE.'/options'], self::sent($browser));

        $browser = self::reviewing(['POST '.self::PAGE.'/options' => self::json(['ceremony_id' => 7, 'publicKey' => 'challenge'])]);
        $panel = PasskeyPanel::open($browser, self::PAGE);
        self::assertStringContainsString('answered HTTP 200 {"ceremony_id":7,"publicKey":"challenge"}, which is not a ceremony', self::thrown(\RuntimeException::class, static fn () => $panel->begin())->getMessage());
    }

    public function testAPageWithTwoPanelsTakesTheFirstOrTheOneGiven(): void
    {
        $page = self::ORIGIN.'/account/passkeys';
        $inside = '<button type="button" data-approve>Continue</button><p data-message></p>';
        $browser = new ScriptedBrowser([
            'GET '.$page => new Response(
                '<!DOCTYPE html><html><head><title>Passkeys</title></head><body>'
                .'<section id="confirm"><div data-passkey-panel data-mode="confirm" data-csrf="csrf-1" data-options-url="/account/passkeys/confirm/options" data-complete-url="/account/passkeys/confirm/complete">'.$inside.'</div></section>'
                .'<section id="add"><div data-passkey-panel data-mode="registration" data-csrf="csrf-2" data-options-url="/account/passkeys/options" data-complete-url="/account/passkeys/complete">'.$inside.'</div></section>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'POST '.$page.'/options' => self::json(self::CEREMONY),
        ]);
        $browser->request('GET', $page);

        self::assertSame('confirm', PasskeyPanel::on($browser)->mode());
        self::assertSame('confirm', PasskeyPanel::on($browser, $browser->getCrawler()->filter('[data-passkey-panel]'))->mode(), 'Of several elements, the first.');
        self::assertNull(PasskeyPanel::find($browser, $browser->getCrawler()->filter('#nothing [data-passkey-panel]')), 'An element that matched nothing is no panel.');
        $section = $browser->getCrawler()->filter('#add');
        self::assertStringContainsString('(<section>) is not marked data-passkey-panel', self::thrown(\RuntimeException::class, static fn () => PasskeyPanel::on($browser, $section))->getMessage());

        $taken = $browser->getCrawler()->filter('#add [data-passkey-panel]');
        $panel = PasskeyPanel::on($browser, $taken);
        self::assertSame('registration', $panel->mode());
        $panel->begin();
        self::assertSame('POST '.$page.'/options', self::sent($browser)[1]);
        self::assertSame('csrf-2', $browser->requests[1]->getServer()['HTTP_X_CSRF_TOKEN']);

        // Between the two halves the browser is on the JSON answer, which has no panel and no elements.
        self::assertNull(PasskeyPanel::find($browser));
        self::assertStringContainsString(
            'is not from the page the browser is on now (https://app.test/account/passkeys/options)',
            self::thrown(\LogicException::class, static fn () => PasskeyPanel::find($browser, $taken))->getMessage(),
        );

        // Loaded again, the page is a new document: an element from the old one may show a panel that is gone.
        $browser->request('GET', $page);
        self::assertStringContainsString(
            'is not from the page the browser is on now (https://app.test/account/passkeys)',
            self::thrown(\LogicException::class, static fn () => PasskeyPanel::on($browser, $taken))->getMessage(),
        );
        self::assertSame('registration', PasskeyPanel::on($browser, $browser->getCrawler()->filter('#add [data-passkey-panel]'))->mode());
    }

    public function testAPasskeyRegistersAndThenApprovesThroughThePanels(): void
    {
        $enrollment = self::ORIGIN.'/account/passkey';
        $registration = ['ceremony_id' => 'ceremony-0', 'publicKey' => ['challenge' => 'cmVnaXN0ZXI', 'rp' => ['id' => 'app.test', 'name' => 'App'], 'user' => ['id' => 'YWxpY2U', 'name' => 'alice', 'displayName' => 'Alice']]];
        $browser = self::reviewing([
            'GET '.$enrollment => self::page('data-mode="registration" data-csrf="csrf-0" data-options-url="/account/passkey/options" data-complete-url="/account/passkey/complete"', '<button type="button" data-approve>Add a passkey</button><p data-message></p>'),
            'POST '.$enrollment.'/options' => self::json($registration),
            'POST '.$enrollment.'/complete' => self::json(['status' => 'registered']),
        ]);
        $passkey = new VirtualAuthenticator(self::ORIGIN);

        $registered = PasskeyPanel::open($browser, $enrollment)->register($passkey);
        $approved = PasskeyPanel::open($browser, self::PAGE)->approve($passkey);

        self::assertSame('registered', $registered->status, (string) $registered);
        self::assertSame('completed', $approved->status, (string) $approved);
        self::assertSame([
            'GET '.$enrollment, 'POST '.$enrollment.'/options', 'POST '.$enrollment.'/complete', 'GET '.$enrollment,
            'GET '.self::PAGE, 'POST '.self::PAGE.'/options', 'POST '.self::PAGE.'/complete', 'GET '.self::PAGE,
        ], self::sent($browser));
        $sent = [2 => ['ceremony-0', 'webauthn.create', 'cmVnaXN0ZXI', 'attestationObject'], 6 => ['ceremony-1', 'webauthn.get', 'Y2hhbGxlbmdl', 'signature']];
        foreach ($sent as $index => [$ceremony, $type, $challenge, $proof]) {
            $content = (string) $browser->requests[$index]->getContent();
            $body = json_decode($content, true, 16, \JSON_THROW_ON_ERROR);
            self::assertSame(['ceremony_id', 'credential'], array_keys($body));
            self::assertSame($ceremony, $body['ceremony_id']);
            self::assertSame($passkey->id(), $body['credential']['id']);
            self::assertNotSame('', $body['credential']['response'][$proof]);
            self::assertSame(
                ['type' => $type, 'challenge' => $challenge, 'origin' => self::ORIGIN, 'crossOrigin' => false],
                json_decode(Base64UrlSafe::decodeNoPadding($body['credential']['response']['clientDataJSON']), true, 4, \JSON_THROW_ON_ERROR),
            );
            self::assertStringContainsString('"clientExtensionResults":{}', $content, 'An empty object stays an object, as the browser sends it.');
        }
        self::assertSame('csrf-0', $browser->requests[2]->getServer()['HTTP_X_CSRF_TOKEN']);
        self::assertSame('csrf-1', $browser->requests[6]->getServer()['HTTP_X_CSRF_TOKEN']);
    }

    public function testACeremonyGoesBackUnderTheNameTheApplicationGaveItOrUnderNone(): void
    {
        // A page of the host's own may keep its ceremony in the session and name none. The script then sends none.
        $enrollment = self::ORIGIN.'/account/passkey';
        $options = ['challenge' => 'cmVnaXN0ZXI', 'rp' => ['id' => 'app.test', 'name' => 'App'], 'user' => ['id' => 'YWxpY2U', 'name' => 'alice', 'displayName' => 'Alice']];
        $unnamed = static fn (): ScriptedBrowser => new ScriptedBrowser([
            'GET '.$enrollment => self::page('data-mode="registration" data-csrf="csrf-0" data-options-url="/account/passkey/options" data-complete-url="/account/passkey/complete"', '<button type="button" data-approve>Add a passkey</button><p data-message></p>'),
            'POST '.$enrollment.'/options' => self::json(['publicKey' => $options]),
            'POST '.$enrollment.'/complete' => self::json(['status' => 'registered']),
        ]);
        $passkey = new VirtualAuthenticator(self::ORIGIN);

        $browser = $unnamed();
        $registered = PasskeyPanel::open($browser, $enrollment)->register($passkey);
        self::assertSame('registered', $registered->status, (string) $registered);
        self::assertSame(['credential'], array_keys(json_decode((string) $browser->requests[2]->getContent(), true, 16, \JSON_THROW_ON_ERROR)));

        $browser = $unnamed();
        $panel = PasskeyPanel::open($browser, $enrollment);
        $ceremony = $panel->begin();
        self::assertSame(['publicKey'], array_keys($ceremony->data));
        $panel->complete($ceremony->data['ceremony_id'] ?? null, $passkey->registration($ceremony->data['publicKey']));
        self::assertSame(['credential'], array_keys(json_decode((string) $browser->requests[2]->getContent(), true, 16, \JSON_THROW_ON_ERROR)));
        self::assertSame(['GET '.$enrollment, 'POST '.$enrollment.'/options', 'POST '.$enrollment.'/complete', 'GET '.$enrollment], self::sent($browser));

        // Whatever the name is, it goes back as it came: the script does not look at it.
        $browser = self::reviewing(['POST '.self::PAGE.'/options' => self::json(['ceremony_id' => 7] + self::CEREMONY)]);
        PasskeyPanel::open($browser, self::PAGE)->approve($passkey);
        self::assertStringStartsWith('{"ceremony_id":7,"credential":{', (string) $browser->requests[2]->getContent());
    }

    /** A browser on an application that answers the review page, a ceremony and a completion. */
    private static function reviewing(array $script = []): ScriptedBrowser
    {
        return new ScriptedBrowser($script + [
            'GET '.self::PAGE => self::page(),
            'POST '.self::PAGE.'/options' => self::json(self::CEREMONY),
            'POST '.self::PAGE.'/complete' => self::json(['status' => 'completed']),
        ]);
    }

    private static function page(string $panel = self::PANEL, string $inside = self::INSIDE, string $head = '', array $headers = []): Response
    {
        return new Response(
            '<!DOCTYPE html><html><head><title>Review</title>'.$head.'</head><body><main><div data-passkey-panel '.$panel.'>'.$inside.'</div></main></body></html>',
            200,
            $headers + ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private static function json(array $data, int $status = 200): Response
    {
        return new Response(json_encode($data, \JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json']);
    }

    /** @return list<string> each request the browser sent, as "METHOD address" */
    private static function sent(ScriptedBrowser $browser): array
    {
        return array_map(static fn (Request $request): string => $request->getMethod().' '.$request->getUri(), $browser->requests);
    }

    /** What the helper added to a request, without what every BrowserKit request carries. */
    private static function fetchHeaders(Request $request): array
    {
        $server = array_diff_key($request->getServer(), ['HTTP_USER_AGENT' => true, 'HTTP_HOST' => true, 'HTTPS' => true]);
        ksort($server);

        return $server;
    }

    /**
     * Runs the call and returns what it threw. PHPUnit's own failure is a RuntimeException, so a fail() inside a
     * try that catches one would pass unseen.
     */
    private static function thrown(string $class, \Closure $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $thrown) {
            self::assertSame($class, $thrown::class, $thrown->getMessage());

            return $thrown;
        }
        self::fail('Nothing was thrown, where '.$class.' was expected.');
    }
}
