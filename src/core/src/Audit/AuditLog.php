<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Audit;

use Psr\Clock\ClockInterface;
use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;

/**
 * An append-only security log for events outside an approval itself: passkeys enrolled and revoked,
 * connections revoked, enrollment links issued, alerts taken down, and the application's signing key
 * changing. Each entry is canonical JSON that names the hash of the entry before it, signed with the
 * application's evidence key, which is kept outside the database. Editing, reordering or deleting an entry
 * breaks a signature or the chain. Only the newest entries could be cut off unnoticed; a checkpoint of the
 * head (head()) kept where the application cannot write exposes that too.
 *
 * Entries are checked against the application's TrustedKeys. A row names its key; a key the set does not
 * list verifies nothing, and an entry whose time ("at") falls outside its key's window is refused. Once the
 * chain records a rotation away from a key (recordRotation()), an entry after it signed with that key is
 * refused too, whatever time it claims. And once a key's window has closed, what it signed counts only while a
 * later entry signed with a key in use links back to it through the chain: the new key vouches for the old
 * key's entries as they stood when it first signed, so record the rotation as soon as the new key signs.
 *
 * This class builds, signs and checks entries; an AuditStore keeps the rows. One chain spans every tenant.
 *
 * @phpstan-import-type AuditHead from AuditStore
 * @phpstan-import-type AuditRecord from AuditStore
 */
final readonly class AuditLog
{
    public const FORMAT = 'verified-human-approval/audit/v1';
    /**
     * The application's signing key changed. The entry's subject and data.from name the old key, data.to
     * and data.publicKey the new one, which signs the entry; data.reason says why, when the operator did.
     */
    public const KEY_ROTATED = 'signing_key.rotated';
    /** The tenant of an entry about the whole application, such as a key rotation: it concerns every tenant. */
    public const ALL_TENANTS = '';

    private TrustedKeys $keys;

    /**
     * @param TrustedKeys|string $trustedPublicKey the keys that check entries. One base64url public key, as
     *                                             before, is a one-key set for the signer's key id, without a window.
     */
    public function __construct(
        private AuditStore $store,
        private EvidenceSigner $signer,
        TrustedKeys|string $trustedPublicKey,
        private ClockInterface $clock,
    ) {
        $this->keys = $trustedPublicKey instanceof TrustedKeys ? $trustedPublicKey : TrustedKeys::single($signer->keyId(), $trustedPublicKey);
    }

    /** The keys this log checks entries against, with their windows. */
    public function trustedKeys(): TrustedKeys
    {
        return $this->keys;
    }

    /**
     * Appends one entry, inside the caller's transaction if there is one. A failure leaves no entry, and
     * inside the caller's transaction it must roll back everything the entry belongs to. The signer must be
     * one of the trusted keys, trusted now: an entry its own log would refuse is never written.
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
            $now = $this->clock->now();
            $keyId = $this->signer->keyId();
            $key = $this->keys->get($keyId);
            if (null === $key || !hash_equals($key->publicKey, $this->signer->publicKey()) || !$key->covers($now)) {
                throw new \LogicException(\sprintf('The signing key "%s" is not one of the trusted keys at this time, so its entries would not verify. List it in the trusted keys with a window that includes now.', $keyId));
            }
            $entry = CanonicalJson::encode([
                'format' => self::FORMAT, 'seq' => $seq, 'at' => ApprovalRequest::timestamp($now),
                'tenantId' => $tenantId, 'type' => $type, 'subject' => $subject, 'data' => (object) $data,
                'previous' => null === $head ? null : $head['hash'],
            ]);

            return [
                'seq' => $seq, 'tenant_id' => $tenantId, 'type' => $type, 'subject' => $subject, 'entry' => $entry,
                'hash' => hash('sha256', $entry), 'key_id' => $keyId, 'signature' => $this->signer->sign($entry),
            ];
        });

        return CanonicalJson::map(CanonicalJson::decode($row['entry']));
    }

    /**
     * Records that the application's signing key moved from $fromKeyId to the current signer, so the chain
     * shows where trust moved. Record it once every host signs with the new key: the entry is signed with
     * it, and from this entry on an entry signed with the old key is refused, whatever time it claims.
     *
     * The trusted keys must already close the old key's window (validUntil) and open the new one's
     * (validFrom): receipts are not in the chain, so only the old key's window stops one it signs later.
     *
     * @return array<string, mixed> the entry as recorded
     */
    public function recordRotation(string $fromKeyId, string $reason = ''): array
    {
        $to = $this->signer->keyId();
        $old = $this->keys->get($fromKeyId) ?? throw new \InvalidArgumentException(\sprintf('The key "%s" is not one of the trusted keys.', $fromKeyId));
        $new = $this->keys->get($to) ?? throw new \LogicException(\sprintf('The signing key "%s" is not one of the trusted keys.', $to));
        if ($old->keyId === $new->keyId || hash_equals($old->publicKey, $new->publicKey)) {
            throw new \InvalidArgumentException(\sprintf('A rotation moves to a new key, but the signer still uses key "%s".', $fromKeyId));
        }
        if (null === $old->validUntil) {
            throw new \LogicException(\sprintf('Close the window of key "%s" first: give it a validUntil, or what it signs later still verifies.', $fromKeyId));
        }
        if (null === $new->validFrom) {
            throw new \LogicException(\sprintf('Open the window of key "%s" first: give it a validFrom, so nothing it signs can claim an earlier time.', $to));
        }
        if (null !== ($seq = $this->rotatedAway($fromKeyId))) {
            throw new \LogicException(\sprintf('Entry %d already records the rotation away from key "%s".', $seq, $fromKeyId));
        }
        if (null !== ($seq = $this->rotatedAway($to))) {
            throw new \LogicException(\sprintf('Entry %d rotated away from key "%s": rotate to a new key instead of returning to it.', $seq, $to));
        }
        $data = ['from' => $fromKeyId, 'to' => $to, 'publicKey' => $new->publicKey];

        return $this->append(self::ALL_TENANTS, self::KEY_ROTATED, $fromKeyId, '' === $reason ? $data : $data + ['reason' => $reason]);
    }

    /**
     * The newest entry of a type for a subject that the trusted keys vouch for, or null. Its own hash, signature
     * and key must verify. If its key's window has closed, as after a rotation, an entry signed with a key still
     * in use must also vouch for it: a later entry, signed with such a key, that the chain links back to it.
     * So once the new key has signed anything, such as the rotation entry, a stolen copy of the old key cannot
     * swap in an entry of its own from before then, nor add one at the end.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $type, string $subject): ?array
    {
        $now = $this->clock->now();
        // A damaged newer entry is passed over, never trusted; an older one that verifies is returned.
        foreach ($this->store->recordsFor($type, $subject) as $row) {
            ['entry' => $entry, 'problem' => $problem] = $this->check($row);
            if (null === $entry || null !== $problem || $this->signedAfterRotation($row['key_id'], (int) $row['seq'])) {
                continue;
            }
            if ($this->inUse($row['key_id'], $now) || $this->vouchedFor($row, $now)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Every entry, oldest first, each checked on its own and against the one before it. Each names the key
     * its row says signed it and its hash; the entry itself is there once its signature verifies. "unvouched"
     * marks an entry signed with a key whose window has closed that no later entry signed with a key in use
     * vouches for, so find() does not count it (see unvouchedKeys()).
     *
     * @return array{entries: list<array{entry: ?array<string, mixed>, seq: int, valid: bool, problem: ?string, keyId: string, hash: string, unvouched: bool}>, intact: bool, newest: int}
     */
    public function verify(): array
    {
        $checked = [];
        $previous = null;
        $expected = 1;
        $now = $this->clock->now();
        // Where the chain moved trust away from each key: by key id, the sequence of that rotation.
        $rotatedAway = [];
        foreach ($this->store->records() as $row) {
            ['entry' => $entry, 'problem' => $problem] = $this->check($row);
            if (null === $problem && null !== $entry) {
                $problem = match (true) {
                    (int) $row['seq'] !== $expected => 'an entry before it is missing',
                    $entry['previous'] !== $previous => 'it does not follow the entry before it',
                    isset($rotatedAway[$row['key_id']]) => \sprintf('it is signed with key "%s", which entry %d rotated away from', $row['key_id'], $rotatedAway[$row['key_id']]),
                    default => null,
                };
                if (null === $problem && self::KEY_ROTATED === $entry['type']) {
                    $rotatedAway[$entry['subject']] ??= (int) $row['seq'];
                }
            }
            $checked[] = ['row' => $row, 'entry' => $entry, 'problem' => $problem, 'follows' => self::follows($row, $previous, $expected), 'inUse' => $this->inUse($row['key_id'], $now)];
            $previous = $row['hash'];
            $expected = (int) $row['seq'] + 1;
        }

        // From the newest back: whether a later entry signed with a key in use links back to each entry, which
        // find() requires of one whose key's window has closed. A row that does not link to the one before it
        // cuts everything before it off from what comes later.
        $unvouched = [];
        $vouched = false;
        for ($position = \count($checked) - 1; $position >= 0; --$position) {
            $item = $checked[$position];
            if (null === $item['problem'] && !$item['inUse'] && !$vouched) {
                $unvouched[$position] = true;
            }
            $vouched = $item['follows'] && ((null === $item['problem'] && $item['inUse']) || $vouched);
        }
        $entries = [];
        foreach ($checked as $position => $item) {
            $problem = isset($unvouched[$position])
                ? \sprintf('it is signed with key "%s", whose window has closed, and no later entry signed with a key in use vouches for it', $item['row']['key_id'])
                : $item['problem'];
            $entries[] = ['entry' => $item['entry'], 'seq' => (int) $item['row']['seq'], 'valid' => null === $problem, 'problem' => $problem, 'keyId' => $item['row']['key_id'], 'hash' => $item['row']['hash'], 'unvouched' => isset($unvouched[$position])];
        }

        return ['entries' => $entries, 'intact' => [] === array_filter($entries, static fn (array $item): bool => !$item['valid']), 'newest' => $expected - 1];
    }

    /**
     * The keys whose window has closed and whose entries nothing in use vouches for yet, by id. Until something
     * does, find() counts nothing they signed, so passkeys enrolled under them cannot approve. Usually the
     * rotation away from such a key has not been recorded yet: recordRotation() ends it. If it has been, the
     * chain is broken after those entries, and verify() says where.
     *
     * @return list<string>
     */
    public function unvouchedKeys(): array
    {
        $keys = [];
        foreach ($this->verify()['entries'] as $item) {
            if ($item['unvouched']) {
                $keys[$item['keyId']] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * What is wrong with a checkpoint kept earlier, the sequence and hash head() gave, or null when it holds: the
     * entry at that sequence is unchanged and the whole chain still verifies.
     */
    public function checkpoint(int $seq, string $hash): ?string
    {
        $report = $this->verify();
        $kept = array_values(array_filter($report['entries'], static fn (array $item): bool => $item['seq'] === $seq))[0] ?? null;

        return match (true) {
            $report['newest'] < $seq => \sprintf('The log now ends at entry %d, before the kept entry %d: entries were cut off.', $report['newest'], $seq),
            null === $kept => \sprintf('Entry %d is missing from the log.', $seq),
            !hash_equals($hash, $kept['hash']) => \sprintf('Entry %d is no longer the entry kept: it, or the chain before it, was rewritten.', $seq),
            !$report['intact'] => \sprintf('Entry %d is unchanged, but the chain does not verify. Open the audit log to see which entries fail.', $seq),
            default => null,
        };
    }

    /**
     * The newest entry's sequence and hash, to keep as a checkpoint where the application cannot write, and
     * whether the whole chain verifies. Later, the same hash at that sequence in a chain that still verifies
     * shows that nothing up to it was cut off or rewritten. Null for an empty log.
     *
     * @return array{seq: int, hash: string, keyId: string, at: ?string, intact: bool}|null
     */
    public function head(): ?array
    {
        $report = $this->verify();
        $newest = end($report['entries']);
        if (false === $newest) {
            return null;
        }
        $at = $newest['entry']['at'] ?? null;

        return ['seq' => $newest['seq'], 'hash' => $newest['hash'], 'keyId' => $newest['keyId'], 'at' => \is_string($at) ? $at : null, 'intact' => $report['intact']];
    }

    /**
     * The entry and what is wrong with it. The entry is there once the row's hash and signature verify with
     * the key the row names and the row's sequence, type, subject and tenant match what was signed; the
     * problem is null once its time also falls inside that key's window. The stored key id is not signed: it
     * only says which trusted key must verify the signature, so naming another key fails.
     *
     * @param AuditRecord $row
     *
     * @return array{entry: ?array<string, mixed>, problem: ?string}
     */
    private function check(array $row): array
    {
        $key = $this->keys->get($row['key_id']);
        if (null === $key) {
            return ['entry' => null, 'problem' => \sprintf('it is signed with key "%s", which is not one of the trusted keys', $row['key_id'])];
        }
        $broken = ['entry' => null, 'problem' => 'its signature or hash does not verify'];
        if (!hash_equals(hash('sha256', $row['entry']), $row['hash']) || !$key->signed($row['entry'], $row['signature'])) {
            return $broken;
        }
        try {
            $entry = CanonicalJson::map(CanonicalJson::decode($row['entry']));
        } catch (\Throwable) {
            return $broken;
        }
        if (($entry['format'] ?? null) !== self::FORMAT || ($entry['seq'] ?? null) !== (int) $row['seq']
            || ($entry['type'] ?? null) !== $row['type'] || ($entry['subject'] ?? null) !== $row['subject'] || ($entry['tenantId'] ?? null) !== $row['tenant_id']) {
            return $broken;
        }
        if (!$key->coversClaim($entry['at'] ?? null)) {
            return ['entry' => $entry, 'problem' => \sprintf('it claims a time outside the window of its key "%s"', $key->keyId)];
        }
        if (self::KEY_ROTATED === $entry['type'] && !self::rotatesTo($entry, $key)) {
            return ['entry' => $entry, 'problem' => 'it records a key rotation that its own signature does not match'];
        }

        return ['entry' => $entry, 'problem' => null];
    }

    /**
     * A rotation entry is about the whole application: its subject is the old key, and the new key it names,
     * which is not the old one, is the key that signed it.
     *
     * @param array<string, mixed> $entry
     */
    private static function rotatesTo(array $entry, TrustedKey $key): bool
    {
        $data = $entry['data'] ?? null;

        return $data instanceof \stdClass && self::ALL_TENANTS === $entry['tenantId']
            && ($data->from ?? null) === $entry['subject'] && ($data->to ?? null) === $key->keyId && $data->from !== $data->to
            && ($data->publicKey ?? null) === $key->publicKey;
    }

    /** Whether entry $seq, signed with $keyId, comes after the chain moved trust away from that key. */
    private function signedAfterRotation(string $keyId, int $seq): bool
    {
        $rotated = $this->rotatedAway($keyId);

        return null !== $rotated && $seq > $rotated;
    }

    /** The first entry that records a rotation away from $keyId and verifies, or null. */
    private function rotatedAway(string $keyId): ?int
    {
        $first = null;
        foreach ($this->store->recordsFor(self::KEY_ROTATED, $keyId) as $row) {
            ['entry' => $entry, 'problem' => $problem] = $this->check($row);
            if (null !== $entry && null === $problem && self::KEY_ROTATED === $entry['type'] && $keyId === $entry['subject']) {
                $first = min($first ?? \PHP_INT_MAX, (int) $row['seq']);
            }
        }

        return $first;
    }

    /** Whether $keyId is trusted to sign at $now, so that what it signed counts without another entry vouching for it. */
    private function inUse(string $keyId, \DateTimeImmutable $now): bool
    {
        return $this->keys->get($keyId)?->covers($now) ?? false;
    }

    /**
     * Whether a later entry, signed with a key in use and verifying on its own, links back to $row through the
     * chain. Each entry names the hash of the one before it, so that entry fixes every entry before it as it was
     * stored when it was signed: a row swapped in or inserted since, even one signed with a retired key and
     * backdated into its window, breaks the link.
     *
     * @param AuditRecord $row
     */
    private function vouchedFor(array $row, \DateTimeImmutable $now): bool
    {
        $hash = null;
        $seq = 0;
        foreach ($this->store->records() as $next) {
            if (null === $hash) {
                if ((int) $next['seq'] === (int) $row['seq'] && $next['entry'] === $row['entry']) {
                    [$hash, $seq] = [hash('sha256', $next['entry']), (int) $next['seq']];
                }
                continue;
            }
            if (!self::follows($next, $hash, $seq + 1)) {
                return false;
            }
            if ($this->inUse($next['key_id'], $now) && !$this->signedAfterRotation($next['key_id'], (int) $next['seq'])) {
                ['entry' => $entry, 'problem' => $problem] = $this->check($next);
                if (null !== $entry && null === $problem) {
                    return true;
                }
            }
            [$hash, $seq] = [$next['hash'], (int) $next['seq']];
        }

        return false;
    }

    /**
     * Whether $row links to the row before it: it comes at the next sequence, its stored hash is its own, and
     * its entry names $previous, the hash of the row before it, or null for the first.
     *
     * @param AuditRecord $row
     */
    private static function follows(array $row, ?string $previous, int $seq): bool
    {
        if ((int) $row['seq'] !== $seq || !hash_equals(hash('sha256', $row['entry']), $row['hash'])) {
            return false;
        }
        try {
            $entry = CanonicalJson::map(CanonicalJson::decode($row['entry']));
        } catch (\Throwable) {
            return false;
        }

        return ($entry['previous'] ?? null) === $previous;
    }
}
