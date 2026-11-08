<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Action;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\exact_approval\Guard\ApprovalNodeStorage;
use Drupal\exact_approval\Guard\MutationGuard;
use Drupal\exact_approval\Security\ActorProvider;
use Drupal\node\NodeInterface;
use ExactApproval\Core\ActionAdapter;
use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\PreparedAction;

/** Shared source locking, immutable snapshots and current-state checks. */
abstract class NodeActionBase implements ActionAdapter
{
    public function __construct(
        protected readonly EntityTypeManagerInterface $entityTypes,
        protected readonly Connection $database,
        protected readonly ActorProvider $actors,
        protected readonly NodeSnapshot $snapshots,
        protected readonly NodeAccess $access,
        protected readonly MutationGuard $guard,
    ) {}

    public function version(): string { return '1'; }

    protected function storage(): ApprovalNodeStorage
    {
        if (\Drupal::hasService('workspaces.manager') && \Drupal::service('workspaces.manager')->hasActiveWorkspace()) {
            throw new ApprovalException('unsupported_workspace', 'This exact-action adapter supports the live workspace only.');
        }
        $storage = $this->entityTypes->getStorage('node');
        if (!$storage instanceof ApprovalNodeStorage) {
            throw new ApprovalException('unsupported_storage', 'Exact approval requires its protected node storage handler.');
        }
        return $storage;
    }

    protected function input(array $input, array $allowed): array
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ApprovalException('invalid_input', 'Only the documented saved-content identifiers may be supplied.');
        }
        foreach (['node_id', 'revision_id'] as $field) {
            if (isset($input[$field]) && ((!is_int($input[$field]) && !is_string($input[$field])) || !preg_match('/^[1-9][0-9]*$/D', (string) $input[$field]))) {
                throw new ApprovalException('invalid_input', 'Content and revision identifiers must be positive integers.');
            }
        }
        if (!isset($input['node_id'])) {
            throw new ApprovalException('invalid_input', 'node_id is required.');
        }
        if (isset($input['transition_id']) && (!is_string($input['transition_id']) || !preg_match('/^[a-z0-9_]+$/D', $input['transition_id']))) {
            throw new ApprovalException('invalid_input', 'transition_id must be a workflow transition identifier.');
        }
        return $input;
    }

    protected function load(string $id, ?string $revision = null, bool $latest = true): NodeInterface
    {
        $storage = $this->storage();
        $storage->resetCache([$id]);
        $selectedRevision = $revision ?? ($latest ? (string) $storage->getLatestRevisionId($id) : null);
        if ($selectedRevision !== null && $selectedRevision !== '') {
            $storage->resetRevisionCache([$selectedRevision]);
            $node = $storage->loadRevision($selectedRevision);
        } else {
            $node = $storage->load($id);
        }
        if (!$node instanceof NodeInterface || (string) $node->id() !== $id) {
            throw new ApprovalException('target_missing', 'The selected saved content revision no longer exists.');
        }
        return $node->getUntranslated();
    }

    /** Serialize writes on the entity's primary row before reading fresh state. */
    protected function lock(string $id): void
    {
        if (!$this->database->inTransaction()) {
            throw new ApprovalException('transaction_required', 'Exact approval execution must share Drupal\'s database transaction.');
        }
        $found = $this->database->select('node', 'n')->fields('n', ['nid'])->condition('nid', $id)->forUpdate()->execute()->fetchField();
        if ($found === false) {
            throw new ApprovalException('target_missing', 'The selected content no longer exists.');
        }
    }

    protected function source(NodeInterface $node, bool $allRevisions = false): array
    {
        $storage = $this->storage();
        $storage->resetCache([$node->id()]);
        $default = $storage->load($node->id());
        $source = [
            'snapshot' => (object) $this->snapshots->capture($node),
            'default_revision_id' => (string) $default->getRevisionId(),
            'latest_revision_id' => (string) $storage->getLatestRevisionId($node->id()),
        ];
        if ($allRevisions) {
            $revisions = [];
            foreach ($storage->revisionIds($node) as $revisionId) {
                $storage->resetRevisionCache([$revisionId]);
                $revision = $storage->loadRevision($revisionId);
                if (!$revision instanceof NodeInterface) {
                    throw new ApprovalException('source_changed', 'A content revision disappeared during preparation.');
                }
                $revisions[(string) $revisionId] = $this->snapshots->digest($this->snapshots->capture($revision));
            }
            $source['revisions'] = (object) $revisions;
        }
        return $source;
    }

    protected function sourceNode(PreparedAction $action): NodeInterface
    {
        $source = $this->snapshots->map($action->preconditions['source']);
        return $this->snapshots->restore($this->storage(), $this->snapshots->map($source['snapshot']));
    }

    protected function target(NodeInterface $node): array
    {
        return [
            'site' => $this->actors->tenantId(), 'entity_type' => 'node',
            'node_id' => (string) $node->id(), 'uuid' => $node->uuid(),
            'bundle' => $node->bundle(), 'revision_id' => (string) $node->getRevisionId(),
            'language' => $node->language()->getId(),
        ];
    }

    protected function placeholders(NodeInterface $node, string $operation, ?string $transition = null): array
    {
        return [
            'title' => (string) $node->label(), 'nodeId' => (string) $node->id(),
            'bundle' => $node->bundle(), 'revisionId' => (string) $node->getRevisionId(),
            'operation' => $operation, 'transition' => $transition ?? '', 'site' => $this->actors->tenantId(),
        ];
    }

    protected function assertIdentity(Actor $actor, PreparedAction $action): void
    {
        if ($actor->tenantId !== $this->actors->tenantId() || $action->target['site'] !== $actor->tenantId
            || $action->actionId !== $this->id() || $action->version !== $this->version()) {
            throw new ApprovalException('invalid_target', 'The approval belongs to a different site or action.');
        }
    }

    protected function assertSource(Actor $actor, PreparedAction $action, bool $allRevisions = false): NodeInterface
    {
        $this->assertIdentity($actor, $action);
        $id = $action->target['node_id'];
        $this->lock($id);
        $node = $this->load($id, $action->target['revision_id']);
        if (!$this->snapshots->same($this->source($node, $allRevisions), $action->preconditions['source'])) {
            throw new ApprovalException('source_changed', 'The saved content, revision set or selected revision changed after approval was requested.');
        }
        return $node;
    }
}
