<?php

declare(strict_types=1);

namespace ExactApproval\Mcp;

use ExactApproval\Core\ApprovalState;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;

/**
 * The MCP App (io.modelcontextprotocol/ui) card shown beside a protected tool's result.
 *
 * The card only opens the application's own review page and shows the outcome the application
 * recorded. It never runs the passkey ceremony: the WebAuthn verifier refuses embedded and
 * cross-origin ceremonies, and MCP App sandboxes cannot request passkeys anyway.
 */
final class ApprovalCard
{
    public const URI = 'ui://exact-approval/approval-card.html';

    /** Tool `_meta` linking a protected tool to the card. A constant, so attributes can use it. */
    public const TOOL_META = ['ui' => ['resourceUri' => self::URI]];

    public static function html(): string
    {
        $html = file_get_contents(\dirname(__DIR__).'/resources/approval-card.html');
        if ($html === false) {
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
     * @return array{requestId: string, status: string, reason: ?string, reviewUrl: string, statusTool: string, statement: string}
     */
    public static function view(ApprovalState $state, string $reviewUrl, string $statusTool): array
    {
        return [
            'requestId' => $state->request->id,
            'status' => $state->status,
            'reason' => $state->reason,
            'reviewUrl' => $reviewUrl,
            'statusTool' => $statusTool,
            'statement' => $state->request->statement(),
        ];
    }
}
