<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;
use VerifiedHumanApproval\Testing\KernelHttpClient;
use VerifiedHumanApproval\Testing\PasskeyPanel;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;

/** The initiating personal grant must remain live when a separately authenticated browser completes approval. */
final class DelegationExpiryTest extends TestCase
{
    private const ORIGIN = 'https://alerts.example';
    private string $runtime;
    private array $accounts;
    private DemoDatabase $database;
    private ?Client $agent = null;
    private ?KernelBrowser $browser = null;

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/verified-human-approval-delegation-expiry-'.bin2hex(random_bytes(8));
        $this->accounts = DemoSetup::initialize($this->runtime, self::ORIGIN)['accounts'];
        $this->database = new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
    }

    protected function tearDown(): void
    {
        $this->agent?->disconnect();
        $this->browser?->getKernel()->shutdown();
        (new Filesystem())->remove($this->runtime);
        Request::setTrustedHosts([]);
    }

    /** @return iterable<string, array{int}> */
    public static function expired(): iterable
    {
        yield 'already expired' => [-1];
        yield 'at expiry' => [0];
    }

    #[DataProvider('expired')]
    public function testAPersonalGrantThatExpiresDuringApprovalCannotPublish(int $offset): void
    {
        $this->setExpiry(time() + 3600);
        $browser = $this->browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'alerts.example', 'HTTPS' => 'on']);
        $page = $browser->request('GET', self::ORIGIN.'/login');
        $browser->submit($page->selectButton('Sign in')->form(['_username' => 'editor', '_password' => $this->accounts['editor']['password']]));
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $browser->request('GET', $this->accounts['editor']['passkey_link']);
        $passkey = new VirtualAuthenticator(self::ORIGIN);
        $registered = PasskeyPanel::open($browser, self::ORIGIN.'/account/passkeys')->register($passkey);
        self::assertSame('registered', $registered->status, (string) $registered);

        $factory = new Psr17Factory();
        $agent = $this->agent = Client::builder()->setClientInfo('delegation-expiry-test', '1.0')->setMaxRetries(0)->build();
        $agent->connect(new HttpTransport(
            endpoint: self::ORIGIN.'/mcp', headers: ['Authorization' => 'Bearer '.$this->accounts['editor']['personal_token']],
            httpClient: new KernelHttpClient(fn () => new DemoKernel($this->runtime, 'test'), $factory, $factory),
            requestFactory: $factory, streamFactory: $factory,
        ));
        $pending = $agent->callTool('publish_alert', ['alert_id' => 'riverside-evacuation', 'request_key' => 'expires-while-reviewing']);
        self::assertFalse($pending->isError);
        self::assertSame('pending', $pending->structuredContent['status']);
        self::assertSame(0, $this->mutations());
        $panel = PasskeyPanel::open($browser, $pending->structuredContent['review_url']);
        $ceremony = $panel->begin();
        self::assertFalse($ceremony->refused(), (string) $ceremony);

        // No clock sleeps: expire the same grant after the application issued the bound challenge.
        $this->setExpiry(time() + $offset);
        $answer = $panel->complete($ceremony->data['ceremony_id'], $passkey->assertion($ceremony->data['publicKey']));
        self::assertSame('denied', $answer->status, (string) $answer);
        self::assertSame('delegation_denied', $answer->reason);
        self::assertSame(0, $this->mutations());
        self::assertNull($this->database->alert('riverside-evacuation')['published_title']);
    }

    #[DataProvider('expired')]
    public function testAnExpiredPersonalCredentialCannotReachMcp(int $offset): void
    {
        $this->setExpiry(time() + $offset);
        $factory = new Psr17Factory();
        $http = new KernelHttpClient(fn () => new DemoKernel($this->runtime, 'test'), $factory, $factory);
        $answer = $http->sendRequest($factory->createRequest('POST', self::ORIGIN.'/mcp')
            ->withHeader('Authorization', 'Bearer '.$this->accounts['editor']['personal_token'])
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"expiry-test","version":"1"}}}')));

        self::assertSame(401, $answer->getStatusCode());
        self::assertSame(0, $this->mutations());
    }

    private function setExpiry(int $expiresAt): void
    {
        $this->database->pdo->prepare('UPDATE demo_grants SET expires_at = ? WHERE id = ?')->execute([$expiresAt, 'editor-personal']);
    }

    private function mutations(): int
    {
        return (int) $this->database->alert('riverside-evacuation')['mutation_count'];
    }
}
