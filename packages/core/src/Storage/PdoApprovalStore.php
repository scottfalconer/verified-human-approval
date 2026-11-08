<?php

declare(strict_types=1);

namespace ExactApproval\Core\Storage;

use ExactApproval\Core\{Actor, ApprovalException, ApprovalRequest, ApprovalState, ApprovalStore, CanonicalJson, Ceremony};

/** Reference SQLite adapter. All protected application writers must use this same connection. */
final class PdoApprovalStore implements ApprovalStore
{
    private bool $active = false;

    public function __construct(private readonly \PDO $pdo)
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \InvalidArgumentException('This reference adapter supports SQLite only; supply another transactional adapter for other databases.');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 10000');
    }

    public function initialize(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS exact_approval_requests (
            id TEXT PRIMARY KEY, actor_key TEXT NOT NULL, idempotency_key TEXT,
            fingerprint TEXT NOT NULL, proposal TEXT NOT NULL, digest TEXT NOT NULL,
            status TEXT NOT NULL, reason TEXT, result TEXT, proof TEXT,
            UNIQUE(actor_key, idempotency_key)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS exact_approval_executions (
            request_id TEXT PRIMARY KEY REFERENCES exact_approval_requests(id), record TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS exact_approval_ceremonies (
            id TEXT PRIMARY KEY, request_id TEXT NOT NULL REFERENCES exact_approval_requests(id),
            nonce TEXT NOT NULL, challenge TEXT NOT NULL, expires_at TEXT NOT NULL,
            options TEXT NOT NULL, consumed INTEGER NOT NULL DEFAULT 0
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS exact_approval_ceremony_request ON exact_approval_ceremonies(request_id)');
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
        $this->pdo->prepare('INSERT INTO exact_approval_requests (id, actor_key, idempotency_key, fingerprint, proposal, digest, status) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            $request->id, $request->actor()->key(), $idempotencyKey === null ? null : hash('sha256', $idempotencyKey), $fingerprint, $request->canonical, $request->digest, $state->status,
        ]);
    }

    public function get(string $id): ?ApprovalState
    {
        $statement = $this->pdo->prepare('SELECT r.*, e.record AS execution FROM exact_approval_requests r LEFT JOIN exact_approval_executions e ON e.request_id = r.id WHERE r.id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $decode = static fn (?string $json): ?array => $json === null ? null : CanonicalJson::map(CanonicalJson::decode($json));
        return new ApprovalState(new ApprovalRequest($row['id'], $row['proposal'], $row['digest']), $row['status'], $row['reason'], $decode($row['result']), $decode($row['execution']), $decode($row['proof']));
    }

    public function findByKey(Actor $actor, string $idempotencyKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, fingerprint FROM exact_approval_requests WHERE actor_key = ? AND idempotency_key = ?');
        $statement->execute([$actor->key(), hash('sha256', $idempotencyKey)]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : ['state' => $this->get($row['id']), 'fingerprint' => $row['fingerprint']];
    }

    public function save(ApprovalState $state): void
    {
        $this->assertTransaction();
        $current = $this->get($state->request->id) ?? throw new \LogicException('Cannot update a missing approval.');
        if ($current->request->canonical !== $state->request->canonical || $current->status !== 'pending') {
            throw new ApprovalException('immutable_approval', 'A frozen proposal or terminal outcome cannot be overwritten.');
        }
        if ($state->status === 'completed') {
            if ($state->execution === null || $state->result === null) {
                throw new \LogicException('Completion requires a durable result and execution record.');
            }
            $this->pdo->prepare('INSERT INTO exact_approval_executions (request_id, record) VALUES (?, ?)')->execute([$state->request->id, CanonicalJson::encode((object) $state->execution)]);
        }
        $this->pdo->prepare('UPDATE exact_approval_requests SET status = ?, reason = ?, result = ?, proof = ? WHERE id = ? AND status = ?')->execute([
            $state->status, $state->reason,
            $state->result === null ? null : CanonicalJson::encode((object) $state->result),
            $state->proof === null ? null : CanonicalJson::encode((object) $state->proof),
            $state->request->id, 'pending',
        ]);
    }

    public function putCeremony(Ceremony $ceremony): void
    {
        $this->assertTransaction();
        // A new explicit review attempt supersedes older unfinished challenges for this proposal.
        $this->pdo->prepare('UPDATE exact_approval_ceremonies SET consumed = 1 WHERE request_id = ?')->execute([$ceremony->requestId]);
        $this->pdo->prepare('INSERT INTO exact_approval_ceremonies (id, request_id, nonce, challenge, expires_at, options, consumed) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            $ceremony->id, $ceremony->requestId, $ceremony->nonce, $ceremony->challenge, $ceremony->expiresAt, CanonicalJson::encode((object) $ceremony->options), (int) $ceremony->consumed,
        ]);
    }

    public function getCeremony(string $id): ?Ceremony
    {
        $statement = $this->pdo->prepare('SELECT * FROM exact_approval_ceremonies WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new Ceremony($row['id'], $row['request_id'], $row['nonce'], $row['challenge'], $row['expires_at'], CanonicalJson::map(CanonicalJson::decode($row['options'])), (bool) $row['consumed']);
    }

    public function retireCeremony(string $id): void
    {
        $this->assertTransaction();
        $this->pdo->prepare('UPDATE exact_approval_ceremonies SET consumed = 1 WHERE id = ?')->execute([$id]);
    }

    private function assertTransaction(): void
    {
        if (!$this->active) {
            throw new \LogicException('Approval writes require the application transaction.');
        }
    }
}
