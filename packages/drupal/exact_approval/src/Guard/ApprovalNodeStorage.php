<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Guard;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorage;

/**
 * A stock-node storage boundary covering UI, JSON:API, bulk, CLI and MCP saves.
 *
 * Checks happen after all hooks in an outer transaction. This is not protection
 * from trusted PHP deliberately bypassing entity storage, SQL, or external side
 * effects performed by arbitrary hooks. Custom storage needs an explicit adapter.
 */
final class ApprovalNodeStorage extends NodeStorage
{
    /** Fresh approval checks must not use a previously cached revision. */
    public function resetRevisionCache(array $revision_ids): void
    {
        parent::resetRevisionCache($revision_ids);
    }

    public function save(EntityInterface $entity)
    {
        if (!$entity instanceof NodeInterface) {
            throw new \LogicException('Node storage received a non-node entity.');
        }
        $transaction = $this->database->startTransaction();
        try {
            $token = $this->guard()->beforeSave($entity);
            $result = parent::save($entity);
            $this->guard()->afterSave($entity, $token);
            $transaction->commitOrRelease();
            return $result;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            if ($entity->id() !== null) {
                $this->resetCache([$entity->id()]);
            }
            throw $error;
        }
    }

    public function delete(array $entities)
    {
        if ($entities === []) {
            return;
        }
        $transaction = $this->database->startTransaction();
        try {
            // Reject a protected member before ANY member of a bulk delete runs.
            foreach ($entities as $entity) {
                $this->guard()->beforeDelete($entity);
            }
            parent::delete($entities);
            foreach ($entities as $entity) {
                $this->guard()->afterDelete($entity);
            }
            $transaction->commitOrRelease();
        } catch (\Throwable $error) {
            $transaction->rollBack();
            $this->resetCache(array_map(static fn (NodeInterface $node) => $node->id(), $entities));
            throw $error;
        }
    }

    public function deleteRevision($revision_id)
    {
        $this->resetRevisionCache([$revision_id]);
        $node = $this->loadRevision($revision_id);
        if ($node instanceof NodeInterface) {
            $this->guard()->assertRevisionDeleteAllowed($node);
        }
        parent::deleteRevision($revision_id);
    }

    private function guard(): MutationGuard
    {
        // Entity storage is itself built by Drupal's handler factory. Keeping
        // its inherited factory/constructor allows stock storage dependencies
        // to evolve without copying them into this integration.
        return \Drupal::service('exact_approval.mutation_guard');
    }
}
