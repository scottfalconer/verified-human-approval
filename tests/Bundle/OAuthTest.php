<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Omouren\McpOauthBundle\Cimd\CimdException;
use Omouren\McpOauthBundle\Cimd\ClientIdMetadataResolver;
use Omouren\McpOauthBundle\McpOauthSettings;
use PHPUnit\Framework\Attributes\DataProvider;
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
use VerifiedHumanApproval\Demo\OAuth\ConnectionOpeningCodeManager;
use VerifiedHumanApproval\Demo\OAuth\OAuthConnections;
use VerifiedHumanApproval\Demo\OAuth\Storage\PdoAccessTokenManager;
use VerifiedHumanApproval\Demo\OAuth\Storage\PdoClientManager;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\Tests\Support\OAuthGrantHooks;
use VerifiedHumanApproval\Tests\Support\ProcessRace;

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

    /** @return iterable<string, array{?string}> */
    public static function resourcesClaudeMayName(): iterable
    {
        yield 'the MCP endpoint' => [self::ORIGIN.'/mcp'];
        yield 'no resource' => [null];
    }

    #[DataProvider('resourcesClaudeMayName')]
    public function testClaudeAiConnectsWithItsClientIdMetadataDocument(?string $resource): void
    {
        // claude.ai names itself by this URL instead of registering. Its document is served here by a mock.
        $browser = $this->client();
        $browser->disableReboot();
        $browser->getKernel()->boot();
        $fetched = new MockHttpClient(new MockResponse(json_encode(['client_id' => self::CLAUDE, 'client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]])));
        $browser->getContainer()->get('test.service_container')->set(ClientMetadataHttpClient::class, new ClientMetadataHttpClient($fetched));
        [$code, $verifier] = $this->consentIn($this->signIn($browser, 'editor'), self::CLAUDE, $resource);
        $tokens = $this->token($browser, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => self::CLAUDE, 'code_verifier' => $verifier] + (null === $resource ? [] : ['resource' => $resource]));
        self::assertSame('Bearer', $tokens['token_type'] ?? null, json_encode($tokens));
        self::assertGreaterThanOrEqual(1, $fetched->getRequestsCount(), 'The document was fetched from claude.ai’s URL.');

        $result = $this->mcp($tokens['access_token'])->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertSame('pending', $result->structuredContent['status']);
        $connection = (new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite')))->pdo->query('SELECT client_name, active FROM demo_grants WHERE token_hash IS NULL')->fetch();
        self::assertSame(['client_name' => 'Claude', 'active' => 1], $connection, 'The connection carries the name from claude.ai’s own document.');
    }

    /** @return iterable<string, array{?string}> */
    public static function resourcesNamedLoosely(): iterable
    {
        yield 'no resource' => [null];
        yield 'the server\'s origin' => [self::ORIGIN];
    }

    #[DataProvider('resourcesNamedLoosely')]
    public function testAClientThatNamesNoResourceOrJustTheOriginIsStillAskedAndGetsATokenForMcp(?string $resource): void
    {
        // Not every client names the MCP endpoint as the resource, or names a scope. The server then uses its own
        // endpoint and the default scopes, and the person still sees the consent page first.
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        [$code, $verifier] = $this->consentIn($this->signedIn('editor'), $registration['client_id'], $resource);
        $tokens = $this->token($api, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $registration['client_id'], 'code_verifier' => $verifier] + (null === $resource ? [] : ['resource' => $resource]));
        $claims = json_decode(base64_decode(strtr(explode('.', $tokens['access_token'])[1], '-_', '+/')), true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame(self::ORIGIN.'/mcp', $claims['resource'] ?? null, 'The token is bound to this server’s MCP endpoint.');
        self::assertSame(['alert.read', 'alert.publish', 'approval.read'], $claims['scopes'] ?? null);
        $result = $this->mcp($tokens['access_token'])->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertSame('pending', $result->structuredContent['status']);
    }

    public function testRefusesPlainPkceAWrongVerifierAnUnregisteredRedirectAndUnknownClients(): void
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
        foreach (['plain' => ['code_challenge_method' => 'plain'], 'no method, which means plain' => []] as $case => $method) {
            $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $registration['client_id'], 'redirect_uri' => self::CALLBACK, 'code_challenge' => str_repeat('c', 43), 'resource' => self::ORIGIN.'/mcp'] + $method));
            self::assertSame(400, $browser->getResponse()->getStatusCode(), $case.': PKCE must use S256.');
            self::assertSame('invalid_request', json_decode((string) $browser->getResponse()->getContent(), true, 8)['error'] ?? null, $case);
        }

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

    public function testReconsentCannotLendItsNewDelegationToAnOldCodeOrToken(): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        $clientId = $registration['client_id'];
        $tokens = $this->connect($api, $clientId);
        $original = $this->connections()->active('editor', $clientId)['id'];
        $pending = $this->mcp($tokens['access_token'])->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertSame('pending', $pending->structuredContent['status']);

        // Consent replaces the connection, including codes that have not yet been exchanged.
        [$oldCode, $oldVerifier] = $this->consent($clientId, 'editor');
        [$newCode, $newVerifier] = $this->consent($clientId, 'editor');
        $current = $this->connections()->active('editor', $clientId)['id'];
        self::assertNotSame($original, $current);
        self::assertSame(401, $this->ping($tokens['access_token']));
        self::assertSame('invalid_grant', $this->token($api, ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId])['error'] ?? null);
        self::assertSame('invalid_grant', $this->token($api, $this->codeExchange($clientId, $oldCode, $oldVerifier))['error'] ?? null);
        $newTokens = $this->token($api, $this->codeExchange($clientId, $newCode, $newVerifier));
        self::assertSame('Bearer', $newTokens['token_type'] ?? null, 'Replacing the old connection must not revoke the new code.');
        self::assertSame(200, $this->ping($newTokens['access_token']));

        $database = $this->database();
        $proposal = json_decode((string) $database->pdo->query('SELECT proposal FROM verified_human_approval_requests')->fetchColumn(), true, 32, \JSON_THROW_ON_ERROR);
        self::assertSame($original, $proposal['actor']['delegationId'], 'A pending request keeps the connection that initiated it.');
        self::assertSame(3, (int) $database->pdo->query('SELECT COUNT(DISTINCT grant_id) FROM oauth2_authorization_codes')->fetchColumn());
        foreach (['oauth2_access_tokens', 'oauth2_refresh_tokens'] as $table) {
            self::assertSame([$current], $database->pdo->query('SELECT DISTINCT grant_id FROM '.$table.' WHERE revoked = 0')->fetchAll(\PDO::FETCH_COLUMN));
        }
        // Even a stale write that clears the revocation flag cannot make an old token follow new consent.
        $database->pdo->prepare('UPDATE oauth2_access_tokens SET revoked = 0 WHERE grant_id = ?')->execute([$original]);
        self::assertSame(401, $this->ping($tokens['access_token']));
    }

    public function testFailureToSaveANewCodeKeepsThePreviousConnectionAndCredentials(): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        $tokens = $this->connect($api, $registration['client_id']);
        $original = $this->connections()->active('editor', $registration['client_id'])['id'];
        $client = $this->kernelService(PdoClientManager::class)->find($registration['client_id']);
        self::assertNotNull($client);
        $code = new AuthorizationCode('test-code-insert-fails', new \DateTimeImmutable('+5 minutes'), $client, 'editor', []);
        $database = $this->database();
        $database->pdo->exec("CREATE TRIGGER fail_new_code BEFORE INSERT ON oauth2_authorization_codes BEGIN SELECT RAISE(ABORT, 'test code storage failure'); END");
        try {
            $this->kernelService(ConnectionOpeningCodeManager::class)->save($code);
            self::fail('The injected code storage failure must be reported.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('test code storage failure', $error->getMessage());
        } finally {
            $database->pdo->exec('DROP TRIGGER fail_new_code');
        }
        self::assertSame($original, $this->connections()->active('editor', $registration['client_id'])['id']);
        self::assertSame(1, (int) $database->pdo->query('SELECT COUNT(*) FROM demo_grants WHERE token_hash IS NULL')->fetchColumn());
        self::assertSame(0, (int) $database->pdo->query('SELECT COUNT(*) FROM oauth2_access_tokens WHERE revoked = 1')->fetchColumn());
        self::assertSame(0, (int) $database->pdo->query('SELECT COUNT(*) FROM oauth2_refresh_tokens WHERE revoked = 1')->fetchColumn());
        self::assertSame(200, $this->ping($tokens['access_token']));
    }

    /** @return iterable<string, array{string}> */
    public static function tokenGrants(): iterable
    {
        yield 'authorization code' => ['authorization_code'];
        yield 'refresh token' => ['refresh_token'];
    }

    #[DataProvider('tokenGrants')]
    public function testOverlappingExchangesIssueOnlyOnePair(string $grantType): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        $form = $this->exchange($api, $registration['client_id'], $grantType);
        $payload = ['runtime' => $this->runtime, 'form' => $form];
        $results = ProcessRace::run(\dirname(__DIR__).'/Support/oauth-token-worker.php', [$payload, $payload], $this->runtime);
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame([200, 400], $statuses, 'Independent processes overlap after a real validity read; only one consumes the credential.');
        $winner = array_values(array_filter($results, static fn (array $result): bool => 200 === $result['status']))[0]['body'];
        $loser = array_values(array_filter($results, static fn (array $result): bool => 400 === $result['status']))[0]['body'];
        self::assertSame('invalid_grant', $loser['error'] ?? null);
        self::assertSame(200, $this->ping($winner['access_token']));
        $database = $this->database();
        $expected = 'refresh_token' === $grantType ? 2 : 1;
        foreach (['oauth2_access_tokens', 'oauth2_refresh_tokens'] as $table) {
            self::assertSame($expected, (int) $database->pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(), 'No second token pair is persisted.');
            self::assertSame(1, (int) $database->pdo->query('SELECT COUNT(*) FROM '.$table.' WHERE revoked = 0')->fetchColumn());
        }
    }

    #[DataProvider('tokenGrants')]
    public function testAnOAuthErrorAfterPersistenceRollsBackConsumptionAndBothNewTokens(string $grantType): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        $form = $this->exchange($api, $registration['client_id'], $grantType);
        $failing = $this->client();
        $failing->disableReboot();
        $failing->getKernel()->boot();
        $failOnce = true;
        OAuthGrantHooks::afterRefreshSave($failing->getContainer()->get('test.service_container')->get(AuthorizationServer::class), $grantType, static function () use (&$failOnce): void {
            // League catches protocol exceptions and returns an HTTP error. The transaction must still undo
            // both persisted tokens and, on rotation, the revocation of the credential being replaced.
            if ($failOnce) {
                $failOnce = false;
                throw OAuthServerException::serverError('test failure after persisting refresh token');
            }
        });
        $failure = $this->token($failing, $form);
        self::assertSame('server_error', $failure['error'] ?? null);
        $database = $this->database();
        foreach (['oauth2_access_tokens', 'oauth2_refresh_tokens'] as $table) {
            self::assertSame('refresh_token' === $grantType ? 1 : 0, (int) $database->pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
            self::assertSame(0, (int) $database->pdo->query('SELECT COUNT(*) FROM '.$table.' WHERE revoked = 1')->fetchColumn());
        }
        $retry = $this->token($failing, $form);
        self::assertSame('Bearer', $retry['token_type'] ?? null, 'A failed exchange leaves its input usable and clears its context, even without rebooting the kernel.');
        self::assertSame(200, $this->ping($retry['access_token']));
    }

    public function testAnExplicitRevocationDuringReuseRejectionIsCommitted(): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        $form = $this->exchange($api, $registration['client_id'], 'authorization_code');
        $tokens = $this->token($api, $form);
        $grantId = $this->connections()->active('editor', $registration['client_id'])['id'];
        $api->disableReboot();
        $container = $api->getContainer()->get('test.service_container');
        $connections = $container->get(AgentConnections::class);
        OAuthGrantHooks::afterCredentialRead($container->get(AuthorizationServer::class), 'authorization_code', static function (bool $revoked) use ($connections, $grantId): void {
            if ($revoked) {
                $connections->revoke($grantId, 'campaigns', 'editor', 'editor');
            }
        });
        self::assertSame('invalid_grant', $this->token($api, $form)['error'] ?? null);
        self::assertSame(0, (int) $this->database()->grant($grantId)['active']);
        self::assertSame(401, $this->ping($tokens['access_token']), 'An explicit connection revocation survives the rejected exchange.');
    }

    public function testExpiryBetweenPasskeyPromptAndCompletionPreventsTheAction(): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        $tokens = $this->connect($api, $registration['client_id']);
        $pending = $this->mcp($tokens['access_token'])->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        $id = $pending->structuredContent['requestId'];
        $browser = $this->signedIn('editor');
        $browser->request('GET', $this->accounts['editor']['passkey_link']);
        $csrf = (string) $browser->request('GET', self::ORIGIN.'/account/passkeys')->filter('[data-passkey-panel]')->attr('data-csrf');
        $options = $this->post($browser, '/account/passkeys/options', $csrf, []);
        $authenticator = new VirtualAuthenticator(self::ORIGIN);
        self::assertSame('registered', $this->post($browser, '/account/passkeys/complete', $csrf, ['ceremony_id' => $options['ceremony_id'], 'credential' => $authenticator->registration($options['publicKey'])])['status']);
        $csrf = (string) $browser->request('GET', self::ORIGIN.'/approval/'.$id)->filter('[data-passkey-panel]')->attr('data-csrf');
        $ceremony = $this->post($browser, '/approval/'.$id.'/options', $csrf, []);
        self::assertArrayHasKey('ceremony_id', $ceremony);
        $database = $this->database();
        $database->pdo->prepare('UPDATE demo_grants SET created_at = ? WHERE client_id = ?')->execute([time() - OAuthConnections::LIFETIME_SECONDS - 60, $registration['client_id']]);
        $result = $this->post($browser, '/approval/'.$id.'/complete', $csrf, ['ceremony_id' => $ceremony['ceremony_id'], 'credential' => $authenticator->assertion($ceremony['publicKey'])]);
        self::assertContains($result['status'], ['denied', 'invalidated']);
        self::assertSame('delegation_denied', $result['reason']);
        self::assertSame(0, (int) $database->pdo->query("SELECT mutation_count FROM demo_alerts WHERE id = 'riverside-evacuation'")->fetchColumn());
    }

    public function testCleanupKeepsAnExpiredAccessTokenWhileItsRefreshTokenIsValid(): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        $tokens = $this->connect($api, $registration['client_id']);
        $this->database()->pdo->exec('UPDATE oauth2_access_tokens SET expiry = 1');
        self::assertSame(0, $this->kernelService(PdoAccessTokenManager::class)->clearExpired());
        $refreshed = $this->token($api, ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $registration['client_id']]);
        self::assertSame('Bearer', $refreshed['token_type'] ?? null);
        self::assertSame(200, $this->ping($refreshed['access_token']));
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
        self::assertNotSame(200, $api->getResponse()->getStatusCode(), 'The device flow is never enabled.');
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
        return $this->consentIn($this->signedIn($user), $clientId);
    }

    /** @return array{0: string, 1: string} the authorization code and its PKCE verifier */
    private function consentIn(KernelBrowser $browser, string $clientId, ?string $resource = self::ORIGIN.'/mcp'): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::CALLBACK, 'state' => 'state-123',
            'code_challenge' => self::challenge($verifier), 'code_challenge_method' => 'S256',
        ] + (null === $resource ? [] : ['resource' => $resource])));
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
        return $this->kernelService(OAuthConnections::class);
    }

    private function database(): DemoDatabase
    {
        return new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
    }

    private function codeExchange(string $clientId, string $code, string $verifier): array
    {
        return ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $clientId, 'code_verifier' => $verifier, 'resource' => self::ORIGIN.'/mcp'];
    }

    private function exchange(KernelBrowser $api, string $clientId, string $grantType): array
    {
        if ('refresh_token' === $grantType) {
            $tokens = $this->connect($api, $clientId);

            return ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId, 'resource' => self::ORIGIN.'/mcp'];
        }
        [$code, $verifier] = $this->consent($clientId, 'editor');

        return $this->codeExchange($clientId, $code, $verifier);
    }

    private function post(KernelBrowser $browser, string $path, string $csrf, array $body): array
    {
        $browser->request('POST', self::ORIGIN.$path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ACCEPT' => 'application/json'], content: json_encode((object) $body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $browser->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
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
        return $this->signIn($this->client(), $user);
    }

    private function signIn(KernelBrowser $browser, string $user): KernelBrowser
    {
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
        $request = static fn () => $browser->request($method, self::ORIGIN.$path, server: ['CONTENT_TYPE' => 'application/json'], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        if ('POST' === $method && '/oauth/register' === $path) {
            // DCR is the provider's deprecated compatibility path. Assert its precise warning at the boundary;
            // PHPUnit 11 still fails on an expected user deprecation. All other deprecations reach its handler.
            $expected = 'Since omouren/mcp-oauth-bundle 0.1: RFC 7591 Dynamic Client Registration is deprecated. Prefer Client ID Metadata Documents (CIMD).';
            $warnings = 0;
            $previous = null;
            $previous = set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($expected, &$warnings, &$previous): bool {
                if (\E_USER_DEPRECATED === $severity && $expected === $message) {
                    ++$warnings;

                    return true;
                }

                return null !== $previous && $previous($severity, $message, $file, $line);
            });
            try {
                $request();
            } finally {
                restore_error_handler();
            }
            self::assertSame(429 === $browser->getResponse()->getStatusCode() ? 0 : 1, $warnings, 'Only a registration that reaches the provider emits its known DCR deprecation.');
        } else {
            $request();
        }

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
