<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\Exception\LogicException;
use Symfony\Component\DependencyInjection\Exception\LogicException as ContainerLogicException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\ProposalBudget;
use VerifiedHumanApproval\Tests\Bundle\Fixture\ReviewKernel;
use VerifiedHumanApproval\Tests\Bundle\Fixture\SpentBudget;

/**
 * The bundle's MCP side as an application's own MCP server serves it: a protected tool written as the guide writes
 * one, the status tool, and the phone tool, called the way a 2026-07-28 client calls them.
 */
final class McpToolsTest extends TestCase
{
    private string $runtime;
    /** @var list<ReviewKernel> */ private array $kernels = [];

    protected function setUp(): void
    {
        $this->runtime = ReviewKernel::runtime();
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        (new Filesystem())->remove($this->runtime);
    }

    public function testAnAgentsCallsToAskForAnActionHaveABudgetAndReadingTheOutcomeIsFree(): void
    {
        $kernel = $this->kernel(['agent_requests_per_minute' => 2]);
        $first = $this->call($kernel, 'publish_article', ['article_id' => 1, 'request_key' => 'k1']);
        self::assertSame('pending', $first['structuredContent']['status'] ?? null, json_encode($first));
        self::assertSame('pending', $this->call($kernel, 'publish_article', ['article_id' => 2, 'request_key' => 'k2'])['structuredContent']['status'] ?? null);

        $refused = $this->call($kernel, 'publish_article', ['article_id' => 1, 'request_key' => 'k3']);

        self::assertTrue($refused['isError'] ?? false);
        self::assertSame(['status' => 'rejected', 'reason' => 'rate_limited', 'message' => 'Too many requests for approval from this account. Wait a minute, then ask again.'], $refused['structuredContent'] ?? null);
        self::assertSame(2, $this->requests(), 'The refused call prepared nothing.');
        for ($check = 0; $check < 3; ++$check) {
            self::assertSame('pending', $this->call($kernel, ApprovalStatusTool::NAME, ['request_id' => $first['structuredContent']['requestId']])['structuredContent']['status'] ?? null, 'The status tool reads for free.');
        }
    }

    public function testAnApplicationsOwnBudgetIsTheOneTheFlowSpendsFrom(): void
    {
        // As the guide says: the application defines its own ProposalBudget, by the interface's name.
        $kernel = $this->kernel(services: [ProposalBudget::class => SpentBudget::class]);

        $refused = $this->call($kernel, 'publish_article', ['article_id' => 1]);

        self::assertTrue($refused['isError'] ?? false);
        self::assertSame(['status' => 'rejected', 'reason' => 'rate_limited', 'message' => SpentBudget::MESSAGE], $refused['structuredContent'] ?? null);
        self::assertSame(0, $this->requests());
    }

    public function testTheCardOffersThePhoneOnlyWhereTheSiteServesIt(): void
    {
        // As the guide registers them: the status tool, the phone tool and the card.
        $on = $this->kernel();
        self::assertContains(ApprovalHandoffTool::NAME, $this->tools($on));
        $pending = $this->call($on, 'publish_article', ['article_id' => 1]);
        self::assertSame(ApprovalHandoffTool::NAME, $pending['structuredContent']['approval']['handoffTool'] ?? null);
        // The card is written for the site's own origin and tools, and calls no other whatever a result names.
        self::assertSame(['origin' => 'https://app.example', 'statusTool' => ApprovalStatusTool::NAME, 'handoffTool' => ApprovalHandoffTool::NAME], $this->card($on));

        // A site that turns the phone code off: the card is never told to call a tool the server does not have.
        $off = $this->kernel(['phone_handoff' => false], [ApprovalStatusTool::class]);
        self::assertNotContains(ApprovalHandoffTool::NAME, $this->tools($off));
        self::assertSame(['origin' => 'https://app.example', 'statusTool' => ApprovalStatusTool::NAME, 'handoffTool' => null], $this->card($off));
        foreach ([$this->call($off, 'publish_article', ['article_id' => 1]), $this->call($off, ApprovalStatusTool::NAME, ['request_id' => $pending['structuredContent']['requestId']])] as $result) {
            self::assertArrayHasKey('handoffTool', $result['structuredContent']['approval'] ?? []);
            self::assertNull($result['structuredContent']['approval']['handoffTool']);
        }
        self::assertSame(410, $off->handle(Request::create('https://app.example/approval/handoff/'.str_repeat('Q', 43), server: ['HTTPS' => 'on']))->getStatusCode(), 'No code link opens a review.');

        // The switch and the tool go together: a server that lists the tool the site turned off does not build.
        $listed = new ReviewKernel($this->runtime, ['phone_handoff' => false], tools: [ApprovalStatusTool::class, ApprovalHandoffTool::class]);
        try {
            $listed->boot();
            self::fail('The server listed a phone tool the site turned off.');
        } catch (LogicException $error) {
            self::assertStringContainsString(ApprovalHandoffTool::class, $error->getMessage());
        } finally {
            $listed->shutdown();
        }
    }

    public function testWhileThePhoneCodeIsOnEachServerThatServesTheCardServesThePhoneTool(): void
    {
        // On, as it is by default, and the server lists the status tool and the card but leaves the phone tool out.
        self::assertSame(
            'The MCP server "application" serves the approval card without '.ApprovalHandoffTool::class.', which the card calls for "Approve on your phone". List the tool under mcp.servers.application.registry.tools, or set verified_human_approval.phone_handoff to false.',
            $this->refusal(new ReviewKernel($this->runtime, tools: [ApprovalStatusTool::class])),
        );

        // Beside a server that has it, the servers that do not are named.
        $several = new ReviewKernel($this->runtime, tools: [ApprovalStatusTool::class, ApprovalHandoffTool::class], servers: [
            'editors' => [ApprovalStatusTool::class], 'phones' => [ApprovalStatusTool::class, ApprovalHandoffTool::class], 'readers' => [ApprovalStatusTool::class],
        ]);
        self::assertSame(
            'The MCP servers "editors", "readers" serve the approval card without '.ApprovalHandoffTool::class.', which the card calls for "Approve on your phone". List the tool under mcp.servers.editors.registry.tools, mcp.servers.readers.registry.tools, or set verified_human_approval.phone_handoff to false.',
            $this->refusal($several),
        );

        // Listed beside the card on each, it is served by each.
        $each = $this->kernel(servers: ['editors' => [ApprovalStatusTool::class, ApprovalHandoffTool::class]]);
        self::assertContains(ApprovalHandoffTool::NAME, $this->tools($each, '/mcp/editors'));
        self::assertSame(ApprovalHandoffTool::NAME, $this->card($each, '/mcp/editors')['handoffTool']);
    }

    /** Why the kernel's container does not build. */
    private function refusal(ReviewKernel $kernel): string
    {
        try {
            $kernel->boot();
        } catch (ContainerLogicException $error) {
            return $error->getMessage();
        } finally {
            $kernel->shutdown();
        }
        self::fail('The container built.');
    }

    /** @return list<string> the names of the tools the server at this path lists */
    private function tools(ReviewKernel $kernel, string $path = '/mcp'): array
    {
        return array_column($this->send($kernel, 'tools/list', path: $path)['tools'] ?? [], 'name');
    }

    /**
     * @param array<string, mixed>              $approval
     * @param array<string, class-string>       $services
     * @param array<string, list<class-string>> $servers
     */
    private function kernel(array $approval = [], ?array $tools = null, array $services = [], array $servers = []): ReviewKernel
    {
        $kernel = new ReviewKernel($this->runtime, $approval, tools: $tools ?? [ApprovalStatusTool::class, ApprovalHandoffTool::class], services: $services, servers: $servers);
        $kernel->boot();

        return $this->kernels[] = $kernel;
    }

    /** @return array<string, mixed> what the card the server at this path serves is written for: its origin and its tools */
    private function card(ReviewKernel $kernel, string $path = '/mcp'): array
    {
        $card = $this->send($kernel, 'resources/read', ['uri' => ApprovalCard::URI], ApprovalCard::URI, $path)['contents'][0] ?? [];
        self::assertSame(1, preg_match('#<script type="application/json" id="card-application">(.*?)</script>#', $card['text'] ?? '', $application));

        return json_decode($application[1], true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * A tools/call (or, with no tool name, a tools/list) as a stateless 2026-07-28 client sends it.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> the JSON-RPC result, or the error with the HTTP status under "http"
     */
    private function call(ReviewKernel $kernel, ?string $tool, array $arguments = []): array
    {
        return null === $tool ? $this->send($kernel, 'tools/list') : $this->send($kernel, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments], $tool);
    }

    /**
     * @param array<string, mixed> $params
     * @param string|null          $name   what the request names, for its Mcp-Name header
     * @param string               $path   the MCP server's endpoint
     *
     * @return array<string, mixed> the JSON-RPC result, or the error with the HTTP status under "http"
     */
    private function send(ReviewKernel $kernel, string $method, array $params = [], ?string $name = null, string $path = '/mcp'): array
    {
        $params += ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo' => ['name' => 'mcp-tools-test', 'version' => '1.0'],
        ]];
        $request = Request::create('https://app.example'.$path, 'POST', server: ['HTTPS' => 'on'], content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR));
        $request->headers->add(['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Mcp-Method' => $method, 'MCP-Protocol-Version' => '2026-07-28'] + (null === $name ? [] : ['Mcp-Name' => $name]));
        $response = $kernel->handle($request);
        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();
        if (1 === preg_match('/^data: (.+)$/m', $body, $match)) {
            $body = $match[1];
        }
        $message = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($message, $body);

        return $message['result'] ?? ['http' => $response->getStatusCode(), 'error' => $message['error'] ?? null];
    }

    private function requests(): int
    {
        return (int) (new \PDO('sqlite:'.$this->runtime.'/application.sqlite'))->query('SELECT COUNT(*) FROM verified_human_approval_requests')->fetchColumn();
    }
}
