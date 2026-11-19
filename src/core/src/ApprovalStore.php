<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/**
 * Durable storage for proposals, ceremonies and their outcomes, and the transaction boundary of an approval.
 * A new adapter (another database, a framework's own storage) must keep every rule below; the storage
 * contract tests (ApprovalStoreContract, in verified-human-approval/store-contracts) show what each one
 * means in practice.
 *
 * 1. Own the root transaction. transaction() refuses, with a LogicException, to start inside an unrelated
 *    transaction, and a normal return means the root transaction committed durably: not that the callback
 *    finished, and not that a nested savepoint was released. Writes outside it are refused the same way.
 * 2. Include every authoritative write. The protected business change, the credential update, the proof,
 *    the execution record, the ceremony changes and the receipt signed at commit share that one transaction.
 * 3. Serialize the writers that matter. The source state, permissions and grants the decision reads must be
 *    protected from concurrent change until commit; locking the approval row alone does not do that.
 * 4. Run the callback once per call. Never retry it on deadlock, timeout or an uncertain commit.
 * 5. Keep canonical data exact. Stored proposals, proofs and records come back with the same bytes, and
 *    JSON keeps null, an empty object and an empty array apart. A record that no longer reads back as it was
 *    written is reported, never repaired or skipped: read it with ApprovalState::fromStorage(), whose
 *    record_integrity_error get() and the lookups pass on.
 * 6. Enforce identity and immutability. A frozen proposal or a terminal record cannot be overwritten through
 *    this API, and database constraints back the identities and the request-key rules.
 * 7. Handle every Throwable. Roll back before rethrowing, reset any transaction bookkeeping, and if the
 *    rollback fails too, rethrow the original error.
 * 8. Recover from authoritative state. After an ambiguous outcome, ApprovalService reads the outcome in a
 *    fresh transaction; it must reach the primary database, never a replica or a stale cache, and nothing
 *    may infer a rollback merely from an exception.
 * 9. Keep schema changes out of execution. Installation and migrations never run inside a transaction here.
 */
interface ApprovalStore
{
    /**
     * Runs $callback once inside a root transaction this store owns, and returns its result after commit.
     * Every other write method is valid only inside it.
     */
    public function transaction(callable $callback): mixed;

    /** Stores a new proposal. A second one with the same id, or the same actor and request key, must fail. */
    public function insert(ApprovalState $state, ?string $idempotencyKey, string $fingerprint): void;

    /** @throws ApprovalException record_integrity_error when the stored record cannot be read back */
    public function get(string $id): ?ApprovalState;

    /**
     * @return array{state: ApprovalState, fingerprint: string}|null
     *
     * @throws ApprovalException record_integrity_error when the stored record cannot be read back
     */
    public function findByKey(Actor $actor, string $idempotencyKey): ?array;

    /**
     * The newest still-pending proposal this actor made with these exact arguments.
     *
     * @throws ApprovalException record_integrity_error when the stored record cannot be read back
     */
    public function findPending(Actor $actor, string $fingerprint): ?ApprovalState;

    /** Records a new status, proof, execution and receipt. A terminal record refuses to change (`immutable_approval`). */
    public function save(ApprovalState $state): void;

    public function putCeremony(Ceremony $ceremony): void;

    public function getCeremony(string $id): ?Ceremony;

    public function retireCeremony(string $id): void;
}
