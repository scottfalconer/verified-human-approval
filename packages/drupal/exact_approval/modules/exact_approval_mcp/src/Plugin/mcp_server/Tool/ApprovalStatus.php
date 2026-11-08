<?php

declare(strict_types=1);

namespace Drupal\exact_approval_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Mcp\Schema\Result\CallToolResult;

#[Tool(
    id: 'exact_approval_status',
    label: new TranslatableMarkup('Read my approval outcome'),
    description: new TranslatableMarkup('Return the stored status and result of this user’s approval request. This cannot approve or execute a content action and cannot read another user’s requests.'),
    inputSchema: ['type' => 'object', 'properties' => ['request_id' => ['type' => 'string']], 'required' => ['request_id'], 'additionalProperties' => false],
    readOnly: true, destructive: false, idempotent: true, openWorld: false,
)]
final class ApprovalStatus extends ApprovalToolBase
{
    public function execute(array $arguments, ClientGateway $gateway): CallToolResult
    {
        return $this->respond(fn () => $this->approvals->get($this->actors->current(), $arguments['request_id']));
    }
}

