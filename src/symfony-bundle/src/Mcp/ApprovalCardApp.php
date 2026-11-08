<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Mcp;

use Mcp\Schema\Content\TextResourceContents;
use Symfony\AI\McpBundle\Attribute\AsMcpApp;
use VerifiedHumanApproval\Mcp\ApprovalCard;

/**
 * Serves the shared approval card as an MCP App resource and enables the MCP Apps extension.
 *
 * Add this class to the server's `registry.apps` list. A protected tool links to the card with
 * `meta: ApprovalCard::TOOL_META`; the card's own phone-code tool is {@see ApprovalHandoffTool},
 * an ordinary tool whose `_meta.ui` links it here with app-only visibility. This class has no
 * tool method of its own, so the bundle registers it as a static, tool-less app.
 */
#[AsMcpApp(uri: ApprovalCard::URI, title: 'Approval')]
final readonly class ApprovalCardApp
{
    public function __invoke(): TextResourceContents
    {
        return ApprovalCard::resource();
    }
}
