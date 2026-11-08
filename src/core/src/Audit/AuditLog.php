<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Audit;

use Psr\Clock\ClockInterface;
use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\Receipt;

/**
 * An append-only security log for events outside an approval itself: passkeys enrolled and revoked,
 * connections revoked, enrollment links issued, alerts taken down. Each entry is canonical JSON that
 * names the hash of the entry before it, signed with the application's evidence key, which is kept
 * outside the database. Editing, reordering or deleting an entry breaks a signature or the chain.
 * Only the newest entries could be cut off unnoticed, so the audit view shows the newest sequence.
 */
final readonly class AuditLog
{
    public const FORMAT = 'verified-human-approval/audit/v1';

    public function __construct(
        private \PDO $pdo,
        private EvidenceSigner $signer,
        private string $trustedPublicKey,
        private ClockInterface $clock,
    ) {
    }

    public function initialize(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS verified_human_approval_audit (
            seq INTEGER PRIMARY KEY, tenant_id TEXT NOT NULL, type TEXT NOT NULL, subject TEXT NOT NULL,
            entry TEXT NOT NULL, hash TEXT NOT NULL, key_id TEXT NOT NULL, signature TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS verified_human_approval_audit_subject ON verified_human_approval_audit(type, subject)');
    }

    /**
     * Appends one entry, inside the caller's transaction if there is one.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> the entry as recorded
     */
    public function append(string $tenantId, string $type, string $subject, array $data): array
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            // The write reservation comes before reading the newest entry, so two writers cannot fork the chain.
            $this->pdo->exec('BEGIN IMMEDIATE');
        }
        try {
            $newest = $this->pdo->prepare('SELECT seq, hash FROM verified_human_approval_audit ORDER BY seq DESC LIMIT 1');
            $newest->execute();
            $last = $newest->fetch(\PDO::FETCH_ASSOC) ?: null;
            $seq = null === $last ? 1 : (int) $last['seq'] + 1;
            $entry = CanonicalJson::encode([
                'format' => self::FORMAT, 'seq' => $seq, 'at' => ApprovalRequest::timestamp($this->clock->now()),
                'tenantId' => $tenantId, 'type' => $type, 'subject' => $subject, 'data' => (object) $data,
                'previous' => null === $last ? null : $last['hash'],
            ]);
            $hash = hash('sha256', $entry);
            $this->pdo->prepare('INSERT INTO verified_human_approval_audit (seq, tenant_id, type, subject, entry, hash, key_id, signature) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$seq, $tenantId, $type, $subject, $entry, $hash, $this->signer->keyId(), $this->signer->sign($entry)]);
            if ($own) {
                $this->pdo->exec('COMMIT');
            }
        } catch (\Throwable $error) {
            if ($own) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $error;
        }

        return CanonicalJson::map(CanonicalJson::decode($entry));
    }

    /**
     * The newest entry of a type for a subject whose own hash and signature verify, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $type, string $subject): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM verified_human_approval_audit WHERE type = ? AND subject = ? ORDER BY seq DESC');
        $query->execute([$type, $subject]);
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $entry = $this->verified($row);
            if (null !== $entry) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Every entry, oldest first, each checked on its own and against the one before it.
     *
     * @return array{entries: list<array{entry: ?array<string, mixed>, seq: int, valid: bool, problem: ?string}>, intact: bool, newest: int}
     */
    public function verify(): array
    {
        $entries = [];
        $intact = true;
        $previous = null;
        $expected = 1;
        $rows = $this->pdo->prepare('SELECT * FROM verified_human_approval_audit ORDER BY seq');
        $rows->execute();
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $entry = $this->verified($row);
            $problem = match (true) {
                null === $entry => 'its signature or hash does not verify',
                (int) $row['seq'] !== $expected => 'an entry before it is missing',
                $entry['previous'] !== $previous => 'it does not follow the entry before it',
                default => null,
            };
            $intact = $intact && null === $problem;
            $entries[] = ['entry' => $entry, 'seq' => (int) $row['seq'], 'valid' => null === $problem, 'problem' => $problem];
            $previous = $row['hash'];
            $expected = (int) $row['seq'] + 1;
        }

        return ['entries' => $entries, 'intact' => $intact, 'newest' => $expected - 1];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function verified(array $row): ?array
    {
        if (!hash_equals(hash('sha256', $row['entry']), $row['hash']) || !Receipt::signatureValid($this->trustedPublicKey, $row['entry'], $row['signature'])) {
            return null;
        }
        try {
            $entry = CanonicalJson::map(CanonicalJson::decode($row['entry']));
        } catch (\Throwable) {
            return null;
        }
        if (($entry['format'] ?? null) !== self::FORMAT || ($entry['seq'] ?? null) !== (int) $row['seq']
            || ($entry['type'] ?? null) !== $row['type'] || ($entry['subject'] ?? null) !== $row['subject'] || ($entry['tenantId'] ?? null) !== $row['tenant_id']) {
            return null;
        }

        return $entry;
    }
}
