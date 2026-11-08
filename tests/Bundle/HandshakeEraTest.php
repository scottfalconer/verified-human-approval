<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Apps\McpApps;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\DemoSetup;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Tests\Support\RecordingHttpClient;

/**
 * The handshake era (MCP 2025-11-25) is what Claude Desktop and claude.ai speak: an initialize
 * handshake, a session the server keeps, and no multi round-trip. The SDK client defaults to
 * 2026-07-28 against this server, so this path needs its own test.
 */
final class HandshakeEraTest extends TestCase
{
    private const ORIGIN = 'http://localhost:8080';
    private string $runtime;
    private array $accounts;
    private DemoDatabase $database;
    /** @var list<Client> */ private array $clients = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/verified-human-approval-handshake-test-'.bin2hex(random_bytes(8));
        $this->accounts = DemoSetup::initialize($this->runtime, self::ORIGIN)['accounts'];
        $this->database = new DemoDatabase(DemoDatabase::connect($this->runtime.'/application.sqlite'));
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->disconnect();
        }
        (new Filesystem())->remove($this->runtime);
    }

    public function testAHandshakeEraClientGetsAPlainPendingResultTheCardAndTheStatusNeverAnElicitation(): void
    {
        // Named like Claude Code and declaring URL-mode elicitation: on this revision it must still get the plain result.
        $wire = new RecordingHttpClient(new SymfonyKernelHttpClient($this->runtime));
        $client = $this->connect($wire, 'claude-code', new ClientCapabilities(elicitationUrl: true));

        self::assertCount(1, $wire->of('initialize'), 'The handshake era starts with initialize.');
        $sessions = array_values(array_unique(array_filter(array_column(\array_slice($wire->exchanges, 1), 'session'))));
        self::assertCount(1, $sessions, 'Every request after initialize carries the one session the server minted.');
        self::assertSame(['2025-11-25'], array_values(array_unique(array_column(\array_slice($wire->exchanges, 1), 'protocol'))));
        self::assertGreaterThan(0, \count(glob($this->runtime.'/mcp/*') ?: []), 'The session lives in the file store, where the session lock also sits.');

        $pending = $client->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertFalse($pending->isError, json_encode($pending->content));
        self::assertSame('pending', $pending->structuredContent['status']);
        self::assertStringStartsWith(self::ORIGIN.'/approval/', $pending->structuredContent['review_url']);
        self::assertSame('approval_status', $pending->structuredContent['approval']['statusTool']);
        self::assertSame(0, $this->mutationCount());

        $call = $wire->of('tools/call');
        self::assertCount(1, $call);
        $messages = RecordingHttpClient::messages($call[0]);
        self::assertCount(1, $messages, 'The answer to tools/call is one message: the result.');
        self::assertArrayHasKey('result', $messages[0]);
        self::assertStringNotContainsString('elicitation/create', $call[0]['body'], 'The SDK shim never turned the pending result into a blocking elicitation.');
        self::assertStringNotContainsString('input_required', $call[0]['body']);

        $card = json_decode(json_encode($client->readResource(ApprovalCard::URI)->contents[0], \JSON_THROW_ON_ERROR), true);
        self::assertSame(McpApps::MIME_TYPE, $card['mimeType'], 'Claude Desktop reads the card over this era.');

        $status = $client->callTool('approval_status', ['request_id' => $pending->structuredContent['requestId']]);
        self::assertSame('pending', $status->structuredContent['status']);
        self::assertSame(1, (int) $this->database->pdo->query("SELECT COUNT(*) FROM verified_human_approval_requests WHERE status = 'pending'")->fetchColumn());
        self::assertSame(0, $this->mutationCount());

        // A second client of the same person gets its own session; both read the same recorded state.
        $other = $this->connect(new RecordingHttpClient(new SymfonyKernelHttpClient($this->runtime)), 'claude-desktop', new ClientCapabilities());
        $again = $other->callTool('publish_alert', ['alert_id' => 'riverside-evacuation']);
        self::assertSame($pending->structuredContent['requestId'], $again->structuredContent['requestId'], 'A keyless retry reuses the pending proposal.');
        self::assertSame(1, (int) $this->database->pdo->query('SELECT COUNT(*) FROM verified_human_approval_requests')->fetchColumn());
    }

    private function connect(RecordingHttpClient $wire, string $name, ClientCapabilities $capabilities): Client
    {
        $factory = new Psr17Factory();
        $client = Client::builder()->setClientInfo($name, '1.0')->setProtocolVersion(ProtocolVersion::V2025_11_25)->setCapabilities($capabilities)->setMaxRetries(0)->build();
        $client->connect(new HttpTransport(
            endpoint: self::ORIGIN.'/mcp',
            headers: ['Authorization' => 'Bearer '.$this->accounts['editor']['personal_token']],
            httpClient: $wire, requestFactory: $factory, streamFactory: $factory,
        ));
        $this->clients[] = $client;

        return $client;
    }

    private function mutationCount(): int
    {
        return (int) $this->database->announcement('riverside-evacuation')['mutation_count'];
    }
}
