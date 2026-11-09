<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Omouren\McpOauthBundle\Cimd\CimdException;
use Omouren\McpOauthBundle\Cimd\ClientIdMetadataResolver;
use Omouren\McpOauthBundle\McpOauthSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VerifiedHumanApproval\Demo\Account\AgentConnections;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;
use VerifiedHumanApproval\Demo\OAuth\ClientMetadataHttpClient;
use VerifiedHumanApproval\Demo\OAuth\OAuthConnections;

/**
 * MCP authorization the way a remote client such as claude.ai does it, end to end, against the OAuth server the
 * demo uses: league/oauth2-server with omouren/mcp-oauth-bundle. These are the security checks the demo's earlier
 * hand-written server passed, kept as acceptance tests for the replacement.
 */
final class OAuthTest extends TestCase
{
    private const ORIGIN = 'http://localhost:8080';
    private const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';
    private const CLAUDE = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
    private string $runtime;
    private array $accounts;
    /** @var list<KernelBrowser> */ private array $browsers = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/verified-human-approval-oauth-test-'.bin2hex(random_bytes(8));
        $this->accounts = DemoSetup::initialize($this->runtime, self::ORIGIN)['accounts'];
    }

    protected function tearDown(): void
    {
        foreach ($this->browsers as $browser) {
            $browser->getKernel()->shutdown();
        }
        (new Filesystem())->remove($this->runtime);
    }

    public function testARemoteClientConnectsThroughOAuthAndTheRoleRuleStillApplies(): void
    {
        $api = $this->client();
        $api->request('POST', self::ORIGIN.'/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertSame(401, $api->getResponse()->getStatusCode());
        $challenge = (string) $api->getResponse()->headers->get('WWW-Authenticate');
        self::assertStringContainsString('resource_metadata="'.self::ORIGIN.'/.well-known/oauth-protected-resource/mcp"', $challenge, 'The challenge names the metadata URL for the resource (RFC 9728 §3.1).');
        self::assertStringContainsString('scope="alert.read alert.publish approval.read"', $challenge, 'The challenge names the scopes an agent needs.');

        $resource = $this->json($api, 'GET', '/.well-known/oauth-protected-resource/mcp');
        self::assertSame(self::ORIGIN.'/mcp', $resource['resource']);
        self::assertSame([self::ORIGIN], $resource['authorization_servers']);
        $server = $this->json($api, 'GET', '/.well-known/oauth-authorization-server');
        self::assertSame(self::ORIGIN, $server['issuer']);
        self::assertSame(['S256'], $server['code_challenge_methods_supported']);
        self::assertTrue($server['client_id_metadata_document_supported']);
        self::assertTrue($server['authorization_response_iss_parameter_supported']);
        self::assertSame(['authorization_code', 'refresh_token'], $server['grant_types_supported']);

        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        self::assertSame(201, $api->getResponse()->getStatusCode());
        [$code, $verifier] = $this->consent($registration['client_id'], 'editor');

        $exchange = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $registration['client_id'], 'code_verifier' => $verifier, 'resource' => self::ORIGIN.'/mcp'];
        $tokens = $this->token($api, $exchange);
        self::assertSame('Bearer', $tokens['token_type'] ?? null, json_encode($tokens));
        self::assertSame('invalid_grant', $this->token($api, $exchange)['error'] ?? null, 'An authorization code works once.');

        $result = $this->mcp($tokens['access_token'])->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertSame('pending', $result->structuredContent['status'], 'OAuth lets Claude act for county staff; it never approves the alert.');
        $delegation = (string) (new \PDO('sqlite:'.$this->runtime.'/application.sqlite'))->query("SELECT json_extract(proposal, '$.actor.delegationId') FROM verified_human_approval_requests")->fetchColumn();
        self::assertStringStartsWith('oauth-', $delegation, 'The request records the agent connection the person allowed.');

        $refresh = ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $registration['client_id'], 'resource' => self::ORIGIN.'/mcp'];
        $refreshed = $this->token($api, $refresh);
        self::assertNotSame($tokens['access_token'], $refreshed['access_token']);
        self::assertSame('invalid_grant', $this->token($api, $refresh)['error'] ?? null, 'Refresh tokens rotate.');
        self::assertSame(401, $this->ping($tokens['access_token']), 'The previous access token stops working.');
        $status = $this->mcp($refreshed['access_token'])->callTool('approval_status', ['request_id' => $result->structuredContent['requestId']]);
        self::assertFalse($status->isError, 'The refreshed token keeps the same connection, so it reads the pending request.');
        self::assertSame('pending', $status->structuredContent['status']);
    }

    public function testRefusesAWrongVerifierAnUnregisteredRedirectAndUnknownClients(): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        [$code] = $this->consent($registration['client_id'], 'editor');
        $wrong = $this->token($api, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $registration['client_id'], 'code_verifier' => str_repeat('a', 43)]);
        self::assertSame('invalid_grant', $wrong['error'] ?? null);

        $browser = $this->signedIn('editor');
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $registration['client_id'], 'redirect_uri' => 'https://attacker.example/callback', 'code_challenge' => self::challenge(str_repeat('b', 43)), 'code_challenge_method' => 'S256', 'resource' => self::ORIGIN.'/mcp']));
        self::assertGreaterThanOrEqual(400, $browser->getResponse()->getStatusCode(), 'An unregistered redirect never leaves the site.');
        self::assertStringNotContainsString('attacker.example', (string) $browser->getResponse()->headers->get('Location'));

        $unknown = $this->token($api, ['grant_type' => 'authorization_code', 'code' => 'x', 'redirect_uri' => self::CALLBACK, 'client_id' => 'client_unknown', 'code_verifier' => str_repeat('c', 43)]);
        self::assertSame('invalid_client', $unknown['error'] ?? null);
        $bad = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => ['http://attacker.example/callback'], 'client_name' => 'Claude']);
        self::assertSame('invalid_redirect_uri', $bad['error'] ?? null, 'A redirect over plain HTTP is accepted only back to this computer.');
    }

    public function testTheConsentPageSaysWhatConnectingMeansAndCannotBeFramed(): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => ['http://127.0.0.1:33418/callback'], 'client_name' => 'Claude Code']);
        $browser = $this->signedIn('editor');
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $registration['client_id'], 'redirect_uri' => 'http://127.0.0.1:33418/callback', 'code_challenge' => self::challenge(str_repeat('d', 43)), 'code_challenge_method' => 'S256', 'resource' => self::ORIGIN.'/mcp', 'scope' => 'alert.read approval.read']));
        $page = $browser->request('GET', self::absolute((string) $browser->getResponse()->headers->get('Location')));
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame("frame-ancestors 'none'", $browser->getResponse()->headers->get('Content-Security-Policy'), 'Nobody can frame the page to trick a click on Allow.');
        self::assertSame('DENY', $browser->getResponse()->headers->get('X-Frame-Options'));
        $text = $page->filter('.approval-sheet')->text();
        self::assertStringContainsString('Let Claude Code act for', $text);
        self::assertStringContainsString('It asks to read public alerts, read the outcome of your approvals.', $text, 'Consent lists what is being granted.');
        self::assertStringContainsString('Connecting does not approve anything.', $text);
        self::assertStringContainsString('returns only to an address on this computer', $text, 'A loopback client gets a warning.');
    }

    public function testTheTokenEndpointIssuesTokensOnlyForThisServersMcpEndpoint(): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        [$code, $verifier] = $this->consent($registration['client_id'], 'editor');
        $exchange = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $registration['client_id'], 'code_verifier' => $verifier];
        self::assertSame('invalid_target', $this->token($api, $exchange + ['resource' => 'https://other.example/mcp'])['error'] ?? null, 'RFC 8707: this server issues tokens only for its own MCP endpoint.');
        $tokens = $this->token($api, $exchange + ['resource' => self::ORIGIN.'/mcp']);
        self::assertSame('Bearer', $tokens['token_type'] ?? null, 'A refused resource did not use the code up.');
        self::assertSame(200, $this->ping($tokens['access_token']));
    }

    public function testOpenRegistrationHasABudgetPerAddress(): void
    {
        $settings = json_decode((string) file_get_contents($this->runtime.'/settings.json'), true, 32, \JSON_THROW_ON_ERROR);
        $settings['registration_attempts_per_minute'] = 2;
        file_put_contents($this->runtime.'/settings.json', json_encode($settings, \JSON_THROW_ON_ERROR));
        $api = $this->client();
        foreach ([201, 201, 429] as $expected) {
            $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
            self::assertSame($expected, $api->getResponse()->getStatusCode());
        }
        self::assertGreaterThan(0, (int) $api->getResponse()->headers->get('Retry-After'));
    }

    public function testRevokingAConnectionEndsItsTokensAndAReconnectionIsANewDelegation(): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        $tokens = $this->connect($api, $registration['client_id']);
        $connection = $this->connections()->active('editor', $registration['client_id']);
        self::assertNotNull($connection);
        self::assertSame('Claude', $connection['client_name'], 'The connection keeps the name the client registered, for the approval page.');

        $database = new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
        self::assertTrue($this->kernelService(AgentConnections::class)->revoke($connection['id'], 'campaigns', 'editor', ownerId: 'editor'));
        self::assertSame(401, $this->ping($tokens['access_token']), 'Its access token stops working at once.');
        self::assertSame('invalid_grant', $this->token($api, ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $registration['client_id'], 'resource' => self::ORIGIN.'/mcp'])['error'] ?? null, 'Nor can the client refresh its way back.');
        self::assertSame(0, (int) $database->grant($connection['id'])['active']);

        $again = $this->connect($api, $registration['client_id']);
        self::assertSame(200, $this->ping($again['access_token']));
        self::assertNotSame($connection['id'], $this->connections()->active('editor', $registration['client_id'])['id'] ?? null, 'Connecting again is a new delegation: requests made under the old one stay refused.');
    }

    public function testAConnectionEndsAfterItsLifetimeHoweverOftenItRefreshes(): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        $tokens = $this->connect($api, $registration['client_id']);
        $database = new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
        $database->pdo->prepare('UPDATE demo_grants SET created_at = ? WHERE client_id = ?')->execute([time() - OAuthConnections::LIFETIME_SECONDS - 60, $registration['client_id']]);
        self::assertSame(401, $this->ping($tokens['access_token']));
        self::assertSame('invalid_grant', $this->token($api, ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $registration['client_id'], 'resource' => self::ORIGIN.'/mcp'])['error'] ?? null);
    }

    public function testAClientIdMetadataDocumentMustNameItselfAndCarryAName(): void
    {
        $document = ['client_id' => self::CLAUDE, 'client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]];
        self::assertSame('Claude', $this->resolver(new MockResponse(json_encode($document)))->resolve(self::CLAUDE)->clientName);
        foreach ([
            'another client ID' => ['client_id' => 'https://other.example/client', 'client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]],
            'no client_name' => ['client_id' => self::CLAUDE, 'redirect_uris' => [self::CALLBACK]],
        ] as $why => $impostor) {
            try {
                $this->resolver(new MockResponse(json_encode($impostor)))->resolve(self::CLAUDE);
                self::fail('A document with '.$why.' was accepted.');
            } catch (CimdException) {
            }
        }
    }

    public function testAClientIdMetadataDocumentIsFetchedOnceAndThenCached(): void
    {
        $once = new MockHttpClient([new MockResponse(json_encode(['client_id' => self::CLAUDE, 'client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]]))]);
        $resolver = new ClientIdMetadataResolver(new ClientMetadataHttpClient($once), new ArrayAdapter(), self::settings());
        $resolver->resolve(self::CLAUDE);
        $resolver->resolve(self::CLAUDE);
        self::assertSame(1, $once->getRequestsCount());
    }

    public function testAnOversizedMetadataDocumentIsCutOffWhileItDownloads(): void
    {
        $sent = 0;
        // An 8 MiB answer, produced only as fast as the client reads it.
        $body = (static function () use (&$sent): \Generator {
            for ($chunk = 0; $chunk < 128; ++$chunk) {
                $sent += 65536;
                yield str_repeat(' ', 65536);
            }
        })();
        foreach ([new MockResponse($body), new MockResponse('{}', ['response_headers' => ['Content-Length' => '8388608']])] as $answer) {
            try {
                $this->resolver($answer)->resolve(self::CLAUDE);
                self::fail('An oversized document was read.');
            } catch (CimdException) {
            }
        }
        self::assertLessThanOrEqual(2 * ClientMetadataHttpClient::MAX_BYTES, $sent, 'The download stopped at the size limit, not after the whole answer; a declared size over it is refused too.');
    }

    public function testClientMetadataIsNeverFetchedFromThisServersOwnNetwork(): void
    {
        self::assertInstanceOf(NoPrivateNetworkHttpClient::class, (new \ReflectionProperty(ClientMetadataHttpClient::class, 'client'))->getValue(new ClientMetadataHttpClient()), 'The connection itself is checked, not only the name.');
        $mock = new MockHttpClient(new MockResponse('{}'));
        $resolver = new ClientIdMetadataResolver(new ClientMetadataHttpClient(new NoPrivateNetworkHttpClient($mock)), new ArrayAdapter(), self::settings());
        // Anyone can name a client ID; none of these may make this server fetch from its own network.
        foreach (['https://127.0.0.1/client.json', 'https://[::1]/client.json', 'https://10.0.0.8/client.json', 'https://169.254.169.254/latest/meta-data/client', 'https://evil.example/client'] as $url) {
            try {
                $resolver->resolve($url);
                self::fail('Fetched '.$url);
            } catch (CimdException) {
            }
        }
        self::assertSame(0, $mock->getRequestsCount(), 'Refused before any request: only listed client domains are fetched.');
    }

    public function testTheUnusedDeviceFlowIssuesNothing(): void
    {
        $api = $this->client();
        $api->request('POST', self::ORIGIN.'/oauth/device-code', ['client_id' => 'anything']);
        self::assertGreaterThanOrEqual(400, $api->getResponse()->getStatusCode());
        self::assertStringNotContainsString('device_code', (string) $api->getResponse()->getContent());
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function connect(KernelBrowser $api, string $clientId): array
    {
        [$code, $verifier] = $this->consent($clientId, 'editor');

        return $this->token($api, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $clientId, 'code_verifier' => $verifier, 'resource' => self::ORIGIN.'/mcp']);
    }

    /** @return array{0: string, 1: string} the authorization code and its PKCE verifier */
    private function consent(string $clientId, string $user): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $browser = $this->signedIn($user);
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::CALLBACK, 'state' => 'state-123',
            'code_challenge' => self::challenge($verifier), 'code_challenge_method' => 'S256', 'resource' => self::ORIGIN.'/mcp',
        ]));
        self::assertSame(302, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $consent = $browser->request('GET', self::absolute((string) $browser->getResponse()->headers->get('Location')));
        self::assertSame(200, $browser->getResponse()->getStatusCode(), 'The person sees the consent page first.');
        self::assertStringContainsString('approve each exact alert with your own passkey', (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('read public alerts, ask to issue public alerts as you, read the outcome of your approvals', (string) $browser->getResponse()->getContent());
        $browser->submit($consent->selectButton('Allow')->form());
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $browser->request('GET', self::absolute((string) $browser->getResponse()->headers->get('Location')));
        self::assertSame(302, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::CALLBACK.'?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('state-123', $query['state']);
        self::assertSame(self::ORIGIN, $query['iss'] ?? null, 'RFC 9207: the response names its issuer.');

        return [$query['code'], $verifier];
    }

    private function resolver(MockResponse $answer): ClientIdMetadataResolver
    {
        return new ClientIdMetadataResolver(new ClientMetadataHttpClient(new MockHttpClient($answer)), new ArrayAdapter(), self::settings());
    }

    private static function settings(): McpOauthSettings
    {
        return new McpOauthSettings(['/mcp'], '/oauth', ['alert.read', 'alert.publish', 'approval.read'], 'demo_login', '_target_path', 'page', true, ['claude.ai', 'vscode.dev', 'cursor.com'], true, true, true, self::ORIGIN);
    }

    private function ping(string $token): int
    {
        $api = $this->client();
        $api->request('POST', self::ORIGIN.'/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"ping","version":"1"}}}');

        return $api->getResponse()->getStatusCode();
    }

    private function connections(): OAuthConnections
    {
        return new OAuthConnections(new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite')));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function kernelService(string $id): object
    {
        $kernel = new DemoKernel($this->runtime, 'test');
        $kernel->boot();
        $this->browsers[] = new KernelBrowser($kernel);

        return $kernel->getContainer()->get('test.service_container')->get($id);
    }

    private static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function absolute(string $location): string
    {
        return str_starts_with($location, '/') ? self::ORIGIN.$location : $location;
    }

    private function signedIn(string $user): KernelBrowser
    {
        $browser = $this->client();
        $crawler = $browser->request('GET', self::ORIGIN.'/login');
        $browser->submit($crawler->selectButton('Sign in')->form(['_username' => $user, '_password' => $this->accounts[$user]['password']]));
        self::assertSame(302, $browser->getResponse()->getStatusCode());

        return $browser;
    }

    private function client(): KernelBrowser
    {
        $browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'), ['HTTP_HOST' => 'localhost:8080']);
        $browser->followRedirects(false);
        $this->browsers[] = $browser;

        return $browser;
    }

    private function json(KernelBrowser $browser, string $method, string $path, ?array $body = null): array
    {
        $browser->request($method, self::ORIGIN.$path, server: ['CONTENT_TYPE' => 'application/json'], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $browser->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);
    }

    private function token(KernelBrowser $browser, array $form): array
    {
        $browser->request('POST', self::ORIGIN.'/oauth/token', $form);

        return json_decode((string) $browser->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);
    }

    private function mcp(string $token): Client
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo('oauth-test', '1.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(endpoint: self::ORIGIN.'/mcp', headers: ['Authorization' => 'Bearer '.$token], httpClient: new SymfonyKernelHttpClient($this->runtime), requestFactory: $factory, streamFactory: $factory));

        return $client;
    }
}
