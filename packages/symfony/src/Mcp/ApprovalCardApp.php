<?php

declare(strict_types=1);

namespace ExactApproval\Symfony\Mcp;

use ExactApproval\Mcp\ApprovalCard;
use Mcp\Schema\Content\TextResourceContents;
use Symfony\AI\McpBundle\Attribute\AsMcpApp;

/**
 * Serves the shared approval card as an MCP App resource and enables the MCP Apps extension.
 *
 * It has no tool of its own. A protected tool links to it with `meta: ApprovalCard::TOOL_META`.
 * Add this class to the server's `registry.apps` list.
 */
#[AsMcpApp(uri: ApprovalCard::URI, title: 'Approval')]
final class ApprovalCardApp
{
    public function __invoke(): TextResourceContents
    {
        return ApprovalCard::resource();
    }
}
