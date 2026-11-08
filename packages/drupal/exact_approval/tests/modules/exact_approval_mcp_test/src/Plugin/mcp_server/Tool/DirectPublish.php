<?php

declare(strict_types=1);

namespace Drupal\exact_approval_mcp_test\Plugin\mcp_server\Tool;

use Drupal\Core\Access\{AccessResult, AccessResultInterface};
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Drupal\node\Entity\Node;
use Mcp\Server\ClientGateway;

#[Tool(id: 'fixture_direct_publish', label: new TranslatableMarkup('Test-only ordinary publish'),
    description: new TranslatableMarkup('Acceptance fixture calls native storage without any approval API.'),
    inputSchema: ['type' => 'object', 'properties' => ['node_id' => ['type' => 'integer']], 'required' => ['node_id'], 'additionalProperties' => false],
    readOnly: false, destructive: true, openWorld: false)]
final class DirectPublish extends ToolPluginBase
{
    protected function defaultConfiguration(): array { return ['enabled' => true]; }
    public function checkAccess(AccountInterface $account): AccessResultInterface
    {
        return AccessResult::allowedIf($account->isAuthenticated() && Settings::get('exact_approval_acceptance_test', false))->cachePerUser();
    }
    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        $node = Node::load($arguments['node_id']);
        if (!$node || !$node->access('update', $this->currentUser) || !$node->get('status')->access('edit', $this->currentUser)) {
            throw new \RuntimeException('Native permission denied.');
        }
        try {
            $node->setPublished()->save();
            return ['published' => true];
        } catch (\Throwable $error) {
            for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof \ExactApproval\Core\ApprovalException) {
                    return new \Mcp\Schema\Result\CallToolResult([new \Mcp\Schema\Content\TextContent($cause->getMessage())], true, (object) ['reason' => $cause->reason]);
                }
            }
            throw $error;
        }
    }
}

