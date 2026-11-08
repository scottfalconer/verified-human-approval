<?php

declare(strict_types=1);

namespace Drupal\exact_approval_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Mcp\Schema\Result\CallToolResult;

#[Tool(
    id: 'exact_approval_delete',
    label: new TranslatableMarkup('Request exact deletion approval'),
    description: new TranslatableMarkup('Prepare deletion of one entire saved Drupal node, all its revisions and translations. Nothing is deleted by this request. Returns a review URL for the same user to approve with a passkey; that approval executes the frozen deletion. Evidence survives the content deletion.'),
    inputSchema: ['type' => 'object', 'properties' => [
        'node_id' => ['type' => 'integer', 'minimum' => 1],
        'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
    ], 'required' => ['node_id'], 'additionalProperties' => false],
    readOnly: false, destructive: false, idempotent: false, openWorld: false,
)]
final class DeleteApproval extends ApprovalToolBase
{
    public function execute(array $arguments, ClientGateway $gateway): CallToolResult
    {
        return $this->request('delete', $arguments);
    }
}

