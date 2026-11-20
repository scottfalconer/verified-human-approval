<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;
use VerifiedHumanApproval\Bundle\Security\McpScopeChallengeSubscriber;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;

/** The scope challenge over HTTP, through the demo's kernel and firewall, the bundle's subscriber and the MCP SDK. */
final class ScopeAuthorizationTest extends TestCase
{
    private const ORIGIN = 'https://alerts.example';
    private const ALERT = ['alert_id' => 'riverside-evacuation'];
    private string $runtime;
    private array $accounts;
    private DemoDatabase $database;

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/verified-human-approval-scope-test-'.bin2hex(random_bytes(8));
        $this->accounts = DemoSetup::initialize($this->runtime, self::ORIGIN)['accounts'];
        $this->database = new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->runtime);
        // The demo trusts its own host only, and Symfony keeps that for the whole process.
        Request::setTrustedHosts([]);
    }

    public static function missingScopes(): iterable
    {
        yield 'read alert' => ['read_alert', self::ALERT, ['approval.read'], 'alert.read'];
        yield 'publish missing read' => ['publish_alert', self::ALERT, ['alert.read', 'alert.publish'], 'alert.publish approval.read'];
        yield 'publish missing publish' => ['publish_alert', self::ALERT, ['approval.read'], 'alert.publish approval.read'];
        yield 'publish missing both' => ['publish_alert', self::ALERT, [], 'alert.publish approval.read'];
        yield 'approval status' => ['approval_status', ['request_id' => 'unavailable'], ['alert.read'], 'approval.read'];
        yield 'phone code' => ['approval_handoff', ['request_id' => 'unavailable'], ['alert.read', 'alert.publish'], 'approval.read'];
    }

    #[DataProvider('missingScopes')]
    public function testMissingScopeIsOneHttpChallengeForAllScopesTheOperationNeeds(string $tool, array $arguments, array $scopes, string $required): void
    {
        $this->scopes($scopes);
        $response = $this->call($tool, $arguments);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'Bearer resource_metadata="'.self::ORIGIN.'/.well-known/oauth-protected-resource/mcp", scope="'.$required.'", error="insufficient_scope", error_description="The access token lacks a required scope."',
            $response->getHeaderLine('WWW-Authenticate'),
            'The challenge names the scopes this operation needs, all of them, and not every scope the server supports.',
        );
        self::assertSame(
            ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'The access token lacks a required scope. This operation needs the scope "'.$required.'".']],
            self::data($response),
            'A client that cannot ask for more scope still learns which the call needs.',
        );
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('Mcp-Session-Id'), 'The MCP server never saw the request.');
        self::assertSame(0, $this->requestCount());
        self::assertSame(0, (int) $this->database->alert('riverside-evacuation')['mutation_count']);
    }

    public static function misleadingHeaders(): iterable
    {
        yield 'other tool name' => [['Mcp-Name' => 'read_alert']];
        yield 'other method' => [['Mcp-Method' => 'tools/list']];
        yield 'name omitted' => [['Mcp-Name' => null]];
        yield 'contradictory protocol' => [['MCP-Protocol-Version' => '2025-11-25']];
    }

    #[DataProvider('misleadingHeaders')]
    public function testAHeaderNeverSelectsAnotherToolsScopes(array $headers): void
    {
        // The token may read alerts. The headers say so; the body asks to publish one.
        $this->scopes(['alert.read']);
        $response = $this->call('publish_alert', self::ALERT, $headers);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('scope="alert.publish approval.read"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(0, $this->requestCount());
    }

    #[DataProvider('misleadingHeaders')]
    public function testAMalformedRequestWithEnoughScopeIsTheSdksRejectionNotAChallenge(array $headers): void
    {
        $response = $this->call('publish_alert', self::ALERT, $headers);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertArrayHasKey('error', self::data($response));
        self::assertFalse($response->hasHeader('WWW-Authenticate'), 'A malformed MCP request is not a request for more scope.');
        self::assertSame(0, $this->requestCount());
    }

    public function testHandshakeEraScopesComeFromTheBodyEvenWithAMisleadingNameHeader(): void
    {
        $this->scopes(['alert.read']);
        $response = $this->call('publish_alert', self::ALERT, ['Mcp-Name' => 'read_alert'], modern: false);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('scope="alert.publish approval.read"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(0, $this->requestCount());
    }

    public function testABatchNeedsEveryScopeOfEveryCallInIt(): void
    {
        // A call the token may make cannot carry one it may not.
        $this->scopes(['alert.read']);
        $response = $this->send([self::message('read_alert', self::ALERT, 1), self::message('publish_alert', self::ALERT, 2)], ['Mcp-Name' => 'read_alert']);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('scope="alert.read alert.publish approval.read"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(0, $this->requestCount());
    }

    public function testListingToolsNeedsNoScope(): void
    {
        $this->scopes([]);
        $response = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => self::modernMeta()]], ['Mcp-Method' => 'tools/list']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertFalse($response->hasHeader('WWW-Authenticate'));
        self::assertContains('publish_alert', array_column(self::data($response)['result']['tools'], 'name'), 'A connection can see which tools exist; calling one is what needs the scope.');
    }

    public function testCorrectScopesAllowTheOperationAndAnUpgradeCanRetryTheRefusedRequest(): void
    {
        $this->scopes(['approval.read']);
        self::assertSame(403, $this->call('read_alert', self::ALERT)->getStatusCode());
        $this->scopes(['alert.read']);
        $read = $this->call('read_alert', self::ALERT);
        self::assertSame(200, $read->getStatusCode());
        self::assertFalse($read->hasHeader('WWW-Authenticate'));
        self::assertSame('riverside-evacuation', self::data($read)['result']['structuredContent']['id']);

        // Publication needs these two scopes, not the unrelated alert.read scope.
        $this->scopes(['alert.publish', 'approval.read']);
        $publish = $this->call('publish_alert', self::ALERT);
        self::assertSame(200, $publish->getStatusCode(), (string) $publish->getBody());
        $pending = self::data($publish)['result']['structuredContent'];
        self::assertSame('pending', $pending['status']);
        $this->scopes(['approval.read']);
        $status = $this->call('approval_status', ['request_id' => $pending['requestId']]);
        self::assertSame(200, $status->getStatusCode());
        self::assertSame('pending', self::data($status)['result']['structuredContent']['status']);
        $handoff = $this->call('approval_handoff', ['request_id' => $pending['requestId']]);
        self::assertSame(200, $handoff->getStatusCode());
        self::assertFalse(self::data($handoff)['result']['isError'] ?? false);
        self::assertSame(0, (int) $this->database->alert('riverside-evacuation')['mutation_count']);
    }

    public function testOwnershipAndApplicationPermissionDenialsRemainToolResults(): void
    {
        $pending = self::data($this->call('publish_alert', self::ALERT))['result']['structuredContent'];
        $response = $this->call('approval_status', ['request_id' => $pending['requestId']], user: 'reader');
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(self::data($response)['result']['isError']);
        self::assertFalse($response->hasHeader('WWW-Authenticate'));

        // A broad token does not grant the account an application permission it does not have.
        $this->scopes(['alert.read', 'alert.publish', 'approval.read'], 'reader');
        $response = $this->call('publish_alert', self::ALERT, user: 'reader');
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(self::data($response)['result']['isError']);
        self::assertSame('permission_denied', self::data($response)['result']['structuredContent']['reason']);
        self::assertFalse($response->hasHeader('WWW-Authenticate'), 'More scope would not help, so nothing asks the client to get more.');
        self::assertSame(0, (int) $this->database->alert('riverside-evacuation')['mutation_count']);
    }

    public function testWhereNothingChallengesTheToolsAndTheAuthorizerStillRefuse(): void
    {
        // Over stdio, or in an application that never registered the subscriber, these checks are the only ones.
        $pending = self::data($this->call('publish_alert', self::ALERT))['result']['structuredContent'];
        self::assertSame('pending', $pending['status']);
        $unchallenged = function (string $tool, array $arguments, array $scopes): array {
            $this->scopes($scopes);
            $response = $this->call($tool, $arguments, challenged: false);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertFalse($response->hasHeader('WWW-Authenticate'), 'Without the subscriber the refusal is a tool result.');
            $result = self::data($response)['result'];
            self::assertTrue($result['isError'], $tool.' must refuse a connection without its scopes.');

            return $result;
        };

        $read = $unchallenged('read_alert', self::ALERT, ['alert.publish', 'approval.read']);
        self::assertSame('Your personal connection does not allow reading alerts.', $read['content'][0]['text']);
        self::assertArrayNotHasKey('structuredContent', $read, 'No alert is returned with the refusal.');

        // A retry of the pending request would hand its result to a connection that may not read results.
        $retry = $unchallenged('publish_alert', self::ALERT, ['alert.read', 'alert.publish']);
        self::assertSame('result_read_denied', $retry['structuredContent']['reason']);
        self::assertArrayNotHasKey('result', $retry['structuredContent']);
        // The tool checks the validated token independently of the authorizer's stored grant check.
        self::assertSame('scope_denied', $unchallenged('publish_alert', self::ALERT, ['alert.read', 'approval.read'])['structuredContent']['reason']);

        $status = $unchallenged('approval_status', ['request_id' => $pending['requestId']], ['alert.read', 'alert.publish']);
        self::assertArrayNotHasKey('structuredContent', $status, 'The status of the request stays with connections that may read it.');
        $handoff = $unchallenged('approval_handoff', ['request_id' => $pending['requestId']], ['alert.read', 'alert.publish']);
        self::assertSame('This connection is not allowed to read this approval.', $handoff['content'][0]['text']);
        self::assertArrayNotHasKey('structuredContent', $handoff, 'No sign-in code is issued.');

        self::assertSame(1, $this->requestCount(), 'No refused call asked for anything new.');
        self::assertSame(0, (int) $this->database->alert('riverside-evacuation')['mutation_count']);
    }

    public function testATokenTheApplicationDoesNotKnowIsToldSoAndAMissingOneIsNot(): void
    {
        $response = $this->call('publish_alert', self::ALERT, ['Authorization' => 'Bearer '.str_repeat('x', 43)]);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            'Bearer resource_metadata="'.self::ORIGIN.'/.well-known/oauth-protected-resource/mcp", scope="alert.read alert.publish approval.read", error="invalid_token"',
            $response->getHeaderLine('WWW-Authenticate'),
            'A client holding a token that stopped working learns it needs a new one (RFC 6750 §3.1).',
        );

        foreach ([null, 'Basic '.base64_encode('anyone:anything')] as $authorization) {
            $response = $this->call('publish_alert', self::ALERT, ['Authorization' => $authorization]);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame(
                'Bearer resource_metadata="'.self::ORIGIN.'/.well-known/oauth-protected-resource/mcp", scope="alert.read alert.publish approval.read"',
                $response->getHeaderLine('WWW-Authenticate'),
                'A request that presented no bearer token is not told one was invalid.',
            );
        }
        self::assertSame(0, $this->requestCount());
    }

    private function scopes(array $scopes, string $user = 'editor'): void
    {
        $this->database->pdo->prepare('UPDATE demo_grants SET scopes = ? WHERE id = ?')
            ->execute([json_encode($scopes, \JSON_THROW_ON_ERROR), $user.'-personal']);
    }

    private function requestCount(): int
    {
        return (int) $this->database->pdo->query('SELECT COUNT(*) FROM verified_human_approval_requests')->fetchColumn();
    }

    private function call(string $tool, array $arguments, array $headers = [], bool $modern = true, string $user = 'editor', bool $challenged = true): ResponseInterface
    {
        return $this->send(self::message($tool, $arguments, 1, $modern), $headers + ['Mcp-Name' => $tool], $modern, $user, $challenged);
    }

    private static function message(string $tool, array $arguments, int $id, bool $modern = true): array
    {
        $params = ['name' => $tool, 'arguments' => (object) $arguments];
        if ($modern) {
            $params['_meta'] = self::modernMeta();
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => $params];
    }

    private static function modernMeta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo' => ['name' => 'scope-test', 'version' => '1'],
        ];
    }

    /** @param array<string, string|null> $headers a null value leaves the header out */
    private function send(array $payload, array $headers = [], bool $modern = true, string $user = 'editor', bool $challenged = true): ResponseInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createRequest('POST', self::ORIGIN.'/mcp')->withBody($factory->createStream(json_encode($payload, \JSON_THROW_ON_ERROR)));
        foreach ($headers + [
            'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$this->accounts[$user]['personal_token'],
            'Mcp-Method' => 'tools/call', 'MCP-Protocol-Version' => $modern ? '2026-07-28' : '2025-11-25',
        ] as $name => $value) {
            if (null !== $value) {
                $request = $request->withHeader($name, $value);
            }
        }

        return (new SymfonyKernelHttpClient($this->runtime, $challenged ? null : self::withoutTheChallenge(...)))->sendRequest($request);
    }

    /** Takes the bundle's subscriber out of a booted kernel, leaving the application as one that never registered it. */
    private static function withoutTheChallenge(DemoKernel $kernel): void
    {
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        foreach ($dispatcher->getListeners(KernelEvents::CONTROLLER) as $listener) {
            if (\is_array($listener) && $listener[0] instanceof McpScopeChallengeSubscriber) {
                $dispatcher->removeListener(KernelEvents::CONTROLLER, $listener);
            }
        }
    }

    private static function data(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if (1 === preg_match('/^data: (.+)$/m', $body, $match)) {
            $body = $match[1];
        }

        return json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
    }
}
