<?php

declare(strict_types=1);

namespace Drupal\exact_approval_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\Access\{AccessResult, AccessResultInterface};
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\{AccountInterface, AccountProxyInterface};
use Drupal\Core\Url;
use Drupal\exact_approval\Security\ActorProvider;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use ExactApproval\Core\{ApprovalException, ApprovalService, ApprovalState};
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** No alternate executor: both MCP and the UI use the same approval service. */
abstract class ApprovalToolBase extends ToolPluginBase
{
    public function __construct(array $configuration, string $plugin_id, mixed $plugin_definition, AccountProxyInterface $currentUser,
        protected readonly ApprovalService $approvals, protected readonly ActorProvider $actors, private readonly ConfigFactoryInterface $config) {
        parent::__construct($configuration, $plugin_id, $plugin_definition, $currentUser);
    }

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static
    {
        return new static($configuration, $plugin_id, $plugin_definition, $container->get('current_user'),
            $container->get('exact_approval.approvals'), $container->get('exact_approval.actor_provider'), $container->get('config.factory'));
    }

    protected function defaultConfiguration(): array { return ['enabled' => true]; }

    public function checkAccess(AccountInterface $account): AccessResultInterface
    {
        return AccessResult::allowedIf($account->isAuthenticated())->cachePerUser();
    }

    protected function request(string $operation, array $arguments): CallToolResult
    {
        return $this->respond(function () use ($operation, $arguments): ApprovalState {
            $key = $arguments['idempotency_key'] ?? null;
            unset($arguments['idempotency_key']);
            return $this->approvals->request($this->actors->current(), 'node.'.$operation, $arguments, $key);
        });
    }

    protected function respond(callable $callback): CallToolResult
    {
        try {
            $state = $callback();
            $data = $state->toArray();
            $path = Url::fromRoute('exact_approval.review', ['id' => $state->request->id])->toString();
            $data['reviewUrl'] = rtrim((string) $this->config->get('exact_approval.settings')->get('origin'), '/').$path;
            $data['message'] = $state->status === 'pending'
                ? 'No content has changed. The initiating user must review this exact action in their Drupal browser session and approve with their registered key. Successful approval executes the stored action immediately.'
                : 'This is the stored outcome. Do not repeat the content operation to retrieve its result.';
            $error = in_array($state->status, ['denied', 'invalidated', 'expired', 'cancelled'], true);
        } catch (ApprovalException $exception) {
            $data = ['status' => 'rejected', 'reason' => $exception->reason, 'message' => $exception->getMessage()];
            $error = true;
        }
        return new CallToolResult([new TextContent(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))], $error, (object) $data);
    }
}

