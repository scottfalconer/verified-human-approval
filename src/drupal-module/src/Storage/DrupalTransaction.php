<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/** Owns the SQLite root transaction shared by the action and its evidence. */
final class DrupalTransaction
{
    private bool $active = false;

    public function __construct(
        private readonly Connection $database,
        private readonly EntityTypeManagerInterface $entityTypes,
    ) {
        if ('sqlite' !== $database->driver()) {
            throw new \LogicException('Verified Human Approval currently supports Drupal SQLite only.');
        }
    }

    /**
     * Run once and return only after root commit. Never silently retry a callback.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function run(callable $callback): mixed
    {
        if ($this->active || $this->database->inTransaction()) {
            throw new \LogicException('Approval execution must own the outer Drupal database transaction.');
        }
        $transaction = $this->database->startTransaction('verified_human_approval');
        try {
            // Drupal's SQLite transaction starts deferred. A write, even to an
            // empty table, reserves the database writer before authority is read.
            // This also serializes ordinary Drupal writers using this database.
            $this->database->query('UPDATE {vha_mutex} SET marker = marker WHERE id = 1');
            $this->active = true;
            $this->resetEntityCaches();
            $result = $callback();
            $transaction->commitOrRelease();
            if ($this->database->inTransaction()) {
                throw new \LogicException('The approval root transaction did not finish.');
            }

            return $result;
        } catch (\Throwable $error) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
                // A commit callback can fail after durable commit. Preserve the
                // failure; ApprovalService recovers from a fresh storage read.
            }
            try {
                $this->resetEntityCaches();
            } catch (\Throwable) {
                // Cache cleanup must not hide the original transaction failure.
            }
            throw $error;
        } finally {
            $this->active = false;
        }
    }

    public function isActive(): bool
    {
        return $this->active && $this->database->inTransaction();
    }

    public function assertActive(): void
    {
        if (!$this->isActive()) {
            throw new \LogicException('Approval writes require the shared Drupal transaction.');
        }
    }

    /** Fresh authority/content checks must not reuse entities from before the lock. */
    public function resetEntityCaches(): void
    {
        foreach (['node', 'user', 'user_role'] as $type) {
            if ($this->entityTypes->hasDefinition($type)) {
                $this->entityTypes->getStorage($type)->resetCache();
            }
        }
    }
}
