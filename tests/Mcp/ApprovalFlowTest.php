<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Mcp;

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Implementation;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Stateless\InputContext;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Stateless\RequestStateCodec;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\ApprovalFlow;
use VerifiedHumanApproval\Mcp\ResultPresenter;
use VerifiedHumanApproval\Mcp\UrlPrompt;
use VerifiedHumanApproval\Tests\Support\CoreFixture;

/** ApprovalFlow::respond() as a protected tool calls it, over the core fixtures' approval service. */
final class ApprovalFlowTest extends TestCase
{
    private CoreFixture $fixture;
    private RequestStateCodec $codec;

    protected function setUp(): void
    {
        $this->fixture = new CoreFixture();
        $this->codec = new RequestStateCodec(str_repeat('k', 32));
    }

    public function testADecimalArgumentIsAskedForWithOrWithoutTheUrlPrompt(): void
    {
        // "Refund 12.50": the action's input takes the amount as a string, the tool's arguments as the client sent it.
        $arguments = ['id' => 1, 'amount' => 12.5];

        $plain = $this->flow(new UrlPrompt())->respond($this->context($arguments), $this->fixture->actor, 'refund', $arguments, $this->request(...));
        self::assertInstanceOf(CallToolResult::class, $plain);
        self::assertSame('pending', $plain->structuredContent['status'] ?? null);

        $flow = $this->flow(new UrlPrompt(true, ['*']));
        $asked = $flow->respond($this->context($arguments), $this->fixture->actor, 'refund', $arguments, $this->request(...));
        self::assertInstanceOf(InputRequiredResult::class, $asked);
        self::assertIsString($asked->requestState);
        $retried = $flow->respond($this->context($arguments, $this->answered($asked)), $this->fixture->actor, 'refund', $arguments, self::readOnly(...));
        self::assertInstanceOf(CallToolResult::class, $retried);
        self::assertSame($plain->structuredContent['requestId'] ?? null, $retried->structuredContent['requestId'] ?? null, 'One proposal, asked for three times.');

        $changed = ['id' => 1, 'amount' => 12.51];
        try {
            $flow->respond($this->context($changed, $this->answered($asked)), $this->fixture->actor, 'refund', $changed, self::readOnly(...));
            self::fail('A retry with another amount continued the approval.');
        } catch (ApprovalException $error) {
            self::assertSame('continuation_mismatch', $error->reason);
        }
    }

    public function testARequestKeyThatNamesAFinishedRequestReturnsItsOutcomeAndSaysNothingRanNow(): void
    {
        $flow = $this->flow(new UrlPrompt());
        $ask = fn (string $key): CallToolResult|InputRequiredResult => $flow->respond($this->context(['id' => 1, 'request_key' => $key]), $this->fixture->actor, 'publish', ['id' => 1, 'request_key' => $key],
            fn (): ApprovalState => $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1], $key));

        $pending = $ask('k1');
        $id = $pending->structuredContent['requestId'] ?? null;
        self::assertIsString($id);
        $this->fixture->clock->sleep(30);
        $retry = $ask('k1');
        self::assertSame([$id, 'pending', false], [$retry->structuredContent['requestId'] ?? null, $retry->structuredContent['status'] ?? null, $retry->structuredContent['approval']['earlier'] ?? null], 'A retry while it waits is the same request, still waiting.');
        self::assertArrayNotHasKey('message', $retry->structuredContent ?? []);
        $ceremony = $this->fixture->service->begin($this->fixture->reviewer, $id);
        $this->fixture->clock->sleep(30);
        self::assertSame('completed', $this->fixture->service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony))->status);

        // A later conversation sends the same key, after the person approved and the action ran.
        $this->fixture->clock->sleep(86400);
        $later = $ask('k1');
        self::assertSame([$id, 'completed'], [$later->structuredContent['requestId'] ?? null, $later->structuredContent['status'] ?? null]);
        self::assertSame('This request key belongs to an earlier request, completed at 2026-11-08T10:01:00Z. This is the recorded outcome of that request: nothing ran now. To ask again, call without this request key.', $later->structuredContent['message'] ?? null);
        self::assertTrue($later->structuredContent['approval']['earlier'] ?? null);
        self::assertSame('2026-11-08T10:01:00Z', $later->structuredContent['approval']['completedAt'] ?? null, 'The card says when it ran.');
        self::assertSame(1, $this->fixture->effectCount());

        // A request that stopped says so too, and that it changed nothing.
        $cancelled = $ask('k2');
        $this->fixture->service->cancel($this->fixture->reviewer, (string) ($cancelled->structuredContent['requestId'] ?? ''));
        $this->fixture->clock->sleep(60);
        $again = $ask('k2');
        self::assertSame('This request key belongs to an earlier request, which is cancelled and changed nothing. Nothing ran now. To ask again, call without this request key.', $again->structuredContent['message'] ?? null);
        self::assertTrue($again->structuredContent['approval']['earlier'] ?? null);
        self::assertNull($again->structuredContent['approval']['completedAt']);
    }

    public function testAnActionARuleLetsRunAtOnceIsThisCallsOwnOutcome(): void
    {
        $this->fixture->pdo->exec("UPDATE test_policies SET required = 0 WHERE action_id = 'article.publish'");

        $done = $this->flow(new UrlPrompt())->respond($this->context(['id' => 1]), $this->fixture->actor, 'publish', ['id' => 1], $this->request(...));

        self::assertSame('completed', $done->structuredContent['status'] ?? null);
        self::assertFalse($done->structuredContent['approval']['earlier'] ?? null, 'It ran for this call.');
        self::assertArrayNotHasKey('message', $done->structuredContent ?? []);
        self::assertSame('2026-11-08T10:00:00Z', $done->structuredContent['approval']['completedAt'] ?? null);
        self::assertSame(1, $this->fixture->effectCount());
    }

    private function flow(UrlPrompt $prompt): ApprovalFlow
    {
        return new ApprovalFlow($this->fixture->service, new class implements ResultPresenter {
            public function reviewUrl(ApprovalState $state): string
            {
                return 'https://app.example/approval/'.$state->request->id;
            }

            public function toolResult(ApprovalState $state, ?string $message = null, bool $earlier = false): CallToolResult
            {
                $data = $state->toArray() + (null === $message ? [] : ['message' => $message]);
                $data['approval'] = ApprovalCard::view($state, $this->reviewUrl($state), $this->statusTool(), null, $earlier);

                return new CallToolResult([new TextContent(json_encode($data, \JSON_THROW_ON_ERROR))], structuredContent: $data);
            }

            public function statusTool(): string
            {
                return 'approval_status';
            }
        }, $prompt, $this->fixture->clock);
    }

    /** What the tool hands respond(): it requests the fixture's action, whatever its own arguments are. */
    private function request(): ApprovalState
    {
        return $this->fixture->service->request($this->fixture->actor, 'article.publish', ['id' => 1]);
    }

    private static function readOnly(): ApprovalState
    {
        self::fail('A retry of an asked round only reads; it never requests the action.');
    }

    /** A 2026-07-28 tools/call from a client that declares URL mode, carrying a continuation when given one. */
    private function context(array $arguments, ?InputContext $input = null): RequestContext
    {
        $session = new Session(new InMemorySessionStore());
        $session->set(RequestMeta::class, new RequestMeta('2026-07-28', new ClientCapabilities(elicitation: true, elicitationUrl: true), new Implementation('claude-code', '2.1.317')));
        $session->set(RequestStateCodec::class, $this->codec);
        if (null !== $input) {
            $session->set(InputContext::class, $input);
        }

        return new RequestContext($session, (new CallToolRequest('refund', $arguments))->withId(1)->withMeta([RequestMeta::PROTOCOL_VERSION => '2026-07-28']));
    }

    /** The retry of an asked round, after the person accepted the prompt: the client's answer and the verified state. */
    private function answered(InputRequiredResult $asked): InputContext
    {
        return new InputContext([UrlPrompt::KEY => ['action' => 'accept']], $this->codec->verify((string) $asked->requestState));
    }
}
