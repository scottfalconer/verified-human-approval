<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\ApprovalStore;
use VerifiedHumanApproval\Core\Ceremony;

/**
 * A real store with injected faults a mock cannot model: a failure just before commit, a commit that really
 * happened but whose answer was lost, and a store that cannot be reached for recovery.
 */
final readonly class FaultingStore implements ApprovalStore
{
    public function __construct(private ApprovalStore $inner, private Faults $faults)
    {
    }

    public function transaction(callable $callback): mixed
    {
        $fault = $this->faults->nextTransaction();
        if ('unavailable' === $fault) {
            throw new \RuntimeException('Injected: the store cannot be reached.');
        }
        $result = $this->inner->transaction(static function () use ($callback, $fault): mixed {
            $result = $callback();
            if ('before_commit' === $fault) {
                throw new \RuntimeException('Injected failure just before commit.');
            }

            return $result;
        });
        if ('after_commit' === $fault) {
            throw new \RuntimeException('Injected failure after a durable commit.');
        }

        return $result;
    }

    public function insert(ApprovalState $state, ?string $idempotencyKey, string $fingerprint): void
    {
        $this->inner->insert($state, $idempotencyKey, $fingerprint);
    }

    public function get(string $id): ?ApprovalState
    {
        return $this->inner->get($id);
    }

    public function findByKey(Actor $actor, string $idempotencyKey): ?array
    {
        return $this->inner->findByKey($actor, $idempotencyKey);
    }

    public function findPending(Actor $actor, string $fingerprint): ?ApprovalState
    {
        return $this->inner->findPending($actor, $fingerprint);
    }

    public function save(ApprovalState $state): void
    {
        $this->inner->save($state);
    }

    public function putCeremony(Ceremony $ceremony): void
    {
        $this->inner->putCeremony($ceremony);
    }

    public function getCeremony(string $id): ?Ceremony
    {
        return $this->inner->getCeremony($id);
    }

    public function retireCeremony(string $id): void
    {
        $this->inner->retireCeremony($id);
    }
}
