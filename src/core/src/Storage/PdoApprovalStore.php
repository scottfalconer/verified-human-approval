<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Storage;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalHistory;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\ApprovalStore;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Ceremony;
use VerifiedHumanApproval\Core\UnreadableApproval;

/** Reference SQLite adapter. All protected application writers must use this same connection. */
final class PdoApprovalStore implements ApprovalStore, ApprovalHistory
{
    /** Marks the transaction this store began, so that a write after it ended is refused: see releaseRoot(). */
    private const ROOT = 'verified_human_approval_root';

    private const THROWS = 'This adapter needs a connection that throws on errors: set PDO::ATTR_ERRMODE to PDO::ERRMODE_EXCEPTION.';

    private bool $active = false;

    /**
     * Works on the application's connection as it is, and changes none of its settings. One that does not throw on
     * errors (PDO::ERRMODE_EXCEPTION, PHP's default) is refused, here and at each transaction and write: a write that
     * failed quietly could be taken for one that happened. How long an approval waits for another writer is the
     * connection's busy timeout (PDO::ATTR_TIMEOUT, 60 seconds unless set).
     */
    public function __construct(private readonly \PDO $pdo)
    {
        if ('sqlite' !== $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            throw new \InvalidArgumentException('This reference adapter supports SQLite only; supply another transactional adapter for other databases.');
        }
        if (!$this->throwsOnErrors()) {
            throw new \InvalidArgumentException(self::THROWS);
        }
    }

    public function initialize(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS verified_human_approval_requests (
            id TEXT PRIMARY KEY, actor_key TEXT NOT NULL, idempotency_key TEXT,
            fingerprint TEXT NOT NULL, proposal TEXT NOT NULL, digest TEXT NOT NULL,
            status TEXT NOT NULL, reason TEXT, result TEXT, proof TEXT,
            UNIQUE(actor_key, idempotency_key)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS verified_human_approval_executions (
            request_id TEXT PRIMARY KEY REFERENCES verified_human_approval_requests(id), record TEXT NOT NULL
        )');
        // The application's receipt, signed in the completing transaction.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS verified_human_approval_receipts (
            request_id TEXT PRIMARY KEY REFERENCES verified_human_approval_requests(id), key_id TEXT NOT NULL, manifest TEXT NOT NULL, signature TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS verified_human_approval_ceremonies (
            id TEXT PRIMARY KEY, request_id TEXT NOT NULL REFERENCES verified_human_approval_requests(id),
            nonce TEXT NOT NULL, challenge TEXT NOT NULL, expires_at TEXT NOT NULL,
            options TEXT NOT NULL, consumed INTEGER NOT NULL DEFAULT 0
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS verified_human_approval_ceremony_request ON verified_human_approval_ceremonies(request_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS verified_human_approval_request_pending ON verified_human_approval_requests(actor_key, fingerprint, status)');
    }

    public function connection(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Begun through PDO, so that PDO reports it: the audit store, and the application's own code on this
     * connection, then join it instead of beginning another, which SQLite would refuse.
     */
    public function transaction(callable $callback): mixed
    {
        $nested = 'Approval execution must own the outer database transaction.';
        if ($this->active || $this->pdo->inTransaction()) {
            throw new \LogicException($nested);
        }
        if (!$this->throwsOnErrors()) {
            throw new \LogicException(self::THROWS);
        }
        try {
            $this->pdo->beginTransaction();
        } catch (\PDOException $error) {
            // Before PHP 8.4, PDO does not report a transaction begun with plain SQL; SQLite refuses the BEGIN.
            throw str_contains($error->getMessage(), 'within a transaction') ? new \LogicException($nested, 0, $error) : $error;
        }
        $this->active = true;
        try {
            // A write that changes nothing takes the write reservation, as BEGIN IMMEDIATE would, before any
            // authority or precondition is read, and holds it until the transaction ends.
            $this->pdo->exec('UPDATE verified_human_approval_requests SET id = id WHERE 0');
            $this->pdo->exec('SAVEPOINT '.self::ROOT);
            $result = $callback();
            $this->releaseRoot();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $error) {
            // A failed commit may have an unknown outcome. ApprovalService reads persisted state.
            $this->rollBack();
            throw $error;
        } finally {
            $this->active = false;
        }
    }

    /**
     * Rolls back, and leaves PDO and SQLite agreeing that no transaction is open. SQLite ends a transaction by
     * itself after some errors, such as a full disk; PDO before PHP 8.4 still counts it then, and would refuse
     * every later transaction on this connection.
     */
    private function rollBack(): void
    {
        try {
            $this->pdo->rollBack();
        } catch (\Throwable) {
            if (!$this->pdo->inTransaction()) {
                return;
            }
            try {
                // A transaction on SQLite alone, for PDO's rollback to end.
                $this->pdo->exec('BEGIN');
                $this->pdo->rollBack();
            } catch (\Throwable) {
                // The failure that ended the transaction is the one to report.
            }
        }
    }

    public function insert(ApprovalState $state, ?string $idempotencyKey, string $fingerprint): void
    {
        $this->assertTransaction();
        $request = $state->request;
        $this->pdo->prepare('INSERT INTO verified_human_approval_requests (id, actor_key, idempotency_key, fingerprint, proposal, digest, status) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            $request->id, $request->actor()->key(), null === $idempotencyKey ? null : hash('sha256', $idempotencyKey), $fingerprint, $request->canonical, $request->digest, $state->status,
        ]);
    }

    public function get(string $id): ?ApprovalState
    {
        $statement = $this->pdo->prepare(self::SELECT.' WHERE r.id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return false === $row ? null : self::state($row);
    }

    /**
     * The newest requests first, for an administrator's audit view. The host filters them by tenant: a request that
     * cannot be read back is an UnreadableApproval, with the tenant its stored proposal still names, if any.
     *
     * @return list<ApprovalState|UnreadableApproval>
     */
    public function recent(int $limit = 200): array
    {
        $statement = $this->pdo->prepare(self::SELECT.' ORDER BY r.rowid DESC LIMIT ?');
        $statement->bindValue(1, max(1, min($limit, 1000)), \PDO::PARAM_INT);
        $statement->execute();

        return array_values(array_map(self::listed(...), $statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /**
     * The requests about one resource of one tenant, newest first, read from the frozen proposal itself
     * (`actor.tenantId` and `action.target.resource`), so no column or migration is needed. A proposal that is
     * not JSON names no resource, so it is in no history; recent() still lists it.
     */
    public function forResource(string $tenantId, string $resource, int $limit = 50): array
    {
        // json_extract() fails the whole query on one row that is not JSON, so it reads only rows that are.
        $statement = $this->pdo->prepare(self::SELECT." WHERE CASE WHEN json_valid(r.proposal) THEN json_extract(r.proposal, '$.actor.tenantId') = ? AND CAST(json_extract(r.proposal, '$.action.target.resource') AS TEXT) = ? ELSE 0 END ORDER BY r.rowid DESC LIMIT ?");
        $statement->bindValue(1, $tenantId);
        $statement->bindValue(2, $resource);
        $statement->bindValue(3, max(1, min($limit, 1000)), \PDO::PARAM_INT);
        $statement->execute();

        return array_values(array_map(self::listed(...), $statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    private const SELECT = 'SELECT r.*, e.record AS execution, c.key_id AS receipt_key_id, c.manifest AS receipt_manifest, c.signature AS receipt_signature
        FROM verified_human_approval_requests r LEFT JOIN verified_human_approval_executions e ON e.request_id = r.id LEFT JOIN verified_human_approval_receipts c ON c.request_id = r.id';

    /**
     * @param array<string, mixed> $row
     *
     * @throws ApprovalException record_integrity_error when the row cannot be read back
     */
    private static function state(array $row): ApprovalState
    {
        $text = static fn (mixed $value): ?string => null === $value ? null : (string) $value;
        $receipt = null === $row['receipt_manifest'] ? null : ['manifest' => (string) $row['receipt_manifest'], 'keyId' => (string) $row['receipt_key_id'], 'signature' => (string) $row['receipt_signature']];

        return ApprovalState::fromStorage((string) $row['id'], (string) $row['proposal'], (string) $row['digest'], (string) $row['status'], $text($row['reason']), $text($row['result']), $text($row['execution']), $text($row['proof']), $receipt);
    }

    /** @param array<string, mixed> $row */
    private static function listed(array $row): ApprovalState|UnreadableApproval
    {
        try {
            return self::state($row);
        } catch (ApprovalException $error) {
            return new UnreadableApproval((string) $row['id'], self::tenantNamedIn($row['proposal']), $error);
        }
    }

    /** The tenant a damaged proposal still names, read without trusting it, so a host can decide who sees the row. */
    private static function tenantNamedIn(mixed $proposal): ?string
    {
        try {
            $tenant = CanonicalJson::decode((string) $proposal)->actor->tenantId ?? null;
        } catch (\JsonException) {
            return null;
        }

        return \is_string($tenant) ? $tenant : null;
    }

    public function findByKey(Actor $actor, string $idempotencyKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, fingerprint FROM verified_human_approval_requests WHERE actor_key = ? AND idempotency_key = ?');
        $statement->execute([$actor->key(), hash('sha256', $idempotencyKey)]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (false === $row) {
            return null;
        }
        $state = $this->get($row['id']);

        return null === $state ? null : ['state' => $state, 'fingerprint' => $row['fingerprint']];
    }

    public function findPending(Actor $actor, string $fingerprint): ?ApprovalState
    {
        $statement = $this->pdo->prepare("SELECT id FROM verified_human_approval_requests WHERE actor_key = ? AND fingerprint = ? AND status = 'pending' ORDER BY rowid DESC LIMIT 1");
        $statement->execute([$actor->key(), $fingerprint]);
        $id = $statement->fetchColumn();

        return false === $id ? null : $this->get((string) $id);
    }

    public function save(ApprovalState $state): void
    {
        $this->assertTransaction();
        $current = $this->get($state->request->id) ?? throw new \LogicException('Cannot update a missing approval.');
        if ($current->request->canonical !== $state->request->canonical || 'pending' !== $current->status) {
            throw new ApprovalException('immutable_approval', 'A frozen proposal or terminal outcome cannot be overwritten.');
        }
        if ('completed' === $state->status) {
            if (null === $state->execution || null === $state->result) {
                throw new \LogicException('Completion requires a durable result and execution record.');
            }
            $this->pdo->prepare('INSERT INTO verified_human_approval_executions (request_id, record) VALUES (?, ?)')->execute([$state->request->id, CanonicalJson::encode((object) $state->execution)]);
            if (null !== $state->receipt) {
                $this->pdo->prepare('INSERT INTO verified_human_approval_receipts (request_id, key_id, manifest, signature) VALUES (?, ?, ?, ?)')
                    ->execute([$state->request->id, $state->receipt['keyId'], $state->receipt['manifest'], $state->receipt['signature']]);
            }
        }
        $this->pdo->prepare('UPDATE verified_human_approval_requests SET status = ?, reason = ?, result = ?, proof = ? WHERE id = ? AND status = ?')->execute([
            $state->status, $state->reason,
            null === $state->result ? null : CanonicalJson::encode((object) $state->result),
            null === $state->proof ? null : CanonicalJson::encode((object) $state->proof),
            $state->request->id, 'pending',
        ]);
    }

    public function putCeremony(Ceremony $ceremony): void
    {
        $this->assertTransaction();
        // Earlier unfinished challenges stay valid until they expire: each is single use and tied to the
        // browser session that started it, so a second session starting a review cannot cancel the first.
        $this->pdo->prepare('INSERT INTO verified_human_approval_ceremonies (id, request_id, nonce, challenge, expires_at, options, consumed) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            $ceremony->id, $ceremony->requestId, $ceremony->nonce, $ceremony->challenge, $ceremony->expiresAt, CanonicalJson::encode((object) $ceremony->options), (int) $ceremony->consumed,
        ]);
    }

    public function getCeremony(string $id): ?Ceremony
    {
        $statement = $this->pdo->prepare('SELECT * FROM verified_human_approval_ceremonies WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return false === $row ? null : Ceremony::fromStorage((string) $row['id'], (string) $row['request_id'], (string) $row['nonce'], (string) $row['challenge'], (string) $row['expires_at'], (string) $row['options'], (bool) $row['consumed']);
    }

    public function retireCeremony(string $id): void
    {
        $this->assertTransaction();
        $this->pdo->prepare('UPDATE verified_human_approval_ceremonies SET consumed = 1 WHERE id = ?')->execute([$id]);
    }

    private function assertTransaction(): void
    {
        if (!$this->active) {
            throw new \LogicException('Approval writes require the application transaction.');
        }
        $this->releaseRoot();
        $this->pdo->exec('SAVEPOINT '.self::ROOT);
    }

    private function throwsOnErrors(): bool
    {
        return \PDO::ERRMODE_EXCEPTION === $this->pdo->getAttribute(\PDO::ATTR_ERRMODE);
    }

    /**
     * Releases the savepoint that marks this store's transaction, which fails once that transaction has ended:
     * committed or rolled back by code on the connection, through PDO or in SQL, rolled back by SQLite itself after
     * an error, or replaced by another. PDO before PHP 8.4 notices none of these done in SQL, and each write after
     * them would commit on its own.
     */
    private function releaseRoot(): void
    {
        // Code on the connection can switch it to quiet errors at any time, and the release would then fail unseen.
        if (!$this->throwsOnErrors()) {
            throw new \LogicException(self::THROWS);
        }
        try {
            $this->pdo->exec('RELEASE SAVEPOINT '.self::ROOT);
        } catch (\PDOException $error) {
            throw new \LogicException('The approval transaction was committed, rolled back or replaced before the approval finished, so nothing more is written in it.', 0, $error);
        }
    }
}
