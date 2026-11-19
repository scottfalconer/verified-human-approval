<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Doctrine;

use Doctrine\DBAL\Connection;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalHistory;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\ApprovalStore;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Ceremony;
use VerifiedHumanApproval\Core\UnreadableApproval;

/**
 * Proposals, ceremonies and their outcomes on the application's DBAL connection, with PdoApprovalStore's rules.
 *
 * Inside the approval transaction on PostgreSQL, MySQL and MariaDB, reading a request locks its row, reading a
 * ceremony locks that row, and looking up an earlier request locks the agent connection's request stripe, so
 * completions and requests that race wait for each other and then read what the other committed. SQLite needs
 * none of this: the transaction holds the database's write lock. Every read goes to the primary database.
 */
final readonly class DbalApprovalStore implements ApprovalStore, ApprovalHistory
{
    private const SELECT = 'SELECT r.id, r.tenant_id, r.proposal, r.digest, r.status, r.reason, r.result, r.proof, e.record AS execution, c.key_id AS receipt_key_id, c.manifest AS receipt_manifest, c.signature AS receipt_signature
        FROM '.ApprovalSchema::REQUESTS.' r LEFT JOIN '.ApprovalSchema::EXECUTIONS.' e ON e.request_id = r.id LEFT JOIN '.ApprovalSchema::RECEIPTS.' c ON c.request_id = r.id';

    private Connection $connection;

    public function __construct(private DbalTransaction $transaction)
    {
        $this->connection = $transaction->connection();
    }

    public function transaction(callable $callback): mixed
    {
        return $this->transaction->run($callback);
    }

    public function insert(ApprovalState $state, ?string $idempotencyKey, string $fingerprint): void
    {
        $this->transaction->assertActive();
        $request = $state->request;
        $this->connection->insert(ApprovalSchema::REQUESTS, [
            'id' => $request->id, 'actor_key' => $request->actor()->key(),
            'idempotency_key' => null === $idempotencyKey ? null : hash('sha256', $idempotencyKey),
            'fingerprint' => $fingerprint, 'tenant_id' => $request->actor()->tenantId,
            'resource_key' => self::resourceKey($request->prepared()->target['resource'] ?? null),
            'proposal' => $request->canonical, 'digest' => $request->digest, 'status' => $state->status,
        ]);
    }

    public function get(string $id): ?ApprovalState
    {
        $this->transaction->usePrimary();
        if ($this->locking()) {
            // Wait for any transaction completing this request; the read below then sees what it committed.
            $this->connection->fetchOne('SELECT id FROM '.ApprovalSchema::REQUESTS.' WHERE id = ? '.$this->transaction->forUpdate(), [$id]);
        }
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE r.id = ?', [$id]);

        return false === $row || $row['id'] !== $id ? null : self::state($row);
    }

    public function findByKey(Actor $actor, string $idempotencyKey): ?array
    {
        $this->lockRequestsBy($actor);
        $row = $this->connection->fetchAssociative('SELECT id, actor_key, idempotency_key, fingerprint FROM '.ApprovalSchema::REQUESTS.' WHERE actor_key = ? AND idempotency_key = ?', [
            $actor->key(), hash('sha256', $idempotencyKey),
        ]);
        if (false === $row || $row['actor_key'] !== $actor->key() || $row['idempotency_key'] !== hash('sha256', $idempotencyKey)) {
            return null;
        }
        $state = $this->get((string) $row['id']);

        return null === $state ? null : ['state' => $state, 'fingerprint' => (string) $row['fingerprint']];
    }

    public function findPending(Actor $actor, string $fingerprint): ?ApprovalState
    {
        $this->lockRequestsBy($actor);
        $sql = $this->connection->getDatabasePlatform()->modifyLimitQuery(
            'SELECT id FROM '.ApprovalSchema::REQUESTS." WHERE actor_key = ? AND fingerprint = ? AND status = 'pending' ORDER BY seq DESC",
            1,
        );
        $id = $this->connection->fetchOne($sql, [$actor->key(), $fingerprint]);

        return false === $id ? null : $this->get((string) $id);
    }

    public function save(ApprovalState $state): void
    {
        $this->transaction->assertActive();
        $current = $this->get($state->request->id) ?? throw new \LogicException('Cannot update a missing approval.');
        if ('pending' !== $current->status || $current->request->canonical !== $state->request->canonical || $current->request->digest !== $state->request->digest) {
            throw new ApprovalException('immutable_approval', 'A frozen proposal or terminal outcome cannot be overwritten.');
        }
        if ('completed' === $state->status) {
            if (null === $state->execution || null === $state->result) {
                throw new \LogicException('Completion requires a durable result and execution record.');
            }
            $this->connection->insert(ApprovalSchema::EXECUTIONS, ['request_id' => $state->request->id, 'record' => CanonicalJson::encode((object) $state->execution)]);
            if (null !== $state->receipt) {
                $this->connection->insert(ApprovalSchema::RECEIPTS, [
                    'request_id' => $state->request->id, 'key_id' => $state->receipt['keyId'],
                    'manifest' => $state->receipt['manifest'], 'signature' => $state->receipt['signature'],
                ]);
            }
        }
        $changed = $this->connection->executeStatement('UPDATE '.ApprovalSchema::REQUESTS." SET status = ?, reason = ?, result = ?, proof = ? WHERE id = ? AND status = 'pending'", [
            $state->status, $state->reason,
            null === $state->result ? null : CanonicalJson::encode((object) $state->result),
            null === $state->proof ? null : CanonicalJson::encode((object) $state->proof),
            $state->request->id,
        ]);
        // The row is locked and was pending, and its status changes, so every database reports one changed row.
        if ('pending' !== $state->status && 1 !== (int) $changed) {
            throw new ApprovalException('immutable_approval', 'A frozen proposal or terminal outcome cannot be overwritten.');
        }
    }

    public function putCeremony(Ceremony $ceremony): void
    {
        $this->transaction->assertActive();
        // Earlier unfinished challenges stay valid until they expire: each is single use and tied to the
        // browser session that started it, so a second session starting a review cannot cancel the first.
        $this->connection->insert(ApprovalSchema::CEREMONIES, [
            'id' => $ceremony->id, 'request_id' => $ceremony->requestId, 'nonce' => $ceremony->nonce,
            'challenge' => $ceremony->challenge, 'expires_at' => $ceremony->expiresAt,
            'options' => CanonicalJson::encode((object) $ceremony->options), 'consumed' => (int) $ceremony->consumed,
        ]);
    }

    public function getCeremony(string $id): ?Ceremony
    {
        $this->transaction->usePrimary();
        // Locked, so a completion never reads a ceremony as unused while another attempt is retiring it.
        $lock = $this->locking() ? ' '.$this->transaction->forUpdate() : '';
        $row = $this->connection->fetchAssociative('SELECT id, request_id, nonce, challenge, expires_at, options, consumed FROM '.ApprovalSchema::CEREMONIES.' WHERE id = ?'.$lock, [$id]);
        if (false === $row || $row['id'] !== $id) {
            return null;
        }

        return Ceremony::fromStorage((string) $row['id'], (string) $row['request_id'], (string) $row['nonce'], (string) $row['challenge'], (string) $row['expires_at'], (string) $row['options'], (bool) (int) $row['consumed']);
    }

    public function retireCeremony(string $id): void
    {
        $this->transaction->assertActive();
        $this->connection->executeStatement('UPDATE '.ApprovalSchema::CEREMONIES.' SET consumed = 1 WHERE id = ?', [$id]);
    }

    /**
     * The requests about one resource of one tenant, newest first: the `resource` entry of the prepared target, as a
     * string or an integer (1 and "1" name the same resource). The records outlive the resource.
     */
    public function forResource(string $tenantId, string $resource, int $limit = 50): array
    {
        $this->transaction->usePrimary();
        $sql = $this->connection->getDatabasePlatform()->modifyLimitQuery(self::SELECT.' WHERE r.tenant_id = ? AND r.resource_key = ? ORDER BY r.seq DESC', max(1, min($limit, 1000)));
        $listed = array_map(self::listed(...), $this->connection->fetchAllAssociative($sql, [$tenantId, self::resourceKey($resource)]));

        // A row whose proposal reads back must name this resource itself, not only in the columns. One whose proposal
        // cannot be read stays where its columns put it: it shows no more than that it cannot be read.
        return array_values(array_filter($listed, static function (ApprovalState|UnreadableApproval $entry) use ($tenantId, $resource): bool {
            $request = $entry instanceof UnreadableApproval ? $entry->error->request : $entry->request;

            return null === $request || ($request->actor()->tenantId === $tenantId && self::resourceKey($request->prepared()->target['resource'] ?? null) === self::resourceKey($resource));
        }));
    }

    /**
     * The newest requests first, for an administrator's audit view. The host filters them by tenant: a request that
     * cannot be read back is an UnreadableApproval, with its proposal's tenant while that reads back, otherwise the
     * tenant its row is filed under.
     *
     * @return list<ApprovalState|UnreadableApproval>
     */
    public function recent(int $limit = 200): array
    {
        $this->transaction->usePrimary();
        $sql = $this->connection->getDatabasePlatform()->modifyLimitQuery(self::SELECT.' ORDER BY r.seq DESC', max(1, min($limit, 1000)));

        return array_map(self::listed(...), $this->connection->fetchAllAssociative($sql));
    }

    /** Whether reads lock rows: inside the approval transaction, on a database without SQLite's single writer. */
    private function locking(): bool
    {
        return $this->transaction->isActive() && $this->transaction->dialect()->locksRows();
    }

    /**
     * Requests by one agent connection with the same key, or the same arguments and no key, are made one at a time,
     * so the second finds the first's proposal instead of making another.
     */
    private function lockRequestsBy(Actor $actor): void
    {
        $this->transaction->usePrimary();
        if (!$this->locking()) {
            return;
        }
        $name = ApprovalSchema::requestLock($actor->key());
        if ($name !== $this->connection->fetchOne('SELECT name FROM '.ApprovalSchema::LOCKS.' WHERE name = ? '.$this->transaction->forUpdate(), [$name])) {
            throw new \LogicException('The approval lock table is missing its rows: run ApprovalSchema::install() again.');
        }
    }

    /** The indexed form of a target's resource: a digest of its string form, for strings and integers only. */
    private static function resourceKey(mixed $resource): ?string
    {
        return \is_string($resource) || \is_int($resource) ? hash('sha256', (string) $resource) : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws ApprovalException record_integrity_error when the row cannot be read back
     */
    private static function state(array $row): ApprovalState
    {
        $text = static fn (mixed $value): ?string => null === $value ? null : (string) $value;
        $receipt = null === $row['receipt_manifest'] ? null : ['manifest' => (string) $row['receipt_manifest'], 'keyId' => (string) $row['receipt_key_id'], 'signature' => (string) $row['receipt_signature']];

        return ApprovalState::fromStorage(
            (string) $row['id'],
            (string) $row['proposal'],
            (string) $row['digest'],
            (string) $row['status'],
            $text($row['reason']),
            $text($row['result']),
            $text($row['execution']),
            $text($row['proof']),
            $receipt,
        );
    }

    /** @param array<string, mixed> $row */
    private static function listed(array $row): ApprovalState|UnreadableApproval
    {
        try {
            return self::state($row);
        } catch (ApprovalException $error) {
            return new UnreadableApproval((string) $row['id'], $error->request?->actor()->tenantId ?? (null === $row['tenant_id'] ? null : (string) $row['tenant_id']), $error);
        }
    }
}
