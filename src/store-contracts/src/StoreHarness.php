<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\StoreContracts;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalStore;
use VerifiedHumanApproval\Core\Ceremony;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAction;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAuthorizer;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureDatabase;
use VerifiedHumanApproval\StoreContracts\Fixture\FixturePolicies;
use VerifiedHumanApproval\StoreContracts\Fixture\TestProofVerifier;

/**
 * One storage adapter under the shared approval contract (ApprovalStoreContract).
 *
 * A harness wires the real ApprovalService over its adapter with a simple protected action (publishing an
 * article), a clearly test-only verifier, a real Ed25519 receipt signer and fault controls. Every
 * connection to one location shares the same data and receipt key. Tests change state through the methods
 * below, the way an ordinary writer would, and observe it through a fresh connection, never through the
 * adapter's own database handle. To run the contract for a new adapter, implement this class and extend
 * ApprovalStoreContract with it.
 *
 * The fixture application (FixtureAction, FixtureAuthorizer, FixturePolicies, TestProofVerifier) reads through a
 * FixtureDatabase on the adapter's connection. On a database that locks rows, give it the lock clauses, so that
 * it locks what its decision reads, as an application must.
 */
abstract class StoreHarness
{
    /** The ordinary writers' changes a test can make with attempt(). editArticle() and revokeGrant() make the first two, and must succeed. */
    public const CHANGES = ['edit_article', 'revoke_grant', 'revoke_permission', 'revoke_credential', 'change_policy'];

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

    /**
     * Closes the connection connect() opened. The contract closes every harness a test connected when the test ends,
     * before destroy(), rather than wait for PHP to free it: the authorizer's afterCheck closure over the harness puts
     * it in a reference cycle, which PHP frees only when it next collects cycles, and until then its connection stays
     * open and counts against the database server's limit. The default does nothing, for a store with no connection
     * to close.
     */
    public function close(): void
    {
    }

    /** The service, over the adapter wrapped with this harness's faults. */
    abstract public function service(): ApprovalService;

    /** The adapter itself, for checking its own rules directly. */
    abstract public function store(): ApprovalStore;

    /** The protected action the service runs, to give its proposals and results a particular shape. */
    abstract public function action(): FixtureAction;

    abstract public function receiptPublicKey(): string;

    abstract public function receiptKeyId(): string;

    /** How many times the protected action ran in this connection's process. */
    abstract public function executeCalls(): int;

    /** @return array{requests: int, effects: int, executions: int, receipts: int, credentialUses: int} what is durably stored */
    abstract public function counts(): array;

    /** @return array{revision: int, body: string, published: bool} the article as durably stored */
    abstract public function article(int $id = 1): array;

    /**
     * Makes the protected action fail after its write ('after_write'), or break its postcondition ('postcondition'),
     * or end the approval transaction itself once its postconditions hold, as code on the adapter's connection could
     * without the adapter: commit it ('commit'), roll it back ('roll_back'), roll it back and begin another
     * ('begin_again'), roll it back in SQL ('sql_roll_back'), as some databases do by themselves after an error, or
     * roll it back from inside a nested transaction of the action's own, a savepoint, and carry on as if that
     * best-effort work had merely failed ('nested_roll_back'), which can leave the adapter counting it as open.
     * Null mends it.
     */
    abstract public function breakAction(?string $how): void;

    /**
     * Makes one of the CHANGES from a connection of its own, as an ordinary writer outside the approval service,
     * waiting at most $milliseconds for a lock. Returns true if it committed, false if a lock held it off.
     */
    abstract public function attempt(string $change, int $milliseconds): bool;

    /** Replaces an article's title and body, as an editor would, from a connection of its own. */
    abstract public function writeArticle(int $id, string $title, string $body): void;

    /**
     * Rewrites one stored part of a request's record from a connection of its own, as someone with write access to
     * the database could: its 'proposal', 'digest', 'status', 'result' or 'proof', its 'execution' record, or its
     * receipt's 'signature'. $edit is given the text stored there and returns the text that replaces it.
     *
     * @param \Closure(string): string $edit
     */
    abstract public function editRecord(string $requestId, string $part, \Closure $edit): void;

    /**
     * Rewrites one stored part of a passkey ceremony from a connection of its own, as editRecord() does for a
     * request: its 'options', its 'nonce' or its expiry ('expires_at').
     *
     * @param \Closure(string): string $edit
     */
    abstract public function editCeremony(string $ceremonyId, string $part, \Closure $edit): void;

    /** Makes the next insert of an execution record or a receipt fail inside the storage itself. */
    abstract public function failInserts(string $what): void;

    abstract public function clearInsertFailures(): void;

    /** Runs $work inside an unrelated transaction opened on the adapter's connection, then rolls that back. */
    abstract public function withAmbientTransaction(callable $work): mixed;

    /**
     * Holds, from another connection, the locks an approval needs before it can read anything: the database's write
     * lock where there is one, or the rows of the approval records and the business content. Released when the
     * returned callable is called.
     *
     * @return callable(): void
     */
    abstract public function holdWriteLock(): callable;

    /** Waits at most this long for a lock before failing. */
    abstract public function lockTimeout(int $milliseconds): void;

    public function editArticle(): void
    {
        $this->mustCommit('edit_article');
    }

    public function revokeGrant(): void
    {
        $this->mustCommit('revoke_grant');
    }

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

    /**
     * The statement that makes one of the CHANGES, in SQL the fixture schema accepts on every database. An edit
     * appends to the body in one statement, with the platform's concatenation: %1$s is the column, %2$s the text.
     *
     * @return array{string, list<int|string>}
     */
    protected static function change(string $change, string $concat): array
    {
        return match ($change) {
            'edit_article' => ['UPDATE test_articles SET body = '.\sprintf($concat, 'body', '?').', revision = revision + 1 WHERE id = ?', [' Edited.', 1]],
            'revoke_grant' => ['UPDATE test_delegations SET active = 0 WHERE id = ?', ['agent-1']],
            'revoke_permission' => ["UPDATE test_users SET can_publish = 0 WHERE user_id = ? AND tenant = 'tenant-a'", ['alice']],
            'revoke_credential' => ['UPDATE test_credentials SET active = 0 WHERE id = ?', ['alice-key']],
            'change_policy' => ['UPDATE test_policies SET version = version + 1', []],
            default => throw new \InvalidArgumentException('Unknown change: '.$change),
        };
    }

    private function mustCommit(string $change): void
    {
        if (!$this->attempt($change, 15000)) {
            throw new \RuntimeException('An ordinary writer could not make its change: '.$change);
        }
    }
}
