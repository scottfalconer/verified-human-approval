<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Guard;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\exact_approval\Action\NodeSnapshot;
use Drupal\exact_approval\Policy\PolicyRules;
use Drupal\exact_approval\Security\ActorProvider;
use Drupal\node\NodeInterface;
use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\CanonicalJson;
use ExactApproval\Core\ExecutionGuard;
use ExactApproval\Core\PreparedAction;

/** A single-use, exact-entity execution context, never an administrator bypass. */
final class MutationGuard
{
    private ?object $scope = null;

    public function __construct(
        private readonly ExecutionGuard $core,
        private readonly ActorProvider $actors,
        private readonly PolicyRules $rules,
        private readonly NodeSnapshot $snapshots,
        private readonly EntityTypeManagerInterface $entityTypes,
        private readonly ?ModerationInformationInterface $moderation = null,
    ) {}

    public function within(Actor $actor, PreparedAction $action, NodeInterface $node, callable $callback): mixed
    {
        $this->core->assertExecuting($actor, $action);
        if ($this->scope !== null || !$this->actors->current()->samePerson($actor)
            || $node->uuid() !== $action->target['uuid'] || (string) $node->id() !== $action->target['node_id']) {
            throw new ApprovalException('approval_required', 'No matching single-content approval context exists.');
        }
        $frozen = PreparedAction::fromArray(CanonicalJson::map(CanonicalJson::decode(CanonicalJson::encode($action->toArray()))));
        $this->scope = (object) [
            'actor' => $actor, 'action' => $frozen, 'node' => $node,
            'begun' => false, 'completed' => false,
        ];
        try {
            $result = $callback();
            if (!$this->scope->completed) {
                throw new ApprovalException('postcondition_failed', 'The approved content mutation did not complete through protected storage.');
            }
            return $result;
        } finally {
            $this->scope = null;
        }
    }

    /** Called before any node or field presave hook. */
    public function beforeSave(NodeInterface $node): array
    {
        $beforeLanguages = [];
        $stored = null;
        if (!$node->isNew() && $node->id() !== null) {
            $storage = $this->storage();
            $storage->resetCache([$node->id()]);
            $stored = $storage->load($node->id());
            if ($stored instanceof NodeInterface) {
                // Reverting an older revision must not silently remove a
                // translation introduced in the current default revision.
                $beforeLanguages = array_keys($stored->getTranslationLanguages());
            }
            $revision = $node->getLoadedRevisionId() ?: $node->getRevisionId();
            if ($revision) {
                $storage->resetRevisionCache([$revision]);
                $before = $storage->loadRevision($revision);
            } else {
                $before = $storage->load($node->id());
            }
            if ($before instanceof NodeInterface) {
                $beforeLanguages = array_values(array_unique(array_merge($beforeLanguages, array_keys($before->getTranslationLanguages()))));
            }
        }
        $token = [
            'languages' => $beforeLanguages,
            'publish_protected' => $this->rules->protects('publish', $node->bundle())
                || ($stored instanceof NodeInterface && $this->rules->protects('publish', $stored->bundle())),
            'delete_protected' => $this->rules->protects('delete', $node->bundle())
                || ($stored instanceof NodeInterface && $this->rules->protects('delete', $stored->bundle())),
        ];
        if ($stored instanceof NodeInterface && ($token['publish_protected'] || $token['delete_protected'])
            && ($stored->bundle() !== $node->bundle() || $stored->uuid() !== $node->uuid())) {
            throw new ApprovalException('unsupported_action', 'Changing the identity or content type of approval-protected content is unsupported.');
        }
        if ($this->scope !== null) {
            $this->begin('node.publish', $node);
            if (!$this->snapshots->same($this->snapshots->content($node), $this->scope->action->effect['content'])) {
                throw new ApprovalException('effect_changed', 'The publication differs from the exact approved content.');
            }
        } elseif ($token['publish_protected'] && $this->hasPublishedTranslation($node, true)) {
            $this->approvalRequired('Publishing or changing published content requires an exact approval. Save an unpublished draft and request publication approval.');
        }
        $this->assertNoRemovedTranslation($node, $token);
        return $token;
    }

    /** Called after ALL entity/field save hooks, before the outer transaction commits. */
    public function afterSave(NodeInterface $node, array $token): void
    {
        $storage = $this->storage();
        $storage->resetCache([$node->id()]);
        $storage->resetRevisionCache([$node->getRevisionId()]);
        $persisted = $storage->loadRevision($node->getRevisionId());
        if (!$persisted instanceof NodeInterface) {
            throw new ApprovalException('postcondition_failed', 'The saved revision cannot be verified.');
        }
        $this->assertNoRemovedTranslation($persisted, $token);
        if ($this->scope === null) {
            // A later hook can change status even if the incoming entity was a draft.
            if (($token['publish_protected'] || $this->rules->protects('publish', $persisted->bundle()))
                && $this->hasPublishedTranslation($persisted)) {
                $this->approvalRequired('A save hook attempted to publish content without exact approval.');
            }
            return;
        }
        $this->assertScope('node.publish', $node);
        $default = $storage->load($node->id());
        if (!$persisted->isPublished() || !$persisted->isDefaultRevision()
            || !$default instanceof NodeInterface || (string) $default->getRevisionId() !== (string) $persisted->getRevisionId()
            || !$this->snapshots->same($this->snapshots->content($default), $this->scope->action->effect['content'])
            || !$this->snapshots->same($this->snapshots->content($persisted), $this->scope->action->effect['content'])) {
            throw new ApprovalException('postcondition_failed', 'A Drupal save hook altered the approved publication.');
        }
        $this->scope->completed = true;
    }

    public function beforeDelete(NodeInterface $node): void
    {
        if ($this->scope !== null) {
            $this->begin('node.delete', $node);
            $source = $this->snapshots->map($this->scope->action->preconditions['source']);
            if (!$this->snapshots->same($this->snapshots->capture($node), $source['snapshot'])) {
                throw new ApprovalException('source_changed', 'The content being deleted differs from the approved snapshot.');
            }
        } else {
            $this->storage()->resetCache([$node->id()]);
            $stored = $this->storage()->load($node->id());
            if ($this->rules->protects('delete', $node->bundle())
                || ($stored instanceof NodeInterface && $this->rules->protects('delete', $stored->bundle()))) {
                $this->approvalRequired('Deleting this content requires an exact approval.');
            }
        }
    }

    public function afterDelete(NodeInterface $node): void
    {
        if ($this->scope === null) {
            return;
        }
        $this->assertScope('node.delete', $node);
        $id = $this->scope->action->target['node_id'];
        $this->storage()->resetCache([$id]);
        if ($this->storage()->load($id) !== null) {
            throw new ApprovalException('postcondition_failed', 'The approved content was not deleted.');
        }
        $this->scope->completed = true;
    }

    public function assertRevisionDeleteAllowed(NodeInterface $node): void
    {
        if ($this->scope !== null || $this->rules->protects('delete', $node->bundle())) {
            throw new ApprovalException('unsupported_action', 'Individual revision deletion is disabled for approval-protected content; it needs its own exact-action adapter.');
        }
    }

    private function begin(string $operation, NodeInterface $node): void
    {
        $this->assertScope($operation, $node);
        if ($this->scope->begun) {
            $this->approvalRequired('An approval permits one entity operation, not recursive or repeated mutations.');
        }
        $this->scope->begun = true;
    }

    private function assertScope(string $operation, NodeInterface $node): void
    {
        if ($this->scope === null || $this->scope->action->actionId !== $operation
            || $node !== $this->scope->node || $node->uuid() !== $this->scope->action->target['uuid']
            || (string) $node->id() !== $this->scope->action->target['node_id']
            || !$this->actors->current()->samePerson($this->scope->actor)) {
            $this->approvalRequired('The mutation does not match the approved actor, target and operation.');
        }
        $this->core->assertExecuting($this->scope->actor, $this->scope->action);
    }

    private function assertNoRemovedTranslation(NodeInterface $node, array $token): void
    {
        if ($token['delete_protected'] && array_diff($token['languages'], array_keys($node->getTranslationLanguages())) !== []) {
            throw new ApprovalException('unsupported_action', 'Individual translation deletion is disabled for approval-protected content; it needs its own exact-action adapter.');
        }
    }

    private function hasPublishedTranslation(NodeInterface $node, bool $beforeHooks = false): bool
    {
        foreach ($node->getTranslationLanguages() as $langcode => $language) {
            $translation = $node->getTranslation($langcode);
            if ($beforeHooks && $this->moderation?->isModeratedEntity($translation)) {
                $state = $translation->get('moderation_state')->value;
                if ($state !== null && $state !== '') {
                    if ($this->moderation->getWorkflowForEntity($translation)->getTypePlugin()->getState($state)->isPublishedState()) {
                        return true;
                    }
                    continue;
                }
            }
            if ($translation->isPublished()) {
                return true;
            }
        }
        return false;
    }

    private function storage(): ApprovalNodeStorage
    {
        $storage = $this->entityTypes->getStorage('node');
        if (!$storage instanceof ApprovalNodeStorage) {
            throw new ApprovalException('unsupported_storage', 'The protected node storage handler is unavailable.');
        }
        return $storage;
    }

    private function approvalRequired(string $message): never
    {
        throw new ApprovalException('approval_required', $message);
    }
}
