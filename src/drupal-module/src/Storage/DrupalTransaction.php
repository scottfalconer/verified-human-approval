<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Storage;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Database\Connection;

/** Owns the SQLite root transaction shared by the action and its evidence. */
final class DrupalTransaction
{
    private bool $active = false;

    /**
     * Node hooks build this service for every node save, so it never refuses to exist: an unsupported database
     * refuses approval work in run() instead, and ordinary saves of unprotected content keep working.
     */
    public function __construct(
        private readonly Connection $database,
        private readonly MemoryCacheInterface $entityMemory,
        private readonly CacheBackendInterface $accessPolicyMemory,
        private readonly CacheTagsChecksumInterface $checksums,
    ) {
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
        if ('sqlite' !== $this->database->driver()) {
            throw new \LogicException('Verified Human Approval currently supports Drupal SQLite only.');
        }
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

    /**
     * Fresh authority and content checks must not reuse what this request loaded or calculated before the lock.
     * Only this request's memory is cleared: the entities it loaded, its calculated permissions, and its copy of
     * cache tag checksums, so persistent cache entries are checked against current invalidations. The site's
     * persistent caches stay, and each check also resets the exact entities it reads.
     */
    public function resetEntityCaches(): void
    {
        $this->entityMemory->deleteAll();
        $this->accessPolicyMemory->deleteAll();
        $this->checksums->reset();
    }
}
