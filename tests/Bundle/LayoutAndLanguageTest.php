<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Source;
use VerifiedHumanApproval\Bundle\ContentSecurityPolicy;
use VerifiedHumanApproval\Bundle\VerifiedHumanApprovalBundle;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Tests\Bundle\Fixture\ReviewKernel;

/**
 * The review pages in an application's own layout and language: a nonce and a short list of origins for the
 * layout, a translation domain for the wording, and the agent's own name. The policy stays as strict as before.
 */
final class LayoutAndLanguageTest extends TestCase
{
    private const ORIGIN = 'https://app.example';
    private const BUNDLE = __DIR__.'/../../src/symfony-bundle';
    private const LAYOUT = __DIR__.'/Fixture/layout';
    /** Today's policy, with only the response's nonce added. */
    private const STRICT = "default-src 'self'; script-src 'self' 'nonce-%1\$s'; style-src 'self' 'nonce-%1\$s'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'";
    private const NO_CODE = '/approval/handoff/QQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQ';

    private string $runtime;
    /** @var list<KernelBrowser> */ private array $browsers = [];

    protected function setUp(): void
    {
        $this->runtime = ReviewKernel::runtime();
    }

    protected function tearDown(): void
    {
        foreach ($this->browsers as $browser) {
            $browser->getKernel()->shutdown();
        }
        (new Filesystem())->remove($this->runtime);
        // ReviewKernel trusts app.example only, and Symfony keeps that for the whole process.
        Request::setTrustedHosts([]);
    }

    public function testEachResponseHasANonceThatThePolicyAndTheLayoutShare(): void
    {
        $browser = $this->browser(templates: self::LAYOUT);
        $id = $this->requestAction($browser);
        $nonces = [];
        foreach (['/approval/'.$id, '/approval/'.$id, self::NO_CODE] as $path) {
            $page = $browser->request('GET', self::ORIGIN.$path);
            $nonce = $this->nonce($browser);
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/D', $nonce, '144 random bits, nothing to escape.');
            self::assertSame(\sprintf(self::STRICT, $nonce), $browser->getResponse()->headers->get('Content-Security-Policy'), $path);
            foreach (['style', 'script[type="importmap"]', 'script[type="module"]:not([src])'] as $inline) {
                self::assertSame($nonce, $page->filter($inline)->attr('nonce'), $path.': the layout’s '.$inline.' carries the header’s nonce.');
            }
            $nonces[] = $nonce;
        }
        self::assertSame(410, $browser->getResponse()->getStatusCode(), 'An expired phone code’s page gets the same headers.');
        self::assertCount(3, array_unique($nonces), 'Every response has its own nonce.');
    }

    public function testThePolicyKeepsOneNoncePerRequestAndRefusesWhatTheConfigurationRefuses(): void
    {
        $policy = new ContentSecurityPolicy();
        [$first, $second] = [Request::create('/approval/a'), Request::create('/approval/b')];
        self::assertSame($policy->nonce($first), $policy->nonce($first));
        self::assertNotSame($policy->nonce($first), $policy->nonce($second));
        self::assertSame(\sprintf(self::STRICT, $policy->nonce($first)), $policy->header($first), 'With nothing added, the policy is as strict as before.');

        foreach ([['script_src' => ["'unsafe-inline'"]], ['img_src' => ['http:']], ['default_src' => ["'self'"]]] as $sources) {
            try {
                new ContentSecurityPolicy($sources);
                self::fail('Accepted '.json_encode($sources));
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testConfiguredOriginsJoinOnlyTheirOwnDirectives(): void
    {
        $browser = $this->browser(['content_security_policy' => [
            'script_src' => ['https://cdn.example.com'],
            'style_src' => ['https://fonts.googleapis.com', "'self'"],
            'img_src' => ['https://images.example.com', 'data:'],
            'font_src' => ['https://fonts.gstatic.com', 'data:'],
            'connect_src' => ['https://api.example.com:8443'],
        ]], templates: self::LAYOUT);
        $browser->request('GET', self::ORIGIN.'/approval/'.$this->requestAction($browser));
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $nonce = $this->nonce($browser);
        self::assertSame(
            "default-src 'self'; script-src 'self' 'nonce-$nonce' https://cdn.example.com; style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; img-src 'self' data: https://images.example.com; font-src 'self' https://fonts.gstatic.com data:; connect-src 'self' https://api.example.com:8443; frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'",
            $browser->getResponse()->headers->get('Content-Security-Policy'),
        );
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function unsafeSources(): iterable
    {
        yield 'inline script' => ['script_src', "'unsafe-inline'"];
        yield 'eval' => ['script_src', "'unsafe-eval'"];
        yield 'inline event handlers' => ['script_src', "'unsafe-hashes'"];
        yield 'any source' => ['script_src', '*'];
        yield 'any http source' => ['script_src', 'http:'];
        yield 'any https source' => ['script_src', 'https:'];
        yield 'an http origin' => ['script_src', 'http://cdn.example.com'];
        yield 'data scripts' => ['script_src', 'data:'];
        yield 'blob scripts' => ['script_src', 'blob:'];
        yield 'trust passed on' => ['script_src', "'strict-dynamic'"];
        yield 'a fixed nonce' => ['script_src', "'nonce-abc'"];
        yield 'a hash' => ['script_src', "'sha256-47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU='"];
        yield 'every subdomain' => ['script_src', 'https://*.example.com'];
        yield 'a directive smuggled in' => ['script_src', 'https://cdn.example.com; script-src *'];
        yield 'two sources in one' => ['script_src', "https://cdn.example.com 'unsafe-inline'"];
        yield 'a trailing newline' => ['script_src', "https://cdn.example.com\n"];
        yield 'self without its quotes' => ['script_src', 'self'];
        yield 'a host without a scheme' => ['script_src', 'cdn.example.com'];
        yield 'a path' => ['script_src', 'https://cdn.example.com/library/'];
        yield 'inline style' => ['style_src', "'unsafe-inline'"];
        yield 'data styles' => ['style_src', 'data:'];
        yield 'any image' => ['img_src', '*'];
        yield 'http images' => ['img_src', 'http:'];
        yield 'any https font' => ['font_src', 'https:'];
        yield 'nothing' => ['font_src', ''];
        yield 'data connections' => ['connect_src', 'data:'];
        yield 'a websocket elsewhere' => ['connect_src', 'wss://socket.example.com'];
        yield 'a port that cannot be' => ['connect_src', 'https://api.example.com:70000'];
        yield 'not text' => ['img_src', 42];
    }

    #[DataProvider('unsafeSources')]
    public function testAnUnsafeSourceIsRefusedWhenTheContainerIsBuilt(string $key, mixed $source): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('verified_human_approval.content_security_policy.'.$key);
        $this->load(['content_security_policy' => [$key => ['https://fine.example.com', $source]]]);
    }

    public function testOnlyTheFiveDirectivesCanBeExtended(): void
    {
        foreach (['default_src', 'frame_ancestors', 'form_action', 'base_uri', 'object_src'] as $key) {
            try {
                $this->load(['content_security_policy' => [$key => ["'self'"]]]);
                self::fail($key.' was accepted.');
            } catch (InvalidConfigurationException $error) {
                self::assertStringContainsString('Unrecognized option "'.$key.'"', $error->getMessage());
            }
        }
        // What is accepted reaches the policy service as configured; a single source needs no list, and none is none.
        $container = $this->load(['content_security_policy' => ['font_src' => ['https://fonts.gstatic.com', "'self'", 'data:'], 'connect_src' => 'https://localhost:8443', 'img_src' => null]]);
        $sources = $container->getDefinition('verified_human_approval.content_security_policy')->getArgument(0);
        ksort($sources);
        self::assertSame(['connect_src' => ['https://localhost:8443'], 'font_src' => ['https://fonts.gstatic.com', "'self'", 'data:'], 'img_src' => [], 'script_src' => [], 'style_src' => []], $sources);
        // A single unsafe source is refused all the same.
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['content_security_policy' => ['script_src' => '*']]);
    }

    public function testAnApplicationWithAnUnsafePolicyDoesNotBoot(): void
    {
        foreach ([
            "'unsafe-inline'" => '"\'unsafe-inline\'" cannot be added to script-src',
            // Its value would arrive after the check, so it is refused too.
            '%env(VERIFIED_HUMAN_APPROVAL_TEST_CDN)%' => 'An empty value, or an environment variable, cannot be added to script-src',
        ] as $source => $refusal) {
            $kernel = new ReviewKernel($this->runtime, ['content_security_policy' => ['script_src' => [$source]]]);
            try {
                $kernel->boot();
                self::fail('The kernel booted with '.$source.' in script-src.');
            } catch (InvalidConfigurationException $error) {
                self::assertStringContainsString($refusal, $error->getMessage());
            } finally {
                $kernel->shutdown();
            }
        }
    }

    public function testATranslationReplacesTheBundlesWording(): void
    {
        $browser = $this->browser(translations: [
            'VerifiedHumanApproval' => [
                'en' => 'fr',
                'Approve with your passkey' => 'Approuver avec votre clé d’accès',
                'Requested by %requester%.' => 'Demandé par %requester%.',
                'an agent connected to your account' => 'un agent relié à votre compte',
                'Waiting for your device…' => 'En attente de votre appareil…',
                // A translation is text, even where the sentence holds a link.
                'Anyone can check it against the site’s %published_keys%, even after the site changes its key.' => 'Chacun peut la <em>vérifier</em> avec les %published_keys% du site.',
                'published keys' => 'clés publiées',
                'Expires %time%.' => 'Expire à <b>%time%</b>.',
            ],
            // The same words in another domain are not the bundle's.
            'messages' => ['Cancel request' => 'Wrong domain'],
        ]);
        $id = $this->requestAction($browser);
        $page = $browser->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame('fr', $page->filter('html')->attr('lang'), 'The page says which language it is in.');
        self::assertSame('Approuver avec votre clé d’accès', $page->filter('[data-approve]')->text());
        self::assertSame('Agent request Demandé par un agent relié à votre compte.', $page->filter('.approval-sheet .requester')->text());
        self::assertSame('Cancel request', $page->filter('[data-cancel]')->text(), 'What the application leaves alone stays in English.');
        $script = $this->messages($page, '[data-passkey-panel]');
        self::assertSame('En attente de votre appareil…', $script['waiting'], 'The script is handed the translation.');
        self::assertSame(self::english()['sending'], $script['sending']);
        $expiry = $page->filter('[data-passkey-panel] p.muted');
        self::assertStringContainsString('Expire à <b>', $expiry->text(), 'The translation’s markup is shown as text.');
        self::assertCount(1, $expiry->filter('time[data-local-time]'), 'The time goes in after the sentence is escaped.');

        $this->approve($browser, $id);
        $receipt = $browser->request('GET', self::ORIGIN.'/approval/'.$id)->filter('.approval-sheet .receipt p');
        self::assertStringEndsWith('Chacun peut la <em>vérifier</em> avec les clés publiées du site.', $receipt->text());
        self::assertCount(0, $receipt->filter('em'), 'The translation’s markup is shown as text.');
        self::assertSame(['/approval/receipt-keys.json', 'clés publiées'], [$receipt->filter('a')->attr('href'), $receipt->filter('a')->text()]);
    }

    public function testTheAgentIsCalledByTheNameTheApplicationGivesIt(): void
    {
        $browser = $this->browser(['agent_name' => 'Aria']);
        $agent = $this->requestAction($browser);
        $this->approve($browser, $agent);
        $done = $browser->request('GET', self::ORIGIN.'/approval/'.$agent);
        // The hidden ways back answer Claude's own approval card (#return=claude-…) and open Claude, so they say Claude.
        self::assertSame('Back to Claude', $done->filter('[data-return-app]')->text());
        self::assertSame('claude://', $done->filter('[data-return-app]')->attr('href'), 'The card in Claude asks for this link with #return=claude-desktop or claude-mobile.');
        self::assertSame('Back to your Claude tab', $done->filter('[data-return-tab]')->text());
        self::assertSame('You can close this tab and return to Aria.', $done->filter('[data-return-hint]')->text());
        self::assertSame([
            'returnApp' => 'Return to Claude to see the result. You can close this tab.',
            'returnHandoff' => 'Done. Return to Aria on your computer to see the result.',
            'returnTab' => 'Switch back to your Claude tab to see the result.',
        ], $this->messages($done, '[data-return]'));
        self::assertNotNull($done->filter('[data-return]')->attr('hidden'), 'The ways back stay hidden until the card asks for one.');
        self::assertCount(0, $done->filterXPath("//body//text()[contains(., 'Claude')][not(ancestor::*[@hidden])]"), 'Nothing a person sees by default names Claude.');

        $expired = $browser->request('GET', self::ORIGIN.self::NO_CODE);
        self::assertSame(410, $browser->getResponse()->getStatusCode());
        self::assertSame('Each phone code works once, for two minutes. Show a new code in Aria and scan it again.', $expired->filter('main p')->text());

        // A request the person made on the site never sends them to the agent.
        $own = $this->requestAction($browser, null, 2);
        $this->approve($browser, $own);
        $mine = $browser->request('GET', self::ORIGIN.'/approval/'.$own);
        self::assertSame('Your request Requested by you, on this site.', $mine->filter('.approval-sheet .requester')->text());
        self::assertCount(0, $mine->filter('[data-return], [data-return-hint]'));
        self::assertStringNotContainsString('Aria', (string) $browser->getResponse()->getContent());
    }

    public function testSymfonysOwnTranslatorReadsTheBundlesCatalogueAndTheApplicationsLanguage(): void
    {
        // An application's French catalogue for two of the bundle's messages; the rest fall back to the bundle's English.
        $path = $this->runtime.'/app-translations';
        mkdir($path, 0700, true);
        file_put_contents($path.'/VerifiedHumanApproval.fr.php', '<?php return '.var_export(['en' => 'fr', 'Approve with your passkey' => 'Approuver avec votre clé d’accès'], true).';');
        $browser = $this->browser(realTranslator: ['locale' => 'fr', 'path' => $path]);
        $id = $this->requestAction($browser);

        $page = $browser->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertTrue($browser->getContainer()->has('translator'), 'Symfony’s own translator, found the bundle’s catalogue with no configuration.');
        self::assertSame('fr', $page->filter('html')->attr('lang'), 'The page declares the language it is written in.');
        self::assertSame('Approuver avec votre clé d’accès', $page->filter('[data-approve]')->text());
        self::assertSame('Cancel request', $page->filter('[data-cancel]')->text(), 'What the application did not translate falls back to the bundle’s English.');
    }

    public function testWithoutATranslatorThePagesReadInEnglishAsBefore(): void
    {
        $browser = $this->browser();
        $browser->getKernel()->boot();
        self::assertFalse($browser->getKernel()->getContainer()->has('translator'), 'This application has no translator.');
        $id = $this->requestAction($browser);

        $page = $browser->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertSame('en', $page->filter('html')->attr('lang'));
        self::assertSame('Review this action', $page->filter('title')->text());
        self::assertSame('Approval requested · fixture', $page->filter('.approval-sheet .eyebrow')->text());
        self::assertSame('Agent request Requested by an agent connected to your account.', $page->filter('.approval-sheet .requester')->text());
        self::assertSame('What you are approving', $page->filter('.sheet-preview h2')->text());
        self::assertSame('Approve with your passkey', $page->filter('[data-approve]')->text());
        self::assertSame('Cancel request', $page->filter('[data-cancel]')->text());
        self::assertStringStartsWith('Only this exact proposal runs, once. A changed draft, a revoked permission or an expired request stops it. Expires ', $page->filter('[data-passkey-panel] p.muted')->text());
        // Times carry their instant in UTC, so the script can show them in the reader's own time zone.
        $times = $page->filter('time[data-local-time]');
        self::assertSame(['time', 'datetime'], $times->each(static fn ($time): string => (string) $time->attr('data-local-time')));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $times->first()->attr('datetime'));
        self::assertSame($times->first()->attr('datetime'), $times->last()->attr('datetime'));
        self::assertSame('Details and everything this approval is bound to', $page->filter('summary')->text());
        self::assertSame('The application checks current permissions and the stored proposal before it executes.', $page->filter('footer')->text());
        // The script is handed its own English, under keys it knows.
        $script = $this->messages($page, '[data-passkey-panel]');
        self::assertSame(array_intersect_key(self::english(), $script), $script);
        self::assertCount(\count($script), array_intersect_key($script, self::english()));

        $this->approve($browser, $id);
        $done = $browser->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertSame('Approved · fixture', $done->filter('.approval-sheet .eyebrow')->text());
        self::assertSame('Approved with your passkey. This request is completed.', $done->filter('.notice.outcome')->text());
        self::assertSame('Back to Claude', $done->filter('[data-return-app]')->text());
        self::assertSame('Back to your Claude tab', $done->filter('[data-return-tab]')->text());
        // Unless the application names its agent, the page names none: any agent's host can open it.
        self::assertSame('You can close this tab and return to your AI assistant.', $done->filter('[data-return-hint]')->text());
        $return = $this->messages($done, '[data-return]');
        self::assertSame(array_intersect_key(self::english(), $return), $return);
        self::assertCount(3, $return);
        $receipt = $done->filter('.approval-sheet .receipt p');
        self::assertSame('Your copy of this exact request, your passkey’s signature on it, and what ran, signed by this site as it ran. Keep it: if the site’s record were changed later, your copy would show it. Anyone can check it against the site’s published keys, even after the site changes its key.', $receipt->text());
        self::assertSame(['/approval/receipt-keys.json', 'published keys'], [$receipt->filter('a')->attr('href'), $receipt->filter('a')->text()]);

        $expired = $browser->request('GET', self::ORIGIN.self::NO_CODE);
        self::assertSame('Link expired', $expired->filter('title')->text());
        self::assertSame('Each phone code works once, for two minutes. Show a new code in your AI assistant and scan it again.', $expired->filter('main p')->text());
    }

    public function testTheEnglishCatalogueHoldsExactlyTheStringsThePagesTranslate(): void
    {
        $browser = $this->browser();
        $browser->getKernel()->boot();
        $twig = $browser->getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $visitor = $twig->getExtension(TranslationExtension::class)->getTranslationNodeVisitor();
        $translated = [];
        foreach (glob(self::BUNDLE.'/templates/*.html.twig') ?: [] as $file) {
            $visitor->enable();
            $twig->parse($twig->tokenize(new Source((string) file_get_contents($file), basename($file), $file)));
            foreach ($visitor->getMessages() as [$message, $domain]) {
                self::assertSame('VerifiedHumanApproval', $domain, basename($file).' translates "'.$message.'" in the bundle’s domain.');
                $translated[$message] = true;
            }
            $visitor->disable();
        }
        self::assertGreaterThan(50, \count($translated));

        $catalogue = self::catalogue();
        self::assertEqualsCanonicalizing(array_keys($translated), array_keys($catalogue));
        foreach ($catalogue as $source => $target) {
            self::assertSame($source, $target, 'The English catalogue reads as the pages do without a translator.');
        }
        // The script's own English is the catalogue's, so a page that hands over no wording reads the same.
        foreach (self::english() as $key => $english) {
            if (!\in_array($key, ['registered', 'unconfirmedRegistration', 'unconfirmedConfirm'], true)) {
                // The ways back that open Claude name Claude; the rest name the configured agent, by default this one.
                self::assertArrayHasKey(\in_array($key, ['returnApp', 'returnTab'], true) ? $english : str_replace('your AI assistant', '%agent%', $english), $catalogue, $key);
            }
        }
    }

    /**
     * @param array<string, mixed>                      $approval
     * @param array<string, array<string, string>>|null $translations
     */
    /** @param array{locale: string, path: string}|null $realTranslator */
    private function browser(array $approval = [], ?array $translations = null, ?string $templates = null, ?array $realTranslator = null): KernelBrowser
    {
        $browser = new KernelBrowser(new ReviewKernel($this->runtime, $approval, $translations, $templates, $realTranslator), ['HTTP_HOST' => 'app.example', 'HTTPS' => 'on']);
        $this->browsers[] = $browser;

        return $browser;
    }

    private function approvals(KernelBrowser $browser): ApprovalService
    {
        $browser->getKernel()->boot();
        $service = $browser->getContainer()->get(ApprovalService::class);
        self::assertInstanceOf(ApprovalService::class, $service);

        return $service;
    }

    /** A pending request for alice's article: by her agent, or by alice on the site when $delegation is null. */
    private function requestAction(KernelBrowser $browser, ?string $delegation = 'agent-1', int $article = 1): string
    {
        $state = $this->approvals($browser)->request(new Actor('alice', 'tenant-a', $delegation), 'article.publish', ['id' => $article]);
        self::assertSame('pending', $state->status);

        return $state->request->id;
    }

    private function approve(KernelBrowser $browser, string $id): void
    {
        $approvals = $this->approvals($browser);
        $alice = new Actor('alice', 'tenant-a');
        $ceremony = $approvals->begin($alice, $id);
        $proof = ['test_only' => true, 'challenge' => $ceremony->challenge, 'user' => 'alice', 'tenant' => 'tenant-a', 'credential' => 'alice-key'];
        self::assertSame('completed', $approvals->complete($alice, $ceremony->id, $proof)->status);
    }

    private function nonce(KernelBrowser $browser): string
    {
        self::assertSame(1, preg_match("/script-src 'self' 'nonce-([^']+)'/", (string) $browser->getResponse()->headers->get('Content-Security-Policy'), $match));

        return $match[1];
    }

    /** @return array<string, string> the wording a page hands approval.js on one element */
    private function messages(Crawler $page, string $selector): array
    {
        $messages = json_decode((string) $page->filter($selector)->attr('data-messages'), true, 4, \JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);

        return $messages;
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag(['kernel.environment' => 'test', 'kernel.build_dir' => $this->runtime, 'kernel.debug' => false]));
        $extension = (new VerifiedHumanApprovalBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([[
            'public_origin' => self::ORIGIN, 'actor_provider' => 'actors', 'policy_provider' => 'policies',
            'authorizer' => 'authorizer', 'store' => 'store', 'verifier' => 'verifier',
        ] + $config], $container);

        return $container;
    }

    /** @return array<string, string> approval.js's own English, by key */
    private static function english(): array
    {
        $script = (string) file_get_contents(self::BUNDLE.'/public/approval.js');
        self::assertSame(1, preg_match('/^const ENGLISH = \{\n(.*?)\n\};$/ms', $script, $object));
        self::assertGreaterThan(0, preg_match_all("/^    (\\w+): '([^'\\\\]*)',$/m", $object[1], $entries, \PREG_SET_ORDER));

        return array_column($entries, 2, 1);
    }

    /** @return array<string, string> the bundle's English catalogue, target by source */
    private static function catalogue(): array
    {
        $document = new \DOMDocument();
        self::assertTrue($document->load(self::BUNDLE.'/translations/VerifiedHumanApproval.en.xlf'));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
        $catalogue = $ids = [];
        foreach ($xpath->query('//x:trans-unit') ?: [] as $unit) {
            self::assertInstanceOf(\DOMElement::class, $unit);
            $id = $unit->getAttribute('id');
            $source = $xpath->evaluate('string(x:source)', $unit);
            self::assertArrayNotHasKey($id, $ids, 'Each id once.');
            self::assertArrayNotHasKey($source, $catalogue, 'Each source once.');
            $ids[$id] = true;
            $catalogue[$source] = $xpath->evaluate('string(x:target)', $unit);
        }

        return $catalogue;
    }
}
