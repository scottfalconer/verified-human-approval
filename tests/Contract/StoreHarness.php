<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalStore;
use VerifiedHumanApproval\Core\Ceremony;

/**
 * One storage adapter under the shared approval contract (ApprovalStoreContract).
 *
 * A harness wires the real ApprovalService over its adapter with a simple protected action (publishing an
 * article), a clearly test-only verifier, a real Ed25519 receipt signer and fault controls. Every
 * connection to one location shares the same data and receipt key. Tests change state through the methods
 * below, the way an ordinary writer would, and observe it through a fresh connection, never through the
 * adapter's own database handle. To run the contract for a new adapter, implement this class and extend
 * ApprovalStoreContract with it.
 */
abstract class StoreHarness
{
    public readonly Faults $faults;

    public function __construct()
    {
        $this->faults = new Faults();
    }

    /** Creates a new, isolated store with its schema and seed data, and returns where it is. Runs before anything connects. */
    abstract public static function prepare(): string;

    /** Removes everything prepare() created. */
    abstract public static function destroy(string $location): void;

    /** A new connection to prepared data. It runs no DDL, so concurrent workers can use it. */
    abstract public static function connect(string $location): static;

    /** The service, over the adapter wrapped with this harness's faults. */
    abstract public function service(): ApprovalService;

    /** The adapter itself, for checking its own rules directly. */
    abstract public function store(): ApprovalStore;

    abstract public function receiptPublicKey(): string;

    abstract public function receiptKeyId(): string;

    /** How many times the protected action ran in this connection's process. */
    abstract public function executeCalls(): int;

    /** @return array{requests: int, effects: int, executions: int, receipts: int, credentialUses: int} what is durably stored */
    abstract public function counts(): array;

    /** @return array{revision: int, body: string, published: bool} the article as durably stored */
    abstract public function article(int $id = 1): array;

    /** Makes the protected action fail after its write ('after_write'), or break its postcondition ('postcondition'); null mends it. */
    abstract public function breakAction(?string $how): void;

    abstract public function editArticle(int $id = 1): void;

    abstract public function revokeGrant(string $id = 'agent-1'): void;

    abstract public function revokePermission(string $userId = 'alice'): void;

    abstract public function changePolicy(): void;

    abstract public function revokeCredential(string $id = 'alice-key'): void;

    /** Makes the next insert of an execution record or a receipt fail inside the storage itself. */
    abstract public function failInserts(string $what): void;

    abstract public function clearInsertFailures(): void;

    /** Runs $work inside an unrelated transaction opened on the adapter's connection, then rolls that back. */
    abstract public function withAmbientTransaction(callable $work): mixed;

    /**
     * Holds the database's write lock from another connection until the returned callable is called.
     *
     * @return callable(): void
     */
    abstract public function holdWriteLock(): callable;

    /** Waits at most this long for a lock before failing. */
    abstract public function lockTimeout(int $milliseconds): void;

    public function actor(): Actor
    {
        return new Actor('alice', 'tenant-a', 'agent-1');
    }

    public function reviewer(): Actor
    {
        return new Actor('alice', 'tenant-a');
    }

    /** @return array<string, mixed> the test-only verifier's proof for this ceremony */
    public function proof(Ceremony $ceremony, string $user = 'alice', string $tenant = 'tenant-a'): array
    {
        return ['test_only' => true, 'challenge' => $ceremony->challenge, 'user' => $user, 'tenant' => $tenant, 'credential' => 'alice-key'];
    }
}
