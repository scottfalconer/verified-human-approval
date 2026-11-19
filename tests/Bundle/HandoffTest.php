<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\RequestException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use VerifiedHumanApproval\Bundle\Handoff\ApprovalHandoff;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\Tests\Support\CoreFixture;

/** "Approve on your phone": a one-time code that opens one approval, and nothing else, on another device. */
final class HandoffTest extends TestCase
{
    private const ORIGIN = 'https://alerts.example';
    private string $runtime;
    private array $accounts;
    /** @var list<KernelBrowser> */ private array $browsers = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/verified-human-approval-handoff-test-'.bin2hex(random_bytes(8));
        $this->accounts = DemoSetup::initialize($this->runtime, self::ORIGIN)['accounts'];
    }

    protected function tearDown(): void
    {
        foreach ($this->browsers as $browser) {
            $browser->getKernel()->shutdown();
        }
        (new Filesystem())->remove($this->runtime);
    }

    public function testAPhoneCodeOpensOnlyThatApprovalAndStillNeedsThePasskey(): void
    {
        $agent = $this->mcp('editor');
        $id = $agent->callTool('publish_alert', ['alert_id' => 'riverside-evacuation'])->structuredContent['requestId'];
        $other = $agent->callTool('publish_alert', ['alert_id' => 'riverside-evacuation', 'request_key' => 'second'])->structuredContent['requestId'];
        self::assertNotSame($id, $other);
        [, $authenticator] = $this->enrollOnComputer('editor');

        $code = $agent->callTool('approval_handoff', ['request_id' => $id]);
        self::assertFalse($code->isError, json_encode($code->content));
        self::assertStringStartsWith(self::ORIGIN.'/approval/handoff/', $code->structuredContent['url']);
        self::assertSame($code->structuredContent['url'], self::scan($code->structuredContent['modules']), 'The rows the card draws are a QR code for the link.');
        self::assertSame(120, $code->structuredContent['expiresIn']);
        self::assertSame('approval_handoff', $agent->callTool('approval_status', ['request_id' => $id])->structuredContent['approval']['handoffTool'], 'On a public HTTPS address the card offers the phone.');
        self::assertTrue($this->mcp('reader')->callTool('approval_handoff', ['request_id' => $id])->isError, 'Only the requester can get a code.');

        $previewer = $this->browser();
        $previewer->request('GET', $code->structuredContent['url']);
        self::assertSame(200, $previewer->getResponse()->getStatusCode(), 'A link previewer fetching the URL sees a Continue page.');
        $phone = $this->browser();
        $crawler = $phone->request('GET', $code->structuredContent['url']);
        self::assertSame(200, $phone->getResponse()->getStatusCode(), 'The previewer did not use the code up.');
        self::assertStringNotContainsString('Evacuation', (string) $phone->getResponse()->getContent(), 'The Continue page reveals nothing about the request.');
        $phone->submit($crawler->selectButton('Continue')->form());
        self::assertSame(303, $phone->getResponse()->getStatusCode());
        self::assertStringEndsWith('/approval/'.$id.'#return=handoff', (string) $phone->getResponse()->headers->get('Location'));
        $crawler = $phone->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertSame(200, $phone->getResponse()->getStatusCode());
        self::assertStringContainsString('Evacuation order', $crawler->filter('.statement')->text());

        foreach (['/', '/account/passkeys', '/admin/approval-policy', '/approval/'.$id.'/evidence', '/approval/'.$other] as $path) {
            $phone->request('GET', self::ORIGIN.$path);
            self::assertContains($phone->getResponse()->getStatusCode(), [302, 403, 404], 'The phone session reaches nothing but its one approval: '.$path);
            self::assertNotSame(200, $phone->getResponse()->getStatusCode(), $path);
        }

        $again = $this->browser();
        $again->request('GET', $code->structuredContent['url']);
        self::assertSame(410, $again->getResponse()->getStatusCode(), 'A code works once.');

        $review = $phone->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertCount(0, $review->filter('[data-cancel]'), 'The phone gets no Cancel button.');
        $csrf = (string) $review->filter('[data-passkey-panel]')->attr('data-csrf');
        $refused = $this->post($phone, '/approval/'.$id.'/cancel', $csrf, []);
        self::assertSame(403, $phone->getResponse()->getStatusCode());
        self::assertSame('sign_in_required', $refused['reason'], 'Whoever holds the code cannot call the request off.');
        $ceremony = $this->post($phone, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(200, $phone->getResponse()->getStatusCode(), (string) $phone->getResponse()->getContent());
        $done = $this->post($phone, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertSame('completed', $done['status'], 'Approved on the phone with the person’s own passkey.');
        $database = new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
        self::assertSame(1, (int) $database->alert('riverside-evacuation')['mutation_count']);
        self::assertSame('completed', $agent->callTool('approval_status', ['request_id' => $id])->structuredContent['status'], 'The card on the computer sees the outcome.');
        // The receipt stays with the signed-in person: the phone is pointed there, and cannot download it.
        $receipt = $phone->request('GET', self::ORIGIN.'/approval/'.$id)->filter('.approval-sheet .receipt');
        self::assertSame('Download your receipt from this request’s page in your signed-in browser.', $receipt->text());
        self::assertCount(0, $receipt->filter('a'));
        $phone->request('GET', self::ORIGIN.'/approval/'.$id.'/evidence');
        self::assertSame(302, $phone->getResponse()->getStatusCode());
    }

    public function testAConnectionWithoutTheReadScopeGetsNoCodeAndCodesHaveABudget(): void
    {
        $settings = json_decode((string) file_get_contents($this->runtime.'/settings.json'), true);
        $settings['approval_attempts_per_minute'] = 2;
        file_put_contents($this->runtime.'/settings.json', json_encode($settings));
        $id = $this->mcp('editor')->callTool('publish_alert', ['alert_id' => 'riverside-evacuation'])->structuredContent['requestId'];
        $limited = 'limited-'.bin2hex(random_bytes(16));
        $database = new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
        $database->pdo->prepare('INSERT INTO demo_grants (id, user_id, tenant_id, token_hash, scopes) VALUES (?, ?, ?, ?, ?)')
            ->execute(['editor-limited', 'editor', 'campaigns', hash('sha256', $limited), '["alert.read","alert.publish"]']);
        $this->accounts['limited'] = ['personal_token' => $limited];
        try {
            $this->mcp('limited')->callTool('approval_handoff', ['request_id' => $id]);
            self::fail('A connection that may not read results cannot open the review elsewhere either.');
        } catch (RequestException $error) {
            self::assertSame('The access token lacks a required scope. This operation needs the scope "approval.read".', $error->getMessage());
        }

        $agent = $this->mcp('editor');
        self::assertFalse($agent->callTool('approval_handoff', ['request_id' => $id])->isError);
        self::assertFalse($agent->callTool('approval_handoff', ['request_id' => $id])->isError);
        self::assertStringContainsString('Too many phone codes', $agent->callTool('approval_handoff', ['request_id' => $id])->content[0]->text);
    }

    public function testAPhoneSessionHasABudgetOfItsOwnAndLeavesTheSignedInPersonTheirs(): void
    {
        $settings = json_decode((string) file_get_contents($this->runtime.'/settings.json'), true);
        $settings['approval_attempts_per_minute'] = 2;
        file_put_contents($this->runtime.'/settings.json', json_encode($settings));
        $agent = $this->mcp('editor');
        $id = $agent->callTool('publish_alert', ['alert_id' => 'riverside-evacuation'])->structuredContent['requestId'];
        [$owner] = $this->enrollOnComputer('editor');

        // Whoever holds the agent's token opens a phone code itself, signed in to nothing, and spends that session's budget.
        $holder = $this->browser();
        $holder->submit($holder->request('GET', $agent->callTool('approval_handoff', ['request_id' => $id])->structuredContent['url'])->selectButton('Continue')->form());
        $csrf = (string) $holder->request('GET', self::ORIGIN.'/approval/'.$id)->filter('[data-passkey-panel]')->attr('data-csrf');
        $answers = [];
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->post($holder, '/approval/'.$id.'/options', $csrf, []);
            $answers[] = $holder->getResponse()->getStatusCode();
        }
        self::assertSame([200, 200, 429], $answers);

        // The person, in their own signed-in browser, still has theirs.
        $ownerCsrf = (string) $owner->request('GET', self::ORIGIN.'/approval/'.$id)->filter('[data-passkey-panel]')->attr('data-csrf');
        $this->post($owner, '/approval/'.$id.'/options', $ownerCsrf, []);
        self::assertSame(200, $owner->getResponse()->getStatusCode(), (string) $owner->getResponse()->getContent());
    }

    public function testACodeLastsTwoMinutesAndThePhoneSessionItOpensTen(): void
    {
        $fixture = new CoreFixture();
        $state = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1]);
        $routes = new RouteCollection();
        $routes->add('verified_human_approval_handoff', new Route('/approval/handoff/{token}'));
        $clock = new MockClock('2026-11-08T10:00:00Z');
        $handoff = new ApprovalHandoff(new ArrayAdapter(), new UrlGenerator($routes, new RequestContext()), 'https://app.example', $clock, new LockFactory(new InMemoryStore()));
        $alice = new Actor('alice', 'tenant-a');

        $code = $handoff->issue($alice, $state);
        self::assertSame(['expiresAt' => $clock->now()->getTimestamp() + 120, 'expiresIn' => 120], array_intersect_key($code, ['expiresAt' => 0, 'expiresIn' => 0]));
        $clock->sleep(120);
        self::assertTrue($handoff->peek(basename($code['url'])), 'Good for two minutes.');
        $clock->sleep(1);
        self::assertFalse($handoff->peek(basename($code['url'])));
        self::assertNull($handoff->redeem(basename($code['url']), new Session(new MockArraySessionStorage())), 'After two minutes the code opens nothing.');

        $session = new Session(new MockArraySessionStorage());
        self::assertSame($state->request->id, $handoff->redeem(basename($handoff->issue($alice, $state)['url']), $session));
        $phone = Request::create('https://app.example/approval/'.$state->request->id);
        $phone->setSession($session);
        $clock->sleep(600);
        self::assertEquals($alice, $handoff->personFor($phone, $state->request->id), 'The phone session lasts ten minutes.');
        $clock->sleep(1);
        self::assertNull($handoff->personFor($phone, $state->request->id), 'Then the phone reaches nothing.');
    }

    public function testNoCodeWithoutAPublicHttpsAddress(): void
    {
        $runtime = $this->runtime.'-local';
        $accounts = DemoSetup::initialize($runtime, 'http://localhost:8080')['accounts'];
        try {
            $factory = new Psr17Factory();
            $agent = Client::builder()->setClientInfo('handoff-test', '1.0')->setMaxRetries(0)->build();
            $agent->connect(new HttpTransport(endpoint: 'http://localhost:8080/mcp', headers: ['Authorization' => 'Bearer '.$accounts['editor']['personal_token']], httpClient: new SymfonyKernelHttpClient($runtime), requestFactory: $factory, streamFactory: $factory));
            $id = $agent->callTool('publish_alert', ['alert_id' => 'riverside-evacuation'])->structuredContent['requestId'];
            $refused = $agent->callTool('approval_handoff', ['request_id' => $id]);
            self::assertTrue($refused->isError);
            self::assertStringContainsString('public HTTPS address', $refused->content[0]->text);
            self::assertNull($agent->callTool('approval_status', ['request_id' => $id])->structuredContent['approval']['handoffTool'], 'On a local address the card does not offer the phone at all.');
        } finally {
            (new Filesystem())->remove($runtime);
        }
    }

    /** Draws the rows the way the card does, then reads them with a real QR decoder. */
    private static function scan(array $rows): string
    {
        $size = \count($rows);
        $scale = 8;
        $image = imagecreatetruecolor($size * $scale, $size * $scale);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);
        foreach ($rows as $y => $row) {
            self::assertMatchesRegularExpression('/^[01]{'.$size.'}$/D', $row);
            for ($x = 0; $x < $size; ++$x) {
                if ('1' === $row[$x]) {
                    imagefilledrectangle($image, $x * $scale, $y * $scale, ($x + 1) * $scale - 1, ($y + 1) * $scale - 1, $black);
                }
            }
        }
        // Read it the way a phone camera does, at whatever angle it is held. This reader misses about one
        // perfect code in a hundred in one orientation (its detector, or the error correction after it) and
        // reads it in another; Apple's detector reads every one. Each code is random, so reading a single
        // orientation made this test fail now and then.
        foreach ([0, 90, 180, 270] as $angle) {
            ob_start();
            imagepng(0 === $angle ? $image : imagerotate($image, $angle, 0));
            $png = (string) ob_get_clean();
            try {
                return (string) (new \chillerlan\QRCode\QRCode())->readFromBlob($png);
            } catch (\chillerlan\QRCode\QRCodeException) {
                continue;
            }
        }
        self::fail('No orientation of the drawn code could be read.');
    }

    /** @return array{KernelBrowser, VirtualAuthenticator} the person's signed-in browser, and the passkey they registered there */
    private function enrollOnComputer(string $user): array
    {
        $browser = $this->browser();
        $crawler = $browser->request('GET', self::ORIGIN.'/login');
        $browser->submit($crawler->selectButton('Sign in')->form(['_username' => $user, '_password' => $this->accounts[$user]['password']]));
        $browser->request('GET', $this->accounts[$user]['passkey_link']);
        $crawler = $browser->request('GET', self::ORIGIN.'/account/passkeys');
        $csrf = (string) $crawler->filter('[data-passkey-panel]')->attr('data-csrf');
        $options = $this->post($browser, '/account/passkeys/options', $csrf, []);
        $authenticator = new VirtualAuthenticator(self::ORIGIN);
        $result = $this->post($browser, '/account/passkeys/complete', $csrf, ['ceremony_id' => $options['ceremony_id'], 'credential' => $authenticator->registration($options['publicKey'])]);
        self::assertSame('registered', $result['status'], json_encode($result));

        return [$browser, $authenticator];
    }

    private function browser(): KernelBrowser
    {
        $browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'alerts.example', 'HTTPS' => 'on']);
        $this->browsers[] = $browser;

        return $browser;
    }

    private function post(KernelBrowser $browser, string $path, string $csrf, array $body): array
    {
        $browser->request('POST', self::ORIGIN.$path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ACCEPT' => 'application/json'], content: json_encode((object) $body, \JSON_THROW_ON_ERROR));
        $value = json_decode((string) $browser->getResponse()->getContent(), true);

        return \is_array($value) ? $value : [];
    }

    private function mcp(string $user): Client
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo('handoff-test', '1.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(endpoint: self::ORIGIN.'/mcp', headers: ['Authorization' => 'Bearer '.$this->accounts[$user]['personal_token']], httpClient: new SymfonyKernelHttpClient($this->runtime), requestFactory: $factory, streamFactory: $factory));

        return $client;
    }
}
