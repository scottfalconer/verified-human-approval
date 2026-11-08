<?php

declare(strict_types=1);

namespace Drupal\exact_approval_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Mcp\Schema\Result\CallToolResult;

#[Tool(
    id: 'exact_approval_publish',
    label: new TranslatableMarkup('Request exact publication approval'),
    description: new TranslatableMarkup('Prepare publication of a saved Drupal node revision. Returns a browser review URL; content changes only after the same user approves the exact frozen action with a passkey. Respects native permissions and moderation. It cannot edit content or approve on the human’s behalf.'),
    inputSchema: ['type' => 'object', 'properties' => [
        'node_id' => ['type' => 'integer', 'minimum' => 1],
        'revision_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional saved revision; defaults to the latest revision.'],
        'transition_id' => ['type' => 'string', 'description' => 'Exact publishing transition when the workflow has more than one valid choice.'],
        'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => 'Reuse for retries of this same request only.'],
    ], 'required' => ['node_id'], 'additionalProperties' => false],
    readOnly: false, destructive: false, idempotent: false, openWorld: false,
)]
final class PublishApproval extends ApprovalToolBase
{
    public function execute(array $arguments, ClientGateway $gateway): CallToolResult
    {
        return $this->request('publish', $arguments);
    }
}

