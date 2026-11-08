<?php

declare(strict_types=1);

namespace ExactApproval\Core;

/** Implementations must serialize competing writers and include the protected application mutation. */
interface ApprovalStore
{
    public function transaction(callable $callback): mixed;
    public function insert(ApprovalState $state, ?string $idempotencyKey, string $fingerprint): void;
    public function get(string $id): ?ApprovalState;
    /** @return null|array{state: ApprovalState, fingerprint: string} */
    public function findByKey(Actor $actor, string $idempotencyKey): ?array;
    public function save(ApprovalState $state): void;
    public function putCeremony(Ceremony $ceremony): void;
    public function getCeremony(string $id): ?Ceremony;
    public function retireCeremony(string $id): void;
}
