<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Doctrine\Orm;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Doctrine\DbalTransaction;

/**
 * An action that changes Doctrine entities. Its execute() is final: it clears the entity manager, so nothing loaded
 * or changed earlier is read as current or flushed with the approved change; loads the targets fresh, locked for
 * writing; lets apply() change them; and flushes, inside the approval transaction (a savepoint there), so the
 * postconditions read the written rows and the receipt is signed after them. If the transaction rolls back, the
 * entity manager is reset: its objects would describe rows that were never committed.
 *
 * Read the current state in prepare(), assertPreconditions() and assertPostconditions() with current(), which
 * locks the row for writing inside the transaction, and read permissions with DbalTransaction::forShare().
 */
abstract class EntityAction implements ActionAdapter
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ExecutionGuard $guard,
        private readonly DbalTransaction $transaction,
        private readonly ?ProtectedEntityListener $protection = null,
        private readonly ?string $managerName = null,
    ) {
    }

    /**
     * The entities the approved change writes, by name, as [entity class, identifier].
     *
     * @return array<string, array{class-string, mixed}>
     */
    abstract protected function targets(Actor $actor, PreparedAction $action): array;

    /**
     * Applies the approved change to the locked entities and returns the result to record. Do not flush.
     *
     * @param array<string, object> $entities the targets, by name
     *
     * @return array<string, mixed>
     */
    abstract protected function apply(Actor $actor, PreparedAction $action, array $entities): array;

    final public function execute(Actor $actor, PreparedAction $action): array
    {
        $this->guard->assertExecuting($actor, $action);
        $this->transaction->assertActive();
        $manager = $this->manager();
        $this->transaction->onRollback($this->reset(...));
        $manager->clear();
        $entities = [];
        foreach ($this->targets($actor, $action) as $name => [$class, $id]) {
            $entities[$name] = $manager->find($class, $id, LockMode::PESSIMISTIC_WRITE)
                ?? throw new ApprovalException('stale_resource', 'Something the approved change writes no longer exists.');
        }
        $this->protection?->permit(...array_values($entities));
        $result = $this->apply($actor, $action, $entities);
        $manager->flush();

        return $result;
    }

    /** Persists a new entity the approved change creates, permitted as one of its targets. */
    protected function persist(object $entity): void
    {
        $this->protection?->permit($entity);
        $this->manager()->persist($entity);
    }

    /**
     * The entity as stored now, locked for writing while the approval transaction is open, or null.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    protected function current(string $class, mixed $id): ?object
    {
        $manager = $this->manager();
        $entity = $manager->find($class, $id);
        if (null !== $entity && $this->transaction->isActive()) {
            // Reloads it as well: an object loaded before the lock may describe an older row.
            $manager->refresh($entity, LockMode::PESSIMISTIC_WRITE);
        }

        return $entity;
    }

    /** The entity manager, on the approval transaction's connection. */
    protected function manager(): EntityManagerInterface
    {
        $manager = $this->doctrine->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $manager = $this->doctrine->resetManager($this->managerName);
        }
        if (!$manager instanceof EntityManagerInterface || $manager->getConnection() !== $this->transaction->connection()) {
            throw new \LogicException('The action\'s entity manager must use the approval transaction\'s connection, or its flush would commit outside the approval.');
        }

        return $manager;
    }

    private function reset(): void
    {
        try {
            $this->doctrine->resetManager($this->managerName);
        } catch (\LogicException) {
            // A manager that cannot be reset (not a lazy service) is at least emptied.
            $manager = $this->doctrine->getManager($this->managerName);
            $manager->clear();
        }
    }
}
