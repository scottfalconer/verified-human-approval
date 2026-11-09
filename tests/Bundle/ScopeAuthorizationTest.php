<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoSetup;

/** Exercise the HTTP contract through the real kernel, firewall, OAuth listener and MCP SDK. */
final class ScopeAuthorizationTest extends TestCase
{
    private const ORIGIN = 'https://alerts.example';
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
    }

    public static function missingScopes(): iterable
    {
        yield 'read alert' => ['read_alert', ['alert_id' => 'riverside-evacuation'], ['approval.read'], 'alert.read'];
        yield 'publish missing read' => ['publish_alert', ['alert_id' => 'riverside-evacuation'], ['alert.read', 'alert.publish'], 'alert.publish approval.read'];
        yield 'publish missing publish' => ['publish_alert', ['alert_id' => 'riverside-evacuation'], ['approval.read'], 'alert.publish approval.read'];
        yield 'publish missing both' => ['publish_alert', ['alert_id' => 'riverside-evacuation'], [], 'alert.publish approval.read'];
        yield 'approval status' => ['approval_status', ['request_id' => 'unavailable'], ['alert.read'], 'approval.read'];
        yield 'phone code' => ['approval_handoff', ['request_id' => 'unavailable'], ['alert.read', 'alert.publish'], 'approval.read'];
    }

    #[DataProvider('missingScopes')]
    public function testMissingScopeIsOneHttpChallengeForAllScopesTheOperationNeeds(string $tool, array $arguments, array $scopes, string $required): void
    {
        $this->scopes($scopes);
        $response = $this->call($tool, $arguments);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('insufficient_scope', self::data($response)['error']);
        self::assertSame(
            'Bearer resource_metadata="'.self::ORIGIN.'/.well-known/oauth-protected-resource/mcp", scope="'.$required.'", error="insufficient_scope"',
            $response->getHeaderLine('WWW-Authenticate'),
            'The generic OAuth listener must not replace the operation scope set with all supported scopes.',
        );
        self::assertFalse($response->hasHeader('Mcp-Session-Id'));
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
    public function testMisleadingModernHeadersReachTheSdkRejectionWithoutSelectingAnotherToolsScopes(array $headers): void
    {
        $this->scopes(['alert.read']);
        $response = $this->call('publish_alert', ['alert_id' => 'riverside-evacuation'], $headers);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertArrayHasKey('error', self::data($response));
        self::assertFalse($response->hasHeader('WWW-Authenticate'), 'A malformed MCP request is not a scope-upgrade request.');
        self::assertSame(0, $this->requestCount());
    }

    public function testHandshakeEraScopesComeFromTheBodyEvenWithAMisleadingNameHeader(): void
    {
        $this->scopes(['alert.read']);
        $response = $this->call('publish_alert', ['alert_id' => 'riverside-evacuation'], ['Mcp-Name' => 'read_alert'], modern: false);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('scope="alert.publish approval.read"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(0, $this->requestCount());
    }

    public function testCorrectScopesAllowTheOperationAndAnUpgradeCanRetryTheRefusedRequest(): void
    {
        $this->scopes(['approval.read']);
        self::assertSame(403, $this->call('read_alert', ['alert_id' => 'riverside-evacuation'])->getStatusCode());
        $this->scopes(['alert.read']);
        $read = $this->call('read_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertSame(200, $read->getStatusCode());
        self::assertFalse($read->hasHeader('WWW-Authenticate'));
        self::assertSame('riverside-evacuation', self::data($read)['result']['structuredContent']['id']);

        // Publication needs these two scopes, not the unrelated alert.read scope.
        $this->scopes(['alert.publish', 'approval.read']);
        $publish = $this->call('publish_alert', ['alert_id' => 'riverside-evacuation']);
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
        $pending = self::data($this->call('publish_alert', ['alert_id' => 'riverside-evacuation']))['result']['structuredContent'];
        $response = $this->call('approval_status', ['request_id' => $pending['requestId']], user: 'reader');
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(self::data($response)['result']['isError']);
        self::assertFalse($response->hasHeader('WWW-Authenticate'));

        // A broad token does not grant the account an application permission it does not have.
        $this->scopes(['alert.read', 'alert.publish', 'approval.read'], 'reader');
        $response = $this->call('publish_alert', ['alert_id' => 'riverside-evacuation'], user: 'reader');
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(self::data($response)['result']['isError']);
        self::assertSame('permission_denied', self::data($response)['result']['structuredContent']['reason']);
        self::assertFalse($response->hasHeader('WWW-Authenticate'));
        self::assertSame(0, (int) $this->database->alert('riverside-evacuation')['mutation_count']);
    }

    public function testInvalidTokenStillUsesTheAuthenticationChallenge(): void
    {
        $response = $this->call('publish_alert', ['alert_id' => 'riverside-evacuation'], ['Authorization' => 'Bearer '.str_repeat('x', 43)]);
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
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

    private function call(string $tool, array $arguments, array $headers = [], bool $modern = true, string $user = 'editor'): ResponseInterface
    {
        $params = ['name' => $tool, 'arguments' => (object) $arguments];
        if ($modern) {
            $params['_meta'] = [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                'io.modelcontextprotocol/clientInfo' => ['name' => 'scope-test', 'version' => '1'],
            ];
        }
        $factory = new Psr17Factory();
        $request = $factory->createRequest('POST', self::ORIGIN.'/mcp')->withBody($factory->createStream(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params,
        ], \JSON_THROW_ON_ERROR)));
        foreach ($headers + [
            'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$this->accounts[$user]['personal_token'],
            'Mcp-Method' => 'tools/call', 'Mcp-Name' => $tool, 'MCP-Protocol-Version' => $modern ? '2026-07-28' : '2025-11-25',
        ] as $name => $value) {
            if (null !== $value) {
                $request = $request->withHeader($name, $value);
            }
        }

        return (new SymfonyKernelHttpClient($this->runtime))->sendRequest($request);
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
