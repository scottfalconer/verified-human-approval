<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\Client as OAuthClient;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
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
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VerifiedHumanApproval\Demo\Account\AgentConnections;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;
use VerifiedHumanApproval\Demo\OAuth\ClientMetadataHttpClient;
use VerifiedHumanApproval\Demo\OAuth\ConnectionOpeningCodeManager;
use VerifiedHumanApproval\Demo\OAuth\ConsentRequest;
use VerifiedHumanApproval\Demo\OAuth\ConsentSession;
use VerifiedHumanApproval\Demo\OAuth\OAuthConnections;
use VerifiedHumanApproval\Demo\OAuth\ReturnAddress;
use VerifiedHumanApproval\Demo\OAuth\Storage\PdoAccessTokenManager;
use VerifiedHumanApproval\Demo\OAuth\Storage\PdoClientManager;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\Tests\Support\OAuthGrantHooks;
use VerifiedHumanApproval\Tests\Support\ProcessRace;

/**
 * MCP authorization the way a remote client such as claude.ai does it, end to end, against the OAuth server the
 * demo uses: league/oauth2-server with omouren/mcp-oauth-bundle, and the demo's own consent step.
 */
final class OAuthTest extends TestCase
{
    private const ORIGIN = 'http://localhost:8080';
    private const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';
    private const CLAUDE = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
    private const CHATGPT = 'https://chatgpt.com/oauth/client.json';
    private const CHATGPT_CALLBACK = 'https://chatgpt.com/connector_platform_oauth_redirect';
    /** A return address registered by someone other than the person connecting. */
    private const ELSEWHERE = 'https://attacker.example/callback';
    /** A return address on this computer, where a local client such as Claude Code listens for its code. */
    private const LOOPBACK = 'http://127.0.0.1:33418/callback';
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
        self::assertSame(self::ORIGIN.'/oauth/register', $server['registration_endpoint'] ?? null, 'A local demo keeps open registration for clients that still need it.');

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
        $metadata = new MockResponse(json_encode(['client_id' => self::CLAUDE, 'client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]]));
        $fetched = new MockHttpClient($metadata);
        $browser->getContainer()->get('test.service_container')->set(ClientMetadataHttpClient::class, new ClientMetadataHttpClient($fetched));
        [$code, $verifier] = $this->consentIn($this->signIn($browser, 'editor'), self::CLAUDE, $resource, static function (string $page): void {
            self::assertStringContainsString('Published by claude.ai. It returns to claude.ai.', $page, 'The host that publishes the client’s metadata vouches for its name.');
            self::assertStringContainsString('It asks to read public alerts, ask to issue public alerts as you, read the outcome of your approvals.', $page);
            self::assertStringNotContainsString('Name not verified', $page);
        });
        $tokens = $this->token($browser, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => self::CLAUDE, 'code_verifier' => $verifier] + (null === $resource ? [] : ['resource' => $resource]));
        self::assertSame('Bearer', $tokens['token_type'] ?? null, json_encode($tokens));
        self::assertGreaterThanOrEqual(1, $fetched->getRequestsCount(), 'The document was fetched from claude.ai’s URL.');
        self::assertSame(0, $metadata->getRequestOptions()['max_redirects'], 'The metadata fetch stays at the URL checked by the resolver.');

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
        self::assertSame(self::ORIGIN.'/mcp', $claims['aud'] ?? null, 'Its audience is that endpoint too, not the client (RFC 8707).');
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
    }

    public function testTheConsentPageSaysWhatConnectingMeansAndCannotBeFramed(): void
    {
        $api = $this->client();
        $registration = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::LOOPBACK], 'client_name' => 'Claude Code']);
        $browser = $this->signedIn('editor');
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $registration['client_id'], 'redirect_uri' => self::LOOPBACK, 'code_challenge' => self::challenge(str_repeat('d', 43)), 'code_challenge_method' => 'S256', 'resource' => self::ORIGIN.'/mcp', 'scope' => 'alert.read approval.read']));
        $page = $browser->request('GET', self::absolute((string) $browser->getResponse()->headers->get('Location')));
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame("frame-ancestors 'none'", $browser->getResponse()->headers->get('Content-Security-Policy'), 'Nobody can frame the page to trick a click on Allow.');
        self::assertSame('DENY', $browser->getResponse()->headers->get('X-Frame-Options'));
        $text = $page->filter('.approval-sheet')->text();
        self::assertStringContainsString('Let Claude Code act for', $text);
        self::assertStringContainsString('Name not verified: it registered itself with this site and chose that name. It returns to 127.0.0.1.', $text, 'A client that named itself is shown as such, with where its code goes.');
        self::assertStringContainsString('It asks to read public alerts, read the outcome of your approvals.', $text, 'Consent lists what is being granted.');
        self::assertStringContainsString('Connecting does not approve anything.', $text);
        self::assertStringContainsString('It returns only to this computer', $text, 'A loopback client gets a warning.');
    }

    public function testALinkToTheConsentPageCannotChooseWhatItShowsOrApproveAnything(): void
    {
        // A link straight to the consent page that names Claude and claude.ai, for a client whose code goes elsewhere.
        $api = $this->client();
        $impostor = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::ELSEWHERE], 'client_name' => 'acme-tools-9931']);
        $browser = $this->signedIn('editor');
        $authorize = self::authorizeQuery($impostor['client_id'], self::ELSEWHERE);
        $link = self::ORIGIN.'/oauth/consent?'.http_build_query($authorize + ['client_name' => 'Claude', 'redirect_host' => 'claude.ai', 'scope' => 'alert.read']);
        $page = $browser->request('GET', $link);
        self::assertSame(410, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Nothing is waiting for your consent', $page->text());
        self::assertStringNotContainsString('claude.ai', $page->text(), 'The page never shows what its link says.');
        $browser->request('POST', $link, ['_csrf_token' => 'anything', 'consent' => 'anything']);
        self::assertSame(403, $browser->getResponse()->getStatusCode(), 'An Allow needs the page’s own form.');

        // Only the authorization request puts a client in front of the person: under the name it registered, with
        // the return address the server checked.
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query($authorize));
        self::assertSame('/oauth/consent', $browser->getResponse()->headers->get('Location'));
        $text = $browser->request('GET', self::ORIGIN.'/oauth/consent?'.http_build_query(['client_name' => 'Claude', 'redirect_host' => 'claude.ai']))->filter('.approval-sheet')->text();
        self::assertStringContainsString('Let acme-tools-9931 act for', $text);
        self::assertStringContainsString('Name not verified', $text);
        self::assertStringContainsString('It returns to attacker.example. Allow it only if you started connecting from attacker.example.', $text);
        self::assertStringNotContainsString('claude.ai', $text);
        self::assertStringNotContainsString('this computer', $text, 'The loopback warning follows the checked return address, not the link.');
    }

    /** @return iterable<string, array{string}> redirect URIs in which PHP's parse_url and a browser find different hosts */
    public static function redirectUrisReadTwoWays(): iterable
    {
        // parse_url takes "attacker.example\" for a user name and finds claude.ai; a browser ends the host at the backslash.
        yield 'a backslash before a user name' => ['https://attacker.example\@claude.ai/api/mcp/auth_callback'];
        // The same over plain HTTP, which the provider accepts only back to this computer.
        yield 'plain HTTP, a backslash, then this computer' => ['http://attacker.example\@localhost/callback'];
        // parse_url finds no host; a browser reads attacker.example as one.
        yield 'one slash after the scheme' => ['https:/attacker.example/callback'];
    }

    /** @return iterable<string, array{string}> redirect URIs in plain form, but neither on https nor back to this computer, as MCP requires */
    public static function redirectUrisNeitherHttpsNorLocal(): iterable
    {
        // A browser's own link: Safari and Chrome on iOS open these as https://attacker.example/…, off this computer.
        yield 'Safari’s own link' => ['x-safari-https://attacker.example/api/mcp/auth_callback'];
        yield 'Chrome’s own link' => ['googlechromes://attacker.example/callback'];
        // An application's own link goes to whichever application opens it, which the page cannot name.
        yield 'an application’s own link' => ['cursor://anysphere.cursor-mcp/oauth/callback'];
        yield 'a private-use scheme' => ['com.example.app:/oauth2redirect'];
        // Plain HTTP would carry the code across the network.
        yield 'plain HTTP to another computer' => ['http://attacker.example/callback'];
    }

    #[DataProvider('redirectUrisReadTwoWays')]
    #[DataProvider('redirectUrisNeitherHttpsNorLocal')]
    public function testARedirectUriWithNoReturnAddressIsRefusedBeforeAnyoneIsAsked(string $redirectUri): void
    {
        // Sent without json(): refused before the provider's controller, it raises no registration deprecation.
        $api = $this->client();
        $api->request('POST', self::ORIGIN.'/oauth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['redirect_uris' => [$redirectUri], 'client_name' => 'Claude'], \JSON_THROW_ON_ERROR));
        self::assertSame(400, $api->getResponse()->getStatusCode(), (string) $api->getResponse()->getContent());
        self::assertSame('invalid_redirect_uri', json_decode((string) $api->getResponse()->getContent(), true, 4)['error'] ?? null);
        self::assertSame(0, (int) $this->database()->pdo->query('SELECT COUNT(*) FROM oauth2_clients')->fetchColumn());

        // A client stored with one all the same, as a client ID metadata document could list it, is refused before
        // the person is asked, and its code goes nowhere.
        $client = (new OAuthClient('Claude', bin2hex(random_bytes(16)), null))->setRedirectUris(new RedirectUri($redirectUri))
            ->setGrants(new Grant('authorization_code'))->setScopes(new Scope('alert.read'), new Scope('alert.publish'), new Scope('approval.read'));
        $this->kernelService(PdoClientManager::class)->save($client);
        $browser = $this->signedIn('editor');
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query(self::authorizeQuery($client->getIdentifier(), $redirectUri)));
        self::assertSame(400, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        self::assertSame('invalid_request', json_decode((string) $browser->getResponse()->getContent(), true, 8)['error'] ?? null);
        self::assertFalse($browser->getResponse()->headers->has('Location'));
        $browser->request('GET', self::ORIGIN.'/oauth/consent');
        self::assertSame(410, $browser->getResponse()->getStatusCode(), 'Nothing waits for the person’s answer.');
    }

    /** @return iterable<string, array{string, string, bool}> a redirect URI, where the consent page says it returns, and whether that is this computer */
    public static function plainRedirectUris(): iterable
    {
        yield 'claude.ai' => [self::CALLBACK, 'claude.ai', false];
        yield 'a host in capitals' => ['https://Claude.AI/api/mcp/auth_callback', 'claude.ai', false];
        yield 'a port, a path and a query' => ['https://app.example:8443/oauth/callback?from=mcp', 'app.example', false];
        yield 'a loopback address' => ['http://127.0.0.1:33418/callback', '127.0.0.1', true];
        yield 'another loopback address' => ['https://127.0.0.2/callback', '127.0.0.2', true];
        yield 'localhost' => ['http://localhost:6274/oauth/callback', 'localhost', true];
        yield 'a name under localhost' => ['https://app.localhost/callback', 'app.localhost', true];
        yield 'the IPv6 loopback address' => ['http://[::1]:8080/callback', '[::1]', true];
    }

    #[DataProvider('plainRedirectUris')]
    public function testTheConsentPageNamesTheHostABrowserSendsTheCodeTo(string $redirectUri, string $returnsTo, bool $thisComputer): void
    {
        $address = ReturnAddress::of($redirectUri);
        self::assertNotNull($address);
        self::assertSame([$redirectUri, $returnsTo, $thisComputer], [$address->uri, $address->host, $address->thisComputer]);
    }

    /** @return iterable<string, array{string}> */
    public static function redirectUrisNotPlain(): iterable
    {
        yield from self::redirectUrisReadTwoWays();
        yield 'no slash after the scheme' => ['https:attacker.example/callback'];
        yield 'three slashes' => ['https:///attacker.example/callback'];
        yield 'a user name' => ['https://claude.ai@attacker.example/callback'];
        yield 'a tab, which a browser drops' => ["https://claude.ai\t@attacker.example/callback"];
        yield 'a space' => ['https://claude.ai/call back'];
        yield 'a fragment' => ['https://claude.ai/callback#done'];
        yield 'a letter beyond ASCII' => ['https://сlaude.ai/callback'];
        yield 'a percent-encoded host' => ['https://claude%2Eai/callback'];
        yield 'an IPv4 address as one number' => ['http://2130706433/callback'];
        yield 'an IPv4 address in short form' => ['http://127.1/callback'];
        yield 'an IPv4 address in hexadecimal' => ['http://0x7f.0.0.1/callback'];
        yield 'an IPv4 address with a trailing dot' => ['http://127.0.0.1./callback'];
        yield 'the IPv6 loopback address in full' => ['http://[0:0:0:0:0:0:0:1]/callback'];
        yield 'a port past 65535' => ['https://claude.ai:65536/callback'];
    }

    #[DataProvider('redirectUrisNotPlain')]
    public function testARedirectUriNotInAPlainFormHasNoReturnAddress(string $redirectUri): void
    {
        self::assertNull(ReturnAddress::of($redirectUri));
    }

    public function testAnAllowApprovesOnlyTheRequestItAnsweredAndOnlyOnce(): void
    {
        $api = $this->client();
        $claude = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        $impostor = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::ELSEWHERE], 'client_name' => 'Claude']);
        $browser = $this->signedIn('editor');
        foreach ([
            'another client' => self::authorizeQuery($impostor['client_id'], self::ELSEWHERE),
            'the same client with another state and PKCE challenge' => self::authorizeQuery($claude['client_id'], self::CALLBACK),
        ] as $case => $next) {
            // The person allows Claude's request; before it resumes, another request arrives in the same session.
            $this->allow($browser, self::authorizeQuery($claude['client_id'], self::CALLBACK));
            $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query($next));
            self::assertSame('/oauth/consent', $browser->getResponse()->headers->get('Location'), $case.': a request the person did not allow is asked about, never approved.');
        }

        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query(self::authorizeQuery($claude['client_id'], self::CALLBACK)));
        $form = $browser->request('GET', self::ORIGIN.'/oauth/consent')->selectButton('Allow')->form();
        $browser->submit($form);
        $resume = (string) $browser->getResponse()->headers->get('Location');
        $browser->submit($form);
        self::assertSame($resume, $browser->getResponse()->headers->get('Location'), 'A second click on Allow answers the same way.');
        $browser->request('GET', self::absolute($resume));
        self::assertStringStartsWith(self::CALLBACK.'?', (string) $browser->getResponse()->headers->get('Location'), 'The request the person allowed gets its code.');
        $browser->request('GET', self::absolute($resume));
        self::assertSame('/oauth/consent', $browser->getResponse()->headers->get('Location'), 'An Allow is used once.');
    }

    /** @return iterable<string, array{string, bool}> a return address the server trusts, and whether a client ID metadata document gives it */
    public static function returnAddressesTheServerTrusts(): iterable
    {
        yield 'one that claude.ai’s metadata document gives' => [self::CALLBACK, true];
        yield 'one on this computer, from a client that registered itself' => [self::LOOPBACK, false];
    }

    #[DataProvider('returnAddressesTheServerTrusts')]
    public function testDontAllowTellsTheClientThePersonRefusedAndEndsTheRequest(string $redirectUri, bool $metadataDocument): void
    {
        $browser = $this->client();
        if ($metadataDocument) {
            $this->serveMetadataDocument($browser, ['client_id' => self::CLAUDE, 'client_name' => 'Claude', 'redirect_uris' => [$redirectUri]]);
            $clientId = self::CLAUDE;
        } else {
            $clientId = $this->json($this->client(), 'POST', '/oauth/register', ['redirect_uris' => [$redirectUri], 'client_name' => 'Claude Code'])['client_id'];
        }
        $authorize = self::authorizeQuery($clientId, $redirectUri);
        $this->signIn($browser, 'editor')->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query($authorize));
        $page = $browser->request('GET', self::ORIGIN.'/oauth/consent');
        $allow = $page->selectButton('Allow')->form();
        $browser->request('POST', self::ORIGIN.'/oauth/consent', array_diff_key($allow->getValues(), ['answer' => true]));
        self::assertSame(400, $browser->getResponse()->getStatusCode(), 'The page’s form counts only as the answer its button gives.');
        $browser->submit($page->selectButton('Don’t allow')->form());
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $resume = (string) $browser->getResponse()->headers->get('Location');
        $browser->submit($allow);
        self::assertSame(410, $browser->getResponse()->getStatusCode(), 'Once refused, the request cannot be allowed from its page.');

        $browser->request('GET', self::absolute($resume));
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith($redirectUri.'?', $location, 'The person goes back to the client that asked.');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $refusal);
        self::assertSame('access_denied', $refusal['error'] ?? null, 'RFC 6749 §4.1.2.1: the client learns that the person refused.');
        self::assertSame($authorize['state'], $refusal['state'] ?? null, 'With the state of the request the person refused.');
        self::assertSame(self::ORIGIN, $refusal['iss'] ?? null, 'RFC 9207: the response names its issuer.');
        self::assertArrayNotHasKey('code', $refusal);
        $this->assertNothingConnected();

        $browser->request('GET', self::absolute($resume));
        self::assertSame('/oauth/consent', $browser->getResponse()->headers->get('Location'), 'A refusal is used once: the same request is asked about again.');
    }

    public function testDontAllowForAClientThatChoseItsOwnReturnAddressEndsOnThisSite(): void
    {
        // RFC 9700 §4.11.2: anyone can register a client that returns to a site of their own. A person who refuses
        // it, because something looks wrong, must not be sent there all the same.
        $impostor = $this->json($this->client(), 'POST', '/oauth/register', ['redirect_uris' => [self::ELSEWHERE], 'client_name' => 'acme-tools-9931']);
        $browser = $this->signedIn('editor');
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query(self::authorizeQuery($impostor['client_id'], self::ELSEWHERE)));
        $page = $browser->request('GET', self::ORIGIN.'/oauth/consent');
        $allow = $page->selectButton('Allow')->form();
        $browser->submit($page->selectButton('Don’t allow')->form());
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $resume = (string) $browser->getResponse()->headers->get('Location');
        $browser->submit($allow);
        self::assertSame(410, $browser->getResponse()->getStatusCode(), 'Once refused, the request cannot be allowed from its page.');

        $answer = $browser->request('GET', self::absolute($resume));
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->headers->get('Location'));
        self::assertFalse($browser->getResponse()->headers->has('Location'), 'The person stays on this site.');
        $text = $answer->filter('.approval-sheet')->text();
        self::assertStringContainsString('Not connected', $text);
        self::assertStringContainsString('You did not allow acme-tools-9931 to act for you. You can close this tab.', $text);
        self::assertStringContainsString('It registered itself with this site, so you are not sent on to attacker.example, the address it chose.', $text);
        self::assertCount(0, $answer->filter('a[href*="attacker.example"]'), 'Nor does the page link there.');
        self::assertSame("frame-ancestors 'none'", $browser->getResponse()->headers->get('Content-Security-Policy'));
        $this->assertNothingConnected();

        $browser->request('GET', self::absolute($resume));
        self::assertSame('/oauth/consent', $browser->getResponse()->headers->get('Location'), 'A refusal is used once: the same request is asked about again.');
    }

    /** @return iterable<string, array{string}> scopes that a client registered for alert.read alone cannot have */
    public static function scopesTheClientCannotHave(): iterable
    {
        yield 'one beyond its own' => ['alert.read alert.publish'];
    }

    #[DataProvider('scopesTheClientCannotHave')]
    public function testARequestForAScopeTheClientCannotHaveGoesBackOnlyToAnAddressTheServerTrusts(string $scope): void
    {
        $reader = $this->json($this->client(), 'POST', '/oauth/register', ['redirect_uris' => [self::ELSEWHERE, self::LOOPBACK], 'client_name' => 'Reader', 'scope' => 'alert.read']);
        $browser = $this->signedIn('editor');
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query(['scope' => $scope] + self::authorizeQuery($reader['client_id'], self::ELSEWHERE)));
        self::assertSame(400, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->headers->get('Location'));
        self::assertFalse($browser->getResponse()->headers->has('Location'), 'It registered itself, so the error is not sent to the address it chose (RFC 9700 §4.11.2).');
        self::assertSame('invalid_scope', json_decode((string) $browser->getResponse()->getContent(), true, 8)['error'] ?? null);

        $query = ['scope' => $scope] + self::authorizeQuery($reader['client_id'], self::LOOPBACK);
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query($query));
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::LOOPBACK.'?', $location, 'Back on this computer, the error goes to the client (RFC 6749 §4.1.2.1).');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $refusal);
        self::assertSame('invalid_scope', $refusal['error'] ?? null);
        self::assertSame($query['state'], $refusal['state'] ?? null);
        self::assertArrayNotHasKey('code', $refusal);
        $browser->request('GET', self::ORIGIN.'/oauth/consent');
        self::assertSame(410, $browser->getResponse()->getStatusCode(), 'Neither request was put to the person.');
    }

    public function testAConsentPageLeftOpenCannotApproveTheRequestThatReplacedIt(): void
    {
        $api = $this->client();
        $claude = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        $other = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::ELSEWHERE], 'client_name' => 'Other tool']);
        $browser = $this->signedIn('editor');
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query(self::authorizeQuery($claude['client_id'], self::CALLBACK)));
        $form = $browser->request('GET', self::ORIGIN.'/oauth/consent')->selectButton('Allow')->form();
        $replacement = self::authorizeQuery($other['client_id'], self::ELSEWHERE);
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query($replacement));

        $browser->submit($form);
        self::assertSame(410, $browser->getResponse()->getStatusCode(), 'The page named a request that no longer waits.');
        self::assertStringContainsString('Let Other tool act for', $browser->request('GET', self::ORIGIN.'/oauth/consent')->filter('.approval-sheet')->text(), 'The newer request still waits for its own answer.');
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query($replacement));
        self::assertSame('/oauth/consent', $browser->getResponse()->headers->get('Location'), 'Nobody allowed it.');
    }

    public function testConsentListsOnlyScopesTheClientMayHave(): void
    {
        $api = $this->client();
        $reader = $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Reader', 'scope' => 'alert.read']);
        $browser = $this->signedIn('editor');

        // Asking for no scope, it gets its own: the page lists those, and the token and the connection carry exactly them.
        [$code, $verifier] = $this->consentIn($browser, $reader['client_id'], self::ORIGIN.'/mcp', static fn (string $page) => self::assertStringContainsString('It asks to read public alerts.', $page));
        $tokens = $this->token($api, $this->codeExchange($reader['client_id'], $code, $verifier));
        $claims = json_decode(base64_decode(strtr(explode('.', $tokens['access_token'])[1], '-_', '+/')), true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame(['alert.read'], $claims['scopes'] ?? null);
        self::assertSame(['alert.read'], json_decode((string) $this->connections()->active('editor', $reader['client_id'])['scopes'], true, 4, \JSON_THROW_ON_ERROR));
    }

    public function testAConsentRequestWaitsTenMinutesAndAnAllowIsUsedOnce(): void
    {
        $clock = new MockClock();
        $consents = new ConsentSession($clock);
        $session = new Session(new MockArraySessionStorage());
        $request = new ConsentRequest('page', 'binding', '/oauth/authorize?client_id=claude', 'Claude', 'claude.ai', ReturnAddress::of(self::CALLBACK) ?? self::fail('claude.ai’s callback is plain.'), ['alert.read']);
        $consents->ask($session, $request);
        self::assertNull($consents->allow($session, 'another page'), 'Allow names the page that asked.');
        $clock->sleep(ConsentSession::LIFETIME_SECONDS);
        self::assertNull($consents->waiting($session), 'An unanswered request stops waiting.');
        self::assertNull($consents->allow($session, 'page'));

        $consents->ask($session, $request);
        self::assertSame('/oauth/authorize?client_id=claude', $consents->allow($session, 'page'));
        self::assertSame('/oauth/authorize?client_id=claude', $consents->allow($session, 'page'), 'A second click answers the same way.');
        self::assertNull($consents->waiting($session), 'An allowed request is no longer shown.');
        self::assertNull($consents->take($session, 'another binding'));
        $clock->sleep(ConsentSession::LIFETIME_SECONDS);
        self::assertNull($consents->take($session, 'binding'), 'An Allow expires with its request.');

        $consents->ask($session, $request);
        $consents->allow($session, 'page');
        self::assertTrue($consents->take($session, 'binding'));
        self::assertNull($consents->take($session, 'binding'), 'An Allow is used once.');
    }

    public function testADontAllowStandsUntilItsRequestUsesIt(): void
    {
        $consents = new ConsentSession(new MockClock());
        $session = new Session(new MockArraySessionStorage());
        $consents->ask($session, new ConsentRequest('page', 'binding', '/oauth/authorize?client_id=claude', 'Claude', 'claude.ai', ReturnAddress::of(self::CALLBACK) ?? self::fail('claude.ai’s callback is plain.'), ['alert.read']));
        self::assertNull($consents->deny($session, 'another page'), 'Don’t allow names the page that asked.');
        self::assertSame('/oauth/authorize?client_id=claude', $consents->allow($session, 'page'));
        self::assertSame('/oauth/authorize?client_id=claude', $consents->deny($session, 'page'), 'It replaces an Allow the request has not used yet.');
        self::assertNull($consents->allow($session, 'page'), 'No Allow replaces it.');
        self::assertSame('/oauth/authorize?client_id=claude', $consents->deny($session, 'page'), 'A second click answers the same way.');
        self::assertNull($consents->waiting($session), 'A refused request is no longer shown.');
        self::assertNull($consents->take($session, 'another binding'));
        self::assertFalse($consents->take($session, 'binding'));
        self::assertNull($consents->take($session, 'binding'), 'A refusal is used once.');
    }

    public function testARuntimeCanTurnOpenRegistrationOff(): void
    {
        // The public demo does: claude.ai names itself with its client ID metadata document instead.
        (new Filesystem())->remove($this->runtime);
        $this->accounts = DemoSetup::initialize($this->runtime, self::ORIGIN, dynamicClientRegistration: false)['accounts'];
        $api = $this->client();
        self::assertArrayNotHasKey('registration_endpoint', $this->json($api, 'GET', '/.well-known/oauth-authorization-server'));
        $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::ELSEWHERE], 'client_name' => 'Claude']);
        self::assertSame(404, $api->getResponse()->getStatusCode());
        self::assertSame(0, (int) $this->database()->pdo->query('SELECT COUNT(*) FROM oauth2_clients')->fetchColumn());

        $browser = $this->client();
        $browser->disableReboot();
        $browser->getKernel()->boot();
        $browser->getContainer()->get('test.service_container')->set(ClientMetadataHttpClient::class, new ClientMetadataHttpClient(new MockHttpClient(new MockResponse(json_encode(['client_id' => self::CLAUDE, 'client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]])))));
        [$code, $verifier] = $this->consentIn($this->signIn($browser, 'editor'), self::CLAUDE);
        self::assertSame('Bearer', $this->token($browser, $this->codeExchange(self::CLAUDE, $code, $verifier))['token_type'] ?? null);
    }

    public function testChatGptConnectsWithItsClientIdMetadataDocumentWhereRegistrationIsOff(): void
    {
        // ChatGPT's document as chatgpt.com serves it. It prefers private_key_jwt, which this server does not offer,
        // so it exchanges the code as a public client with its PKCE verifier.
        (new Filesystem())->remove($this->runtime);
        $this->accounts = DemoSetup::initialize($this->runtime, self::ORIGIN, dynamicClientRegistration: false)['accounts'];
        $browser = $this->client();
        $browser->disableReboot();
        $browser->getKernel()->boot();
        $document = [
            'client_id' => self::CHATGPT, 'client_uri' => 'https://chatgpt.com/', 'redirect_uris' => [self::CHATGPT_CALLBACK],
            'token_endpoint_auth_method' => 'private_key_jwt', 'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
            'grant_types' => ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:jwt-bearer'],
            'response_types' => ['code'], 'client_name' => 'ChatGPT', 'token_endpoint_auth_signing_alg' => 'RS256',
            'jwks_uri' => 'https://chatgpt.com/oauth/jwks.json',
        ];
        $fetched = new MockHttpClient(new MockResponse(json_encode($document)));
        $browser->getContainer()->get('test.service_container')->set(ClientMetadataHttpClient::class, new ClientMetadataHttpClient($fetched));
        [$code, $verifier] = $this->consentIn($this->signIn($browser, 'editor'), self::CHATGPT, checkPage: static function (string $page): void {
            self::assertStringContainsString('Published by chatgpt.com. It returns to chatgpt.com.', $page);
            self::assertStringNotContainsString('Name not verified', $page);
        }, redirectUri: self::CHATGPT_CALLBACK);
        $tokens = $this->token($browser, $this->codeExchange(self::CHATGPT, $code, $verifier, self::CHATGPT_CALLBACK));
        self::assertSame('Bearer', $tokens['token_type'] ?? null, json_encode($tokens));
        self::assertSame(1, $fetched->getRequestsCount(), 'The document was fetched from chatgpt.com.');
        self::assertSame('pending', $this->mcp($tokens['access_token'])->callTool('publish_alert', ['alert_id' => 'riverside-evacuation'])->structuredContent['status']);
    }

    public function testOpenRegistrationIsOnByDefaultOnlyForADemoOnThisComputer(): void
    {
        // Setup told nothing chooses by the address, and so does a runtime whose settings do not say.
        $public = 'https://alerts.example';
        foreach ([$public => false, self::ORIGIN => true] as $origin => $open) {
            (new Filesystem())->remove($this->runtime);
            $this->accounts = DemoSetup::initialize($this->runtime, $origin)['accounts'];
            $settings = json_decode((string) file_get_contents($this->runtime.'/settings.json'), true, 32, \JSON_THROW_ON_ERROR);
            self::assertSame($open, $settings['dynamic_client_registration'], $origin);
            unset($settings['dynamic_client_registration']);
            file_put_contents($this->runtime.'/settings.json', json_encode($settings, \JSON_THROW_ON_ERROR));
            if (!$open) {
                $browser = new KernelBrowser(new DemoKernel($this->runtime, 'test'));
                $this->browsers[] = $browser;
                $browser->request('GET', $origin.'/.well-known/oauth-authorization-server');
                self::assertArrayNotHasKey('registration_endpoint', json_decode((string) $browser->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR));
                $browser->request('POST', $origin.'/oauth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude'], \JSON_THROW_ON_ERROR));
                self::assertSame(404, $browser->getResponse()->getStatusCode(), 'A demo at a public address does not let clients register themselves.');
            }
        }
        $api = $this->client();
        $this->json($api, 'POST', '/oauth/register', ['redirect_uris' => [self::CALLBACK], 'client_name' => 'Claude']);
        self::assertSame(201, $api->getResponse()->getStatusCode(), 'A demo on this computer does.');
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

    /**
     * @param (\Closure(string): void)|null $checkPage checks the consent page's text; by default, that it lists every scope
     *
     * @return array{0: string, 1: string} the authorization code and its PKCE verifier
     */
    private function consentIn(KernelBrowser $browser, string $clientId, ?string $resource = self::ORIGIN.'/mcp', ?\Closure $checkPage = null, string $redirectUri = self::CALLBACK): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'state' => 'state-123',
            'code_challenge' => self::challenge($verifier), 'code_challenge_method' => 'S256',
        ] + (null === $resource ? [] : ['resource' => $resource])));
        self::assertSame(302, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $consent = $browser->request('GET', self::absolute((string) $browser->getResponse()->headers->get('Location')));
        self::assertSame(200, $browser->getResponse()->getStatusCode(), 'The person sees the consent page first.');
        $page = $consent->filter('.approval-sheet')->text();
        self::assertStringContainsString('approve each exact alert with your own passkey', $page);
        ($checkPage ?? static fn (string $page) => self::assertStringContainsString('It asks to read public alerts, ask to issue public alerts as you, read the outcome of your approvals.', $page))($page);
        $browser->submit($consent->selectButton('Allow')->form());
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $browser->request('GET', self::absolute((string) $browser->getResponse()->headers->get('Location')));
        self::assertSame(302, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith($redirectUri.'?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('state-123', $query['state']);
        self::assertSame(self::ORIGIN, $query['iss'] ?? null, 'RFC 9207: the response names its issuer.');

        return [$query['code'], $verifier];
    }

    /** @return array<string, string> an authorization request with a state and PKCE challenge of its own */
    private static function authorizeQuery(string $clientId, string $redirectUri): array
    {
        return [
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'state' => bin2hex(random_bytes(8)),
            'code_challenge' => self::challenge(bin2hex(random_bytes(32))), 'code_challenge_method' => 'S256', 'resource' => self::ORIGIN.'/mcp',
        ];
    }

    /**
     * Opens the authorization request and allows it on the consent page.
     *
     * @param array<string, string> $query
     *
     * @return string the authorization to resume
     */
    private function allow(KernelBrowser $browser, array $query): string
    {
        $browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query($query));
        self::assertSame('/oauth/consent', $browser->getResponse()->headers->get('Location'));
        $browser->submit($browser->request('GET', self::ORIGIN.'/oauth/consent')->selectButton('Allow')->form());
        self::assertSame(302, $browser->getResponse()->getStatusCode());

        return (string) $browser->getResponse()->headers->get('Location');
    }

    /**
     * Answers this browser's fetch of a client ID metadata document with this one, as its host would.
     *
     * @param array<string, mixed> $document
     */
    private function serveMetadataDocument(KernelBrowser $browser, array $document): void
    {
        $browser->disableReboot();
        $browser->getKernel()->boot();
        $browser->getContainer()->get('test.service_container')->set(ClientMetadataHttpClient::class, new ClientMetadataHttpClient(new MockHttpClient(new MockResponse(json_encode($document, \JSON_THROW_ON_ERROR)))));
    }

    private function assertNothingConnected(): void
    {
        $database = $this->database();
        self::assertSame(0, (int) $database->pdo->query('SELECT COUNT(*) FROM oauth2_authorization_codes')->fetchColumn(), 'No code was issued.');
        self::assertSame(0, (int) $database->pdo->query('SELECT COUNT(*) FROM demo_grants WHERE token_hash IS NULL')->fetchColumn(), 'No connection was opened.');
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

    private function codeExchange(string $clientId, string $code, string $verifier, string $redirectUri = self::CALLBACK): array
    {
        return ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'client_id' => $clientId, 'code_verifier' => $verifier, 'resource' => self::ORIGIN.'/mcp'];
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
            // Refused by the budget (429), or with registration off (404), it never reaches that code.
            self::assertSame(\in_array($browser->getResponse()->getStatusCode(), [404, 429], true) ? 0 : 1, $warnings, 'Only a registration that reaches the provider emits its known DCR deprecation.');
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
