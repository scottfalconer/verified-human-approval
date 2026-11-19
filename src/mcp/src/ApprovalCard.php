<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Mcp;

use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use VerifiedHumanApproval\Core\ApprovalState;

/**
 * The MCP App (io.modelcontextprotocol/ui) card shown beside a protected tool's result.
 *
 * The card only opens the application's own review page and shows the outcome the application
 * recorded. It never runs the passkey ceremony: the WebAuthn verifier refuses embedded and
 * cross-origin ceremonies, and the card runs on the host's sandbox origin, so no assertion it could
 * obtain would pass the application's exact-origin check.
 */
final class ApprovalCard
{
    public const URI = 'ui://verified-human-approval/approval-card.html';

    /** Tool `_meta` linking a protected tool to the card. A constant, so attributes can use it. */
    public const TOOL_META = ['ui' => ['resourceUri' => self::URI]];

    public static function html(): string
    {
        $html = file_get_contents(\dirname(__DIR__).'/resources/approval-card.html');
        if (false === $html) {
            throw new \RuntimeException('The approval card template is missing.');
        }

        return $html;
    }

    public static function resource(): TextResourceContents
    {
        return new TextResourceContents(self::URI, McpApps::MIME_TYPE, self::html(), [
            'ui' => (new UiResourceContentMeta(prefersBorder: true))->jsonSerialize(),
        ]);
    }

    /**
     * The fields the card reads, under fixed names whatever the platform's own result calls them.
     *
     * @param bool $earlier whether this tool call's request key named an earlier request, so nothing ran for this call
     *
     * @return array{requestId: string, status: string, reason: ?string, approvedWithPasskey: bool, completedAt: ?string, earlier: bool, reviewUrl: string, statusTool: string, statement: string, handoffTool: ?string}
     */
    public static function view(ApprovalState $state, string $reviewUrl, string $statusTool, ?string $handoffTool = null, bool $earlier = false): array
    {
        return [
            'requestId' => $state->request->id,
            'status' => $state->status,
            'reason' => $state->reason,
            // A person approved it with a passkey only if the application recorded that proof.
            'approvedWithPasskey' => null !== $state->proof,
            'completedAt' => self::completedAt($state),
            'earlier' => $earlier,
            'reviewUrl' => $reviewUrl,
            'statusTool' => $statusTool,
            'statement' => $state->request->statement(),
            'handoffTool' => $handoffTool,
        ];
    }

    /** When the action ran, as recorded with it: RFC 3339 in UTC, to the second. Null until it ran. */
    public static function completedAt(ApprovalState $state): ?string
    {
        $recorded = 'completed' === $state->status ? ($state->execution['recordedAt'] ?? null) : null;
        $time = \is_string($recorded) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $recorded, new \DateTimeZone('UTC')) : false;

        return false === $time ? null : $time->format('Y-m-d\TH:i:s\Z');
    }
}
