<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Mcp;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Server\Stateless\InputContext;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\UrlPrompt;
use VerifiedHumanApproval\Tests\Support\CoreFixture;

final class UrlPromptTest extends TestCase
{
    public function testPromptsOnlyAConfiguredClientDeclaringUrlModeOnAModernRevision(): void
    {
        $prompt = new UrlPrompt(true, ['claude-code']);
        self::assertTrue($prompt->allows(ProtocolVersion::V2026_07_28, true, 'claude-code'));
        self::assertFalse($prompt->allows(ProtocolVersion::V2025_11_25, true, 'claude-code'), 'A handshake-era prompt would hold the call open.');
        self::assertFalse($prompt->allows(ProtocolVersion::V2026_07_28, false, 'claude-code'), 'A client that did not declare URL mode must not be asked.');
        self::assertFalse($prompt->allows(ProtocolVersion::V2026_07_28, true, 'another-client'), 'Declaring URL mode is not enough.');
        self::assertFalse($prompt->allows(ProtocolVersion::V2026_07_28, true, null));
        self::assertFalse((new UrlPrompt(false, ['*']))->allows(ProtocolVersion::V2026_07_28, true, 'claude-code'), 'Off by default.');
        self::assertTrue((new UrlPrompt(true, ['*']))->allows(ProtocolVersion::V2026_07_28, true, null));
    }

    public function testBindingCoversCallerToolAndArgumentsButNotArgumentOrder(): void
    {
        $actor = new Actor('alice', 'tenant-a', 'agent-1');
        $binding = UrlPrompt::binding($actor, 'publish', ['id' => 1, 'key' => 'k']);
        self::assertSame($binding, UrlPrompt::binding($actor, 'publish', ['key' => 'k', 'id' => 1]));
        self::assertNotSame($binding, UrlPrompt::binding(new Actor('alice', 'tenant-a', 'agent-2'), 'publish', ['id' => 1, 'key' => 'k']));
        self::assertNotSame($binding, UrlPrompt::binding(new Actor('bob', 'tenant-a', 'agent-1'), 'publish', ['id' => 1, 'key' => 'k']));
        self::assertNotSame($binding, UrlPrompt::binding($actor, 'delete', ['id' => 1, 'key' => 'k']));
        self::assertNotSame($binding, UrlPrompt::binding($actor, 'publish', ['id' => 2, 'key' => 'k']));
    }

    public function testAResumedRoundYieldsTheBoundRequestAndTheClientAnswer(): void
    {
        $binding = UrlPrompt::binding(new Actor('alice', 'tenant-a'), 'publish', ['id' => 1]);
        $state = ['format' => 'verified-human-approval/continuation/v1', 'requestId' => 'apr_1', 'binding' => $binding];
        self::assertNull(UrlPrompt::resumed(null, $binding));
        self::assertSame(['requestId' => 'apr_1', 'answer' => 'none'], UrlPrompt::resumed(new InputContext(['other' => ['action' => 'accept']], $state), $binding), 'A verified state of ours names the request even when our answer is missing; it only reads.');
        self::assertNull(UrlPrompt::resumed(new InputContext(['other' => ['action' => 'accept']], ['format' => 'another/flow/v1']), $binding), 'Another flow’s state is not ours.');
        foreach (['accept', 'decline', 'cancel'] as $action) {
            self::assertSame(['requestId' => 'apr_1', 'answer' => $action], UrlPrompt::resumed(new InputContext([UrlPrompt::KEY => ['action' => $action]], $state), $binding));
        }
    }

    public function testAContinuationForOtherArgumentsOrAnotherFlowIsRefused(): void
    {
        $binding = UrlPrompt::binding(new Actor('alice', 'tenant-a'), 'publish', ['id' => 1]);
        $answer = [UrlPrompt::KEY => ['action' => 'accept']];
        $changed = ['format' => 'verified-human-approval/continuation/v1', 'requestId' => 'apr_1', 'binding' => UrlPrompt::binding(new Actor('alice', 'tenant-a'), 'publish', ['id' => 2])];
        $this->assertReason('continuation_mismatch', static fn () => UrlPrompt::resumed(new InputContext($answer, $changed), $binding));
        $this->assertReason('continuation_invalid', static fn () => UrlPrompt::resumed(new InputContext($answer, ['id' => 'apr_1']), $binding));
    }

    public function testTheModelIsNeverToldThatAClientAnswerApprovedAnything(): void
    {
        $fixture = new CoreFixture();
        $pending = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1]);
        self::assertStringContainsString('not an approval', UrlPrompt::note('accept', $pending, 'approval_status'));
        self::assertStringContainsString('approval_status', UrlPrompt::note('accept', $pending, 'approval_status'));
        foreach (['decline', 'cancel', 'none'] as $answer) {
            self::assertStringContainsString('nothing was approved or changed', UrlPrompt::note($answer, $pending, 'approval_status'));
        }
        $cancelled = $fixture->service->cancel($fixture->reviewer, $pending->request->id);
        self::assertStringContainsString('did not change anything', UrlPrompt::note('accept', $cancelled, 'approval_status'));
        $message = UrlPrompt::message($pending, 'http://localhost:8080/approval/'.$pending->request->id);
        self::assertStringContainsString('localhost:8080', $message);
        self::assertStringContainsString($pending->request->statement(), $message);
    }

    public function testTheCardLinkMatchesTheSdkSerializationAndTheCardNeverRunsAPasskey(): void
    {
        self::assertSame(['ui' => (new UiToolMeta(resourceUri: ApprovalCard::URI))->jsonSerialize()], ApprovalCard::TOOL_META);
        $resource = ApprovalCard::resource()->jsonSerialize();
        self::assertSame(McpApps::MIME_TYPE, $resource['mimeType']);
        self::assertSame(ApprovalCard::URI, $resource['uri']);
        self::assertStringContainsString('ui/open-link', $resource['text']);
        self::assertStringNotContainsString('navigator.credentials', $resource['text']);

        $fixture = new CoreFixture();
        $pending = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1]);
        $view = ApprovalCard::view($pending, 'https://example.test/approval/x', 'approval_status');
        self::assertSame(['requestId', 'status', 'reason', 'reviewUrl', 'statusTool', 'statement', 'handoffTool'], array_keys($view));
        self::assertSame('pending', $view['status']);
    }

    private function assertReason(string $reason, callable $attempt): void
    {
        try {
            $attempt();
            self::fail('Expected '.$reason);
        } catch (ApprovalException $error) {
            self::assertSame($reason, $error->reason);
        }
    }
}
