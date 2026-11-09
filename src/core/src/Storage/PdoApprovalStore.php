<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Storage;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalHistory;
use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\ApprovalStore;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Ceremony;

/** Reference SQLite adapter. All protected application writers must use this same connection. */
final class PdoApprovalStore implements ApprovalStore, ApprovalHistory
{
    private bool $active = false;

    public function __construct(private readonly \PDO $pdo)
    {
        if ('sqlite' !== $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            throw new \InvalidArgumentException('This reference adapter supports SQLite only; supply another transactional adapter for other databases.');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 10000');
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

    public function transaction(callable $callback): mixed
    {
        if ($this->active || $this->pdo->inTransaction()) {
            throw new \LogicException('Approval execution must own the outer database transaction.');
        }
        // BEGIN IMMEDIATE obtains the write reservation before reading any authority/precondition.
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->active = true;
        try {
            $result = $callback();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $error) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
                // A failed commit may have an unknown outcome. ApprovalService reads persisted state.
            }
            throw $error;
        } finally {
            $this->active = false;
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
     * The newest requests first, for an administrator's audit view. The host filters them by tenant.
     *
     * @return list<ApprovalState>
     */
    public function recent(int $limit = 200): array
    {
        $statement = $this->pdo->prepare(self::SELECT.' ORDER BY r.rowid DESC LIMIT ?');
        $statement->bindValue(1, max(1, min($limit, 1000)), \PDO::PARAM_INT);
        $statement->execute();

        return array_values(array_map(self::state(...), $statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /**
     * The requests about one resource of one tenant, newest first, read from the frozen proposal itself
     * (`actor.tenantId` and `action.target.resource`), so no column or migration is needed.
     */
    public function forResource(string $tenantId, string $resource, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(self::SELECT." WHERE json_extract(r.proposal, '$.actor.tenantId') = ? AND CAST(json_extract(r.proposal, '$.action.target.resource') AS TEXT) = ? ORDER BY r.rowid DESC LIMIT ?");
        $statement->bindValue(1, $tenantId);
        $statement->bindValue(2, $resource);
        $statement->bindValue(3, max(1, min($limit, 1000)), \PDO::PARAM_INT);
        $statement->execute();

        return array_values(array_map(self::state(...), $statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    private const SELECT = 'SELECT r.*, e.record AS execution, c.key_id AS receipt_key_id, c.manifest AS receipt_manifest, c.signature AS receipt_signature
        FROM verified_human_approval_requests r LEFT JOIN verified_human_approval_executions e ON e.request_id = r.id LEFT JOIN verified_human_approval_receipts c ON c.request_id = r.id';

    /** @param array<string, mixed> $row */
    private static function state(array $row): ApprovalState
    {
        $decode = static fn (?string $json): ?array => null === $json ? null : CanonicalJson::map(CanonicalJson::decode($json));
        $receipt = null === $row['receipt_manifest'] ? null : ['manifest' => $row['receipt_manifest'], 'keyId' => $row['receipt_key_id'], 'signature' => $row['receipt_signature']];

        return new ApprovalState(new ApprovalRequest($row['id'], $row['proposal'], $row['digest']), $row['status'], $row['reason'], $decode($row['result']), $decode($row['execution']), $decode($row['proof']), $receipt);
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

        return false === $row ? null : new Ceremony($row['id'], $row['request_id'], $row['nonce'], $row['challenge'], $row['expires_at'], CanonicalJson::map(CanonicalJson::decode($row['options'])), (bool) $row['consumed']);
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
    }
}
