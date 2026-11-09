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
 *
 * This class builds, signs and checks entries; an AuditStore keeps the rows. One chain spans every tenant.
 *
 * @phpstan-import-type AuditHead from AuditStore
 * @phpstan-import-type AuditRow from AuditStore
 */
final readonly class AuditLog
{
    public const FORMAT = 'verified-human-approval/audit/v1';

    public function __construct(
        private AuditStore $store,
        private EvidenceSigner $signer,
        private string $trustedPublicKey,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Appends one entry, inside the caller's transaction if there is one. A failure leaves no entry, and
     * inside the caller's transaction it must roll back everything the entry belongs to.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> the entry as recorded
     */
    public function append(string $tenantId, string $type, string $subject, array $data): array
    {
        $row = $this->store->append(function (?array $head) use ($tenantId, $type, $subject, $data): array {
            /** @var AuditHead|null $head */
            $seq = null === $head ? 1 : $head['seq'] + 1;
            $entry = CanonicalJson::encode([
                'format' => self::FORMAT, 'seq' => $seq, 'at' => ApprovalRequest::timestamp($this->clock->now()),
                'tenantId' => $tenantId, 'type' => $type, 'subject' => $subject, 'data' => (object) $data,
                'previous' => null === $head ? null : $head['hash'],
            ]);

            return [
                'seq' => $seq, 'tenant_id' => $tenantId, 'type' => $type, 'subject' => $subject, 'entry' => $entry,
                'hash' => hash('sha256', $entry), 'key_id' => $this->signer->keyId(), 'signature' => $this->signer->sign($entry),
            ];
        });

        return CanonicalJson::map(CanonicalJson::decode($row['entry']));
    }

    /**
     * The newest entry of a type for a subject whose own hash and signature verify, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $type, string $subject): ?array
    {
        // A damaged newer entry is passed over, never trusted; an older one that verifies is returned.
        foreach ($this->store->recordsFor($type, $subject) as $row) {
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
        foreach ($this->store->records() as $row) {
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
     * The entry, if the row's hash and signature verify and the row's sequence, type, subject and tenant
     * match what was signed. The stored key id is not trusted: only the configured public key is.
     *
     * @param AuditRow $row
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
