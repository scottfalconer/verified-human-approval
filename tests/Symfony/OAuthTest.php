<?php

declare(strict_types=1);

namespace ExactApproval\Tests\Symfony;

use ExactApproval\Demo\DemoDatabase;
use ExactApproval\Demo\DemoKernel;
use ExactApproval\Demo\OAuth\ClientRegistry;
use ExactApproval\Demo\DemoSetup;
use ExactApproval\Demo\OAuth\OAuthServer;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** MCP authorization the way a remote client such as claude.ai does it, end to end. */
final class OAuthTest extends TestCase
{
    private const ORIGIN = 'http://localhost:8080';
    private const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';
    private string $runtime;
    private array $accounts;
    /** @var list<KernelBrowser> */ private array $browsers = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/exact-approval-oauth-test-'.bin2hex(random_bytes(8));
        $this->accounts = DemoSetup::initialize($this->runtime, self::ORIGIN)['accounts'];
    }

    protected function tearDown(): void
    {
        foreach ($this->browsers as $browser) $browser->getKernel()->shutdown();
        (new Filesystem())->remove($this->runtime);
    }

    public function testARemoteClientConnectsThroughOAuthAndTheRoleRuleStillApplies(): void
    {
        $api = $this->client();
        $api->request('POST', self::ORIGIN.'/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertSame(401, $api->getResponse()->getStatusCode());
        self::assertStringContainsString('resource_metadata="'.self::ORIGIN.'/.well-known/oauth-protected-resource"', (string) $api->getResponse()->headers->get('WWW-Authenticate'));

        $resource = $this->json($api, 'GET', '/.well-known/oauth-protected-resource');
        self::assertSame(self::ORIGIN.'/mcp', $resource['resource']);
        self::assertSame([self::ORIGIN], $resource['authorization_servers']);
        $server = $this->json($api, 'GET', '/.well-known/oauth-authorization-server');
        self::assertSame(['S256'], $server['code_challenge_methods_supported']);
        self::assertTrue($server['client_id_metadata_document_supported']);

        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        self::assertSame(201, $api->getResponse()->getStatusCode());
        [$code, $verifier] = $this->consent($registration['client_id'], 'editor');

        $tokens = $this->token($api, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $registration['client_id'], 'code_verifier' => $verifier]);
        self::assertSame('Bearer', $tokens['token_type']);
        $reused = $this->token($api, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $registration['client_id'], 'code_verifier' => $verifier]);
        self::assertSame('invalid_grant', $reused['error'], 'An authorization code works once.');

        $result = $this->mcp($tokens['access_token'])->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertSame('pending', $result->structuredContent['status'], 'OAuth lets Claude act for county staff; it never approves the alert.');
        self::assertStringStartsWith('oauth-', (string) (new \PDO('sqlite:'.$this->runtime.'/application.sqlite'))->query("SELECT json_extract(proposal, '$.actor.delegationId') FROM exact_approval_requests")->fetchColumn());

        $refreshed = $this->token($api, ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $registration['client_id']]);
        self::assertNotSame($tokens['access_token'], $refreshed['access_token']);
        $again = $this->token($api, ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $registration['client_id']]);
        self::assertSame('invalid_grant', $again['error'], 'Refresh tokens rotate.');
        $api->request('POST', self::ORIGIN.'/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']], content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertSame(401, $api->getResponse()->getStatusCode(), 'The previous access token stops working.');
        $status = $this->mcp($refreshed['access_token'])->callTool('approval_status', ['request_id' => $result->structuredContent['requestId']]);
        self::assertFalse($status->isError, 'The refreshed token keeps the same delegation, so it can read the pending request.');
        self::assertSame('pending', $status->structuredContent['status']);
    }

    public function testRefusesAWrongVerifierAnUnregisteredRedirectAndUnknownClients(): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        [$code] = $this->consent($registration['client_id'], 'editor');
        $wrong = $this->token($api, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $registration['client_id'], 'code_verifier' => str_repeat('a', 43)]);
        self::assertSame('invalid_grant', $wrong['error']);

        $browser = $this->signedIn('editor');
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $registration['client_id'], 'redirect_uri' => 'https://attacker.example/callback', 'code_challenge' => OAuthServer::challenge(str_repeat('b', 43)), 'code_challenge_method' => 'S256']));
        self::assertSame(400, $browser->getResponse()->getStatusCode(), 'An unregistered redirect never leaves the site.');
        self::assertSame("frame-ancestors 'none'", $browser->getResponse()->headers->get('Content-Security-Policy'));

        $unknown = $this->token($api, ['grant_type' => 'authorization_code', 'code' => 'x', 'redirect_uri' => self::CALLBACK, 'client_id' => 'client_unknown', 'code_verifier' => str_repeat('c', 43)]);
        self::assertSame('invalid_client', $unknown['error']);
        $bad = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => ['http://attacker.example/callback']]);
        self::assertSame('invalid_redirect_uri', $bad['error']);
    }

    public function testAClientIdMetadataDocumentMustNameItself(): void
    {
        $database = new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
        $url = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
        $document = ['client_id' => $url, 'client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]];
        $client = (new ClientRegistry($database, new MockHttpClient(new MockResponse(json_encode($document)))))->find($url);
        self::assertSame(['client_id' => $url, 'client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]], $client);
        $impostor = ['client_id' => 'https://other.example/client', 'redirect_uris' => [self::CALLBACK]];
        self::assertNull((new ClientRegistry($database, new MockHttpClient(new MockResponse(json_encode($impostor)))))->find($url));
        self::assertNull((new ClientRegistry($database, new MockHttpClient(new MockResponse('', ['http_code' => 302]))))->find($url));
    }

    /** @return array{0: string, 1: string} the authorization code and its PKCE verifier */
    private function consent(string $clientId, string $user): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $browser = $this->signedIn($user);
        $crawler = $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::CALLBACK, 'state' => 'state-123',
            'code_challenge' => OAuthServer::challenge($verifier), 'code_challenge_method' => 'S256', 'resource' => self::ORIGIN.'/mcp',
        ]));
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('approve each exact alert with your own passkey', (string) $browser->getResponse()->getContent());
        $browser->submit($crawler->selectButton('Allow')->form());
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::CALLBACK.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('state-123', $query['state']);
        self::assertSame(self::ORIGIN, $query['iss']);

        return [$query['code'], $verifier];
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
        $this->browsers[] = $browser;

        return $browser;
    }

    private function json(KernelBrowser $browser, string $method, string $path, ?array $body = null): array
    {
        $browser->request($method, self::ORIGIN.$path, server: ['CONTENT_TYPE' => 'application/json'], content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode((string) $browser->getResponse()->getContent(), true, 16, JSON_THROW_ON_ERROR);
    }

    private function token(KernelBrowser $browser, array $form): array
    {
        $browser->request('POST', self::ORIGIN.'/oauth/token', $form);

        return json_decode((string) $browser->getResponse()->getContent(), true, 16, JSON_THROW_ON_ERROR);
    }

    private function mcp(string $token): Client
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo('oauth-test', '1.0')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(endpoint: self::ORIGIN.'/mcp', headers: ['Authorization' => 'Bearer '.$token], httpClient: new SymfonyKernelHttpClient($this->runtime), requestFactory: $factory, streamFactory: $factory));

        return $client;
    }
}
