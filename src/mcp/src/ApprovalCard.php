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

    /** The element the template keeps for the application's origin and tools. Left as `{}`, the card opens and calls nothing. */
    private const APPLICATION = '<script type="application/json" id="card-application">%s</script>';

    /**
     * The card for one application. It opens pages on that origin only, and calls only those tools, whatever a
     * result names: a result can leave out the phone tool, never name another.
     *
     * @param string      $origin      the review pages' origin, as {@see isOrigin()} takes it
     * @param string      $statusTool  the read-only tool the card polls, called with `request_id`
     * @param string|null $handoffTool the app-only tool that returns a phone code, or null where there is none
     */
    public static function html(string $origin, string $statusTool, ?string $handoffTool = null): string
    {
        if (!self::isOrigin($origin)) {
            throw new \InvalidArgumentException('The approval card needs the review pages\' origin, on HTTPS or on HTTP localhost.');
        }
        $template = file_get_contents(\dirname(__DIR__).'/resources/approval-card.html');
        if (false === $template) {
            throw new \RuntimeException('The approval card template is missing.');
        }
        // Hex-escaped, so no value can close the script element it is written into.
        $application = json_encode(['origin' => $origin, 'statusTool' => $statusTool, 'handoffTool' => $handoffTool], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP);
        $html = str_replace(\sprintf(self::APPLICATION, '{}'), \sprintf(self::APPLICATION, $application), $template, $count);
        if (1 !== $count) {
            throw new \RuntimeException('The approval card template has no place for the application.');
        }

        return $html;
    }

    /** The card for one application as an MCP App resource, written as {@see html()} writes it. */
    public static function resource(string $origin, string $statusTool, ?string $handoffTool = null): TextResourceContents
    {
        return new TextResourceContents(self::URI, McpApps::MIME_TYPE, self::html($origin, $statusTool, $handoffTool), [
            'ui' => (new UiResourceContentMeta(prefersBorder: true))->jsonSerialize(),
        ]);
    }

    /** Whether the card can open pages on this origin: HTTPS, or HTTP localhost for development, with no path. */
    public static function isOrigin(string $origin): bool
    {
        $parts = parse_url($origin);

        return \is_array($parts) && isset($parts['scheme'], $parts['host']) && [] === array_diff(array_keys($parts), ['scheme', 'host', 'port', 'path'])
            && \in_array($parts['path'] ?? '', ['', '/'], true) && ('https' === $parts['scheme'] || ('http' === $parts['scheme'] && 'localhost' === $parts['host']));
    }

    /**
     * The fields the card reads, under fixed names whatever the platform's own result calls them.
     *
     * @param bool $earlier whether this tool call's request key named an earlier request that had finished, so nothing ran for this call
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
