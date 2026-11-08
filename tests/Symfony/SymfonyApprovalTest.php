<?php

declare(strict_types=1);

namespace ExactApproval\Tests\Symfony;

use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ChallengeBinding;
use ExactApproval\Core\ExecutionGuard;
use ExactApproval\Demo\Action\AnnouncementPublisher;
use ExactApproval\Demo\Action\PublishAnnouncement;
use ExactApproval\Demo\DemoDatabase;
use ExactApproval\Demo\DemoKernel;
use ExactApproval\Demo\DemoSetup;
use ExactApproval\Tests\Support\VirtualAuthenticator;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

/** Actual Symfony kernel, firewall, forms, SDK HTTP transport and WebAuthn cryptography. */
final class SymfonyApprovalTest extends TestCase
{
    private const ORIGIN = 'http://localhost:8080';
    private string $runtime;
    private array $accounts;
    private DemoDatabase $database;
    /** @var list<Client> */ private array $clients = [];
    /** @var list<KernelBrowser> */ private array $browsers = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/exact-approval-symfony-test-'.bin2hex(random_bytes(8));
        $created = DemoSetup::initialize($this->runtime, self::ORIGIN);
        $this->accounts = $created['accounts'];
        $this->database = new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) $client->disconnect();
        foreach ($this->browsers as $browser) $browser->getKernel()->shutdown();
        (new Filesystem())->remove($this->runtime);
    }

    public function testMcpRequestBrowserPasskeyAndStoredStatusCompleteTheSameActionOnce(): void
    {
        $client = $this->mcp();
        $tools = [];
        foreach ($client->listTools()->tools as $tool) $tools[$tool->name] = $tool;
        self::assertEqualsCanonicalizing(['approval_status', 'publish_announcement', 'read_announcement'], array_keys($tools));
        self::assertArrayNotHasKey('actor', $tools['publish_announcement']->inputSchema['properties']);
        self::assertArrayNotHasKey('user_id', $tools['publish_announcement']->inputSchema['properties']);

        $pending = $client->callTool('publish_announcement', ['announcement_id' => 'summer-campaign', 'request_key' => 'same-request']);
        self::assertFalse($pending->isError);
        self::assertSame('pending', $pending->structuredContent['status']);
        self::assertStringStartsWith(self::ORIGIN.'/approval/', $pending->structuredContent['review_url']);
        self::assertSame(0, $this->mutationCount());
        $id = $pending->structuredContent['requestId'];

        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        [$csrf, $ceremony] = $this->begin($browser, $id);
        self::assertSame('required', $ceremony['publicKey']['userVerification']);
        $completed = $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        self::assertSame('completed', $completed['status']);
        self::assertSame('published', $completed['result']['disposition']);
        self::assertSame(1, $this->mutationCount());
        $row = $this->database->announcement('summer-campaign');
        self::assertSame($row['title'], $row['published_title']);
        self::assertSame($row['body'], $row['published_body']);

        $status = $client->callTool('approval_status', ['request_id' => $id]);
        self::assertSame('completed', $status->structuredContent['status']);
        $retry = $client->callTool('publish_announcement', ['announcement_id' => 'summer-campaign', 'request_key' => 'same-request']);
        self::assertSame($id, $retry->structuredContent['requestId']);
        self::assertSame('completed', $retry->structuredContent['status']);
        self::assertSame(1, $this->mutationCount());

        $browser->request('GET', self::ORIGIN.'/approval/'.$id.'/evidence');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $evidence = json_decode((string) $browser->getResponse()->getContent(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('exact-approval/evidence/v1', $evidence['format']);
        self::assertStringContainsString('I approve publishing', $evidence['proposal']);
        self::assertNotEmpty($evidence['receipt']['signature']);
        self::assertSame(1, $this->mutationCount());
    }

    public function testOneCharacterDraftChangeAfterReviewInvalidatesTheRealWebAuthnApproval(): void
    {
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        [$csrf, $ceremony] = $this->begin($browser, $id);
        $this->database->pdo->exec("UPDATE demo_announcements SET body = body || '!', revision = revision + 1 WHERE id = 'summer-campaign'");
        $result = $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertSame('invalidated', $result['status']);
        self::assertSame('stale_resource', $result['reason']);
        self::assertSame(0, $this->mutationCount());
    }

    public function testCurrentDelegationRevocationPreventsExecutionAfterReview(): void
    {
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        [$csrf, $ceremony] = $this->begin($browser, $id);
        $this->database->pdo->exec("UPDATE demo_grants SET active = 0 WHERE id = 'editor-personal'");
        $result = $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertContains($result['status'], ['denied', 'invalidated']);
        self::assertSame('delegation_denied', $result['reason']);
        self::assertSame(0, $this->mutationCount());
    }

    public function testAReadOnlyPersonsMcpCredentialCannotRequestPublication(): void
    {
        $client = $this->mcp('reader');
        self::assertFalse($client->callTool('read_announcement', ['announcement_id' => 'summer-campaign'])->isError);
        $response = $client->callTool('publish_announcement', ['announcement_id' => 'summer-campaign']);
        self::assertTrue($response->isError);
        self::assertSame('permission_denied', $response->structuredContent['reason']);
        self::assertSame(0, (int) $this->database->pdo->query('SELECT COUNT(*) FROM exact_approval_requests')->fetchColumn());
        self::assertSame(0, $this->mutationCount());
    }

    public function testApprovalUrlDoesNotGrantOtherAccountsAccessToFrozenContent(): void
    {
        $id = $this->requestAction();
        foreach (['reader', 'other'] as $user) {
            $browser = $this->browser($user);
            $browser->request('GET', self::ORIGIN.'/approval/'.$id);
            self::assertSame(404, $browser->getResponse()->getStatusCode());
            self::assertStringNotContainsString('Applications close on June 20', (string) $browser->getResponse()->getContent());
        }
        self::assertSame(0, $this->mutationCount());
    }

    public function testAgentTokenCannotEnrollPasskeysOrChangePolicyOrReplaceBrowserApproval(): void
    {
        $id = $this->requestAction();
        $browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $browser;
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->accounts['editor']['personal_token'], 'CONTENT_TYPE' => 'application/json'];
        foreach (['/account/passkeys/options', '/account/passkeys/complete', '/admin/approval-policy', '/approval/'.$id.'/complete'] as $path) {
            $browser->request('POST', self::ORIGIN.$path, server: $headers, content: '{}');
            self::assertSame(302, $browser->getResponse()->getStatusCode(), $path);
            self::assertStringEndsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
        }
        self::assertSame(0, (int) $this->database->pdo->query('SELECT COUNT(*) FROM exact_approval_credentials')->fetchColumn());
        self::assertSame(1, (int) $this->database->policy('announcement.publish')['version']);
        self::assertSame(0, $this->mutationCount());
    }

    public function testCsrfAndReplacementArgumentsAreRejectedBeforeCompletion(): void
    {
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        $this->post($browser, '/approval/'.$id.'/options', '', []);
        self::assertSame(403, $browser->getResponse()->getStatusCode());
        [$csrf, $ceremony] = $this->begin($browser, $id);
        $this->post($browser, '/approval/'.$id.'/complete', $csrf, [
            'ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey']),
            'replacement_content' => 'Please publish this instead',
        ]);
        self::assertSame(400, $browser->getResponse()->getStatusCode());
        self::assertSame(0, $this->mutationCount());
    }

    public function testBrowserPublicationUsesSameGuardAndPolicyFormsCannotBeChangedByReadOnlyUsers(): void
    {
        $browser = $this->browser('editor');
        $crawler = $browser->request('GET', self::ORIGIN.'/');
        $browser->submit($crawler->selectButton('Request publication')->form());
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('/approval/', (string) $browser->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->mutationCount());
        $browser->followRedirect();
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Approve and execute', (string) $browser->getResponse()->getContent());

        $reader = $this->browser('reader');
        $reader->request('GET', self::ORIGIN.'/admin/approval-policy');
        self::assertSame(403, $reader->getResponse()->getStatusCode());
    }

    public function testDirectPublicationServiceCannotBypassTheApplicationGuard(): void
    {
        $publisher = new AnnouncementPublisher($this->database, new ExecutionGuard());
        $adapter = new PublishAnnouncement($this->database, $publisher);
        $actor = new Actor('editor', 'campaigns');
        try {
            $publisher->publish($actor, $adapter->prepare($actor, ['announcement_id' => 'summer-campaign']));
            self::fail('Direct publication should require the approval execution context.');
        } catch (ApprovalException $error) {
            self::assertSame('approval_required', $error->reason);
        }
        self::assertSame(0, $this->mutationCount());
    }

    public function testCurrentReadScopeAppliesToStatusAndIdempotentResultRetrieval(): void
    {
        $client = $this->mcp();
        $request = $client->callTool('publish_announcement', ['announcement_id' => 'summer-campaign', 'request_key' => 'scope-test']);
        self::assertFalse($request->isError);
        $id = $request->structuredContent['requestId'];
        $this->database->pdo->prepare("UPDATE demo_grants SET scopes = ? WHERE id = 'editor-personal'")
            ->execute([json_encode(['announcement.read', 'announcement.publish'], JSON_THROW_ON_ERROR)]);
        $status = $client->callTool('approval_status', ['request_id' => $id]);
        self::assertTrue($status->isError);
        self::assertNull($status->structuredContent);
        $retry = $client->callTool('publish_announcement', ['announcement_id' => 'summer-campaign', 'request_key' => 'scope-test']);
        self::assertTrue($retry->isError);
        self::assertSame('result_read_denied', $retry->structuredContent['reason']);
        self::assertArrayNotHasKey('result', $retry->structuredContent);
        self::assertSame(0, $this->mutationCount());
    }

    public function testAdministratorFormValidatesStatementsAndVersionChangesInvalidatePendingRequests(): void
    {
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $crawler = $browser->request('GET', self::ORIGIN.'/admin/approval-policy');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $form = $crawler->selectButton('Save policy')->form();
        $form['approval_policy[statement]'] = 'I approve {{unknown}}';
        $browser->submit($form);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame(1, (int) $this->database->policy('announcement.publish')['version']);
        $crawler = $browser->request('GET', self::ORIGIN.'/admin/approval-policy');
        $form = $crawler->selectButton('Save policy')->form();
        $form['approval_policy[statement]'] = 'I approve exactly “{{title}}” revision {{revision}}.';
        $browser->submit($form);
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame(2, (int) $this->database->policy('announcement.publish')['version']);
        $crawler = $browser->request('GET', self::ORIGIN.'/approval/'.$id);
        $csrf = (string) $crawler->filter('[data-passkey-panel]')->attr('data-csrf');
        $rejected = $this->post($browser, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(409, $browser->getResponse()->getStatusCode());
        self::assertSame('policy_changed', $rejected['reason']);
        self::assertSame(0, $this->mutationCount());

        $crawler = $browser->request('GET', self::ORIGIN.'/admin/approval-policy');
        $form = $crawler->selectButton('Save policy')->form();
        $form['approval_policy[required]']->untick();
        $browser->submit($form);
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $newRequest = $this->mcp()->callTool('publish_announcement', ['announcement_id' => 'summer-campaign']);
        self::assertSame('completed', $newRequest->structuredContent['status']);
        self::assertSame(1, $this->mutationCount());
        self::assertSame(1, (int) $this->database->pdo->query("SELECT COUNT(*) FROM exact_approval_requests WHERE status='invalidated'")->fetchColumn());
    }

    public function testBinaryReceiptKeysCannotBeInterpretedAsContainerParameters(): void
    {
        $settings = json_decode(file_get_contents($this->runtime.'/settings.json'), true, 32, JSON_THROW_ON_ERROR);
        $keypair = sodium_crypto_sign_seed_keypair(str_pad('%missing_parameter%', 32, "\0"));
        $secret = sodium_crypto_sign_secretkey($keypair);
        self::assertStringContainsString('%missing_parameter%', $secret);
        $settings['receipt_secret_key'] = ChallengeBinding::encode($secret);
        $settings['receipt_public_key'] = ChallengeBinding::encode(sodium_crypto_sign_publickey($keypair));
        file_put_contents($this->runtime.'/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
        self::assertCount(3, $this->mcp()->listTools()->tools);
    }

    public function testBrowserApprovalAttemptsAreBoundedAcrossRequestsWithoutExecuting(): void
    {
        $settings = json_decode(file_get_contents($this->runtime.'/settings.json'), true, 32, JSON_THROW_ON_ERROR);
        $settings['approval_attempts_per_minute'] = 2;
        file_put_contents($this->runtime.'/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
        $id = $this->requestAction();
        $browser = $this->browser('editor');
        $authenticator = $this->enroll($browser);
        [$csrf] = $this->begin($browser, $id);
        $second = $this->post($browser, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $limited = $this->post($browser, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(429, $browser->getResponse()->getStatusCode());
        self::assertSame('rate_limited', $limited['status']);
        self::assertGreaterThan(0, (int) $browser->getResponse()->headers->get('Retry-After'));
        $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $second['ceremony_id'], 'credential' => $authenticator->assertion($second['publicKey'])]);
        self::assertSame(429, $browser->getResponse()->getStatusCode());
        self::assertSame(0, $this->mutationCount());
        self::assertSame(2, (int) $this->database->pdo->query('SELECT COUNT(*) FROM exact_approval_ceremonies')->fetchColumn());
    }

    public function testEnrollmentAttemptsAreBoundedWithoutRegisteringAnything(): void
    {
        $settings = json_decode(file_get_contents($this->runtime.'/settings.json'), true, 32, JSON_THROW_ON_ERROR);
        $settings['enrollment_attempts_per_minute'] = 2;
        file_put_contents($this->runtime.'/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
        $browser = $this->browser('editor');
        $crawler = $browser->request('GET', self::ORIGIN.'/account/passkeys');
        $csrf = (string) $crawler->filter('[data-passkey-panel]')->attr('data-csrf');
        $this->post($browser, '/account/passkeys/options', $csrf, []);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $this->post($browser, '/account/passkeys/options', $csrf, []);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $limited = $this->post($browser, '/account/passkeys/options', $csrf, []);
        self::assertSame(429, $browser->getResponse()->getStatusCode());
        self::assertSame('rate_limited', $limited['status']);
        self::assertSame(0, (int) $this->database->pdo->query('SELECT COUNT(*) FROM exact_approval_credentials')->fetchColumn());
    }

    public function testFailedLoginAttemptsAreThrottledBeforeAValidPasswordCanBeUsed(): void
    {
        $browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $browser;
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $crawler = $browser->request('GET', self::ORIGIN.'/login');
            $browser->submit($crawler->selectButton('Sign in')->form(['_username' => 'editor', '_password' => 'incorrect-password']));
            self::assertSame(302, $browser->getResponse()->getStatusCode());
        }
        $crawler = $browser->request('GET', self::ORIGIN.'/login');
        $browser->submit($crawler->selectButton('Sign in')->form(['_username' => 'editor', '_password' => $this->accounts['editor']['password']]));
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertStringEndsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->mutationCount());
    }

    private function requestAction(): string
    {
        $result = $this->mcp()->callTool('publish_announcement', ['announcement_id' => 'summer-campaign']);
        self::assertFalse($result->isError, json_encode($result->structuredContent));

        return $result->structuredContent['requestId'];
    }

    private function browser(string $user): KernelBrowser
    {
        $browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
        $this->browsers[] = $browser;
        $crawler = $browser->request('GET', self::ORIGIN.'/login');
        $browser->submit($crawler->selectButton('Sign in')->form(['_username' => $user, '_password' => $this->accounts[$user]['password']]));
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $browser->followRedirect();
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());

        return $browser;
    }

    private function enroll(KernelBrowser $browser): VirtualAuthenticator
    {
        $crawler = $browser->request('GET', self::ORIGIN.'/account/passkeys');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $csrf = (string) $crawler->filter('[data-passkey-panel]')->attr('data-csrf');
        $options = $this->post($browser, '/account/passkeys/options', $csrf, []);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $authenticator = new VirtualAuthenticator(self::ORIGIN);
        $result = $this->post($browser, '/account/passkeys/complete', $csrf, ['ceremony_id' => $options['ceremony_id'], 'credential' => $authenticator->registration($options['publicKey'])]);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        self::assertSame('registered', $result['status']);

        return $authenticator;
    }

    private function begin(KernelBrowser $browser, string $id): array
    {
        $crawler = $browser->request('GET', self::ORIGIN.'/approval/'.$id);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $browser->getResponse()->headers->get('Content-Security-Policy'));
        $csrf = (string) $crawler->filter('[data-passkey-panel]')->attr('data-csrf');
        $ceremony = $this->post($browser, '/approval/'.$id.'/options', $csrf, []);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());

        return [$csrf, $ceremony];
    }

    private function post(KernelBrowser $browser, string $path, string $csrf, array $body): array
    {
        $browser->request('POST', self::ORIGIN.$path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ACCEPT' => 'application/json'], content: json_encode((object) $body, JSON_THROW_ON_ERROR));
        $value = json_decode((string) $browser->getResponse()->getContent(), true);

        return \is_array($value) ? $value : [];
    }

    private function mcp(string $user = 'editor'): Client
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo('exact-approval-test', '1.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(
            endpoint: self::ORIGIN.'/mcp',
            headers: ['Authorization' => 'Bearer '.$this->accounts[$user]['personal_token']],
            httpClient: new SymfonyKernelHttpClient($this->runtime), requestFactory: $factory, streamFactory: $factory,
        ));
        $this->clients[] = $client;

        return $client;
    }

    private function mutationCount(): int { return (int) $this->database->announcement('summer-campaign')['mutation_count']; }
}

/** Test adapter transports real HTTP messages through a fresh Symfony kernel per request. */
final readonly class SymfonyKernelHttpClient implements ClientInterface
{
    public function __construct(private string $runtime) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $kernel = new DemoKernel($this->runtime, 'test');
        $http = Request::create((string) $request->getUri(), $request->getMethod(), content: (string) $request->getBody());
        $http->headers->replace($request->getHeaders());
        $response = $kernel->handle($http);
        ob_start();
        try {
            $response->sendContent();
            $body = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $kernel->terminate($http, $response);
        $kernel->shutdown();

        return new PsrResponse($response->getStatusCode(), $response->headers->all(), $body);
    }
}
