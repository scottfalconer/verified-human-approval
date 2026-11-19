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
 * breaks a signature or the chain. Only two such edits can pass unnoticed: the newest entries cut off, and, by
 * someone holding a retired key's private half, the newest entries replaced with entries signed with that key,
 * reaching back to the rotation away from it once that is recorded. A checkpoint of the head (head()) kept
 * where the application cannot write exposes both.
 *
 * Entries are checked against the application's TrustedKeys. A row names its key; a key the set does not
 * list verifies nothing, and an entry whose time ("at") falls outside its key's window is refused. Once the
 * chain records a rotation away from a key (recordRotation()), an entry after it signed with that key is
 * refused too, whatever time it claims. A key with an end, as a key being retired has, is held to more: what
 * it signed counts only while nothing before it could hide where trust moved, so no entry was edited, removed
 * or put out of order, and no rotation entry, nor any other entry that key signed, is one the log refuses. Once
 * its window has closed, a later entry signed with a key in use must also vouch for it, through entries the log
 * accepts: the new key vouches for the old key's entries as they stood when it first signed, so record the
 * rotation as soon as the new key signs.
 *
 * This class builds, signs and checks entries; an AuditStore keeps the rows. One chain spans every tenant. The
 * store's lookup only finds entries: that one is absent, such as a revocation (unrecorded()) or the rotation away
 * from a key being retired (find()), is read from the whole chain, since an edited index or storage class can
 * hide a row from a lookup.
 *
 * So the checks grow with the chain. A reading fetches every row and checks the signatures of the entries it reads,
 * which is most of the time it takes. It also holds every row, and the entry it decodes from each one it checks,
 * until it is done: about 3 KB per entry at its peak when it checks every signature, some 120 MB at 37,000 entries.
 * Under PHP's default memory_limit of 128M, an approval step or verify() then fails with a fatal error, not a
 * refusal, once the chain passes about 37,000 entries, and sooner in a process that holds more: raise the limit
 * before the chain grows that long. find() reads the chain only for an entry signed with a key that has an end, and
 * standing() reads it once however many subjects it weighs. A log remembers up to 50,000 signatures that verified,
 * the first it checks, and does not check those again, so its later readings, later in a request or in a
 * long-running worker, cost a fraction of the first; in a longer chain, the entries beyond those are checked at each
 * reading. A passkey's standing is read from its enrollment on, or from the first entry once an entry since is signed
 * with a key that has an end, as after a rotation: an approval step in a request of its own reads the chain once, for
 * all of the person's passkeys, and checks every signature in that stretch once. The chain grows with what is
 * recorded here, passkeys enrolled and revoked, rotations and the host's own events, and never with approvals.
 *
 * @phpstan-import-type AuditHead from AuditStore
 * @phpstan-import-type AuditRecord from AuditStore
 *
 * @phpstan-type Judgement array{row: AuditRecord, entry: ?array<string, mixed>, problem: ?string, fault: ?string, inUse: bool}
 * @phpstan-type AuditReport array{entries: list<array{entry: ?array<string, mixed>, seq: int, valid: bool, problem: ?string, keyId: string, hash: string, unvouched: bool}>, intact: bool, newest: int}
 */
final class AuditLog
{
    public const FORMAT = 'verified-human-approval/audit/v1';
    /**
     * The application's signing key changed. The entry's subject and data.from name the old key, data.to
     * and data.publicKey the new one, which signs the entry; data.reason says why, when the operator did.
     */
    public const KEY_ROTATED = 'signing_key.rotated';
    /** The tenant of an entry about the whole application, such as a key rotation: it concerns every tenant. */
    public const ALL_TENANTS = '';

    /** How many verified signatures signed() remembers at most, which bounds its memory in a long-running worker. */
    private const REMEMBERED = 50000;

    /** @var array<string, true> the signatures that verified, by a digest of the key id, entry hash and signature */
    private array $verified = [];

    /**
     * @param TrustedKeys $keys the keys that check entries, the signer's among them: for its key alone, without a
     *                          window, TrustedKeys::single($signer->keyId(), $signer->publicKey())
     */
    public function __construct(
        private readonly AuditStore $store,
        private readonly EvidenceSigner $signer,
        private readonly TrustedKeys $keys,
        private readonly ClockInterface $clock,
    ) {
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
            if (!$this->keys->trusts($this->signer, $now)) {
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
     * shows where trust moved. Record it once every host and worker signs with the new key: the entry is signed
     * with it, and from this entry on an entry signed with the old key is refused, whatever time it claims, and
     * unrecorded() rules out nothing before such an entry.
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
     * and key must verify. If its key has an end, as a key being retired has, verify() must accept it too, which
     * reads the rotations from the whole chain, wants nothing before it damaged and, once that key's window has
     * closed, a later entry signed with a key in use to vouch for it (see the class description). So once the
     * rotation is recorded, someone holding a stolen copy of the old key cannot make an entry of their own count,
     * whether they damage the rotation entry, sign it again or hide it from the lookup, short of rewriting the
     * chain as far back as the rotation.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $type, string $subject): ?array
    {
        $accepted = null;
        foreach ($this->candidates($type, $subject) as [$row, $entry, $open]) {
            if ($open) {
                return $entry;
            }
            $accepted ??= self::accepted($this->verify());
            if (isset($accepted[$row['hash']])) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * For each of the subjects, the entry of $type that find() returns, while unrecorded() rules out an entry of
     * $undoneBy for that subject since that entry: a passkey's enrollment that no revocation has undone, say. One
     * reading of the chain answers for every subject, so weighing several costs about what weighing one does.
     *
     * @param list<string> $subjects
     *
     * @return array<string, array<string, mixed>> the entries that stand, by subject, in the order of $subjects
     */
    public function standing(string $type, array $subjects, string $undoneBy): array
    {
        $rows = null;
        $judged = null;
        $accepted = null;
        $found = [];
        $since = [];
        foreach ($subjects as $subject) {
            foreach ($this->candidates($type, $subject) as [$row, $entry, $open]) {
                // Read and judged once, when the first entry needs it, the chain answers for every later one too.
                if (!$open && null === $accepted) {
                    $judged = $this->judged($rows = $this->rows());
                    $accepted = self::accepted($this->report($judged));
                }
                if ($open || isset($accepted[$row['hash']])) {
                    $found[$subject] = $entry;
                    $since[$subject] = (int) $row['seq'];
                    break;
                }
            }
        }
        if ([] === $since) {
            return [];
        }

        return array_intersect_key($found, array_flip($this->ruledOut($undoneBy, $since, $rows ?? $this->rows(), $judged)));
    }

    /**
     * Of the subjects in $since, those for which the chain rules out an entry of this type. Ask it before trusting
     * that something was never recorded, such as a passkey's revocation. Any trace counts, whether it verifies or
     * not: a row whose columns name the type and the subject, or whose entry does. The answer comes from the whole
     * chain, never from recordsFor(), which misses a row whose index or storage was edited. And a subject is ruled
     * out only while every entry after its own sequence in $since is one verify() accepts, or will once a later
     * entry vouches for it, and its own entry is too, leaving aside how it follows the one before it. An entry the
     * log refuses could be what is left of the one in question: edited, retargeted, removed, or rewritten with a
     * retired key. Only the two edits the class description names can hide it unnoticed (see checkpoint()).
     *
     * @param array<string, int> $since by subject, the entry from which its chain must verify, such as its enrollment
     *
     * @return list<string>
     */
    public function unrecorded(string $type, array $since): array
    {
        return [] === $since ? [] : $this->ruledOut($type, $since, $this->rows());
    }

    /**
     * Every entry, oldest first, each checked on its own and against the one before it. Each names the key
     * its row says signed it and its hash; the entry itself is there once its signature verifies. An entry
     * signed with a key that has an end is refused after damage (see the class description), and
     * "unvouched" marks one that no later entry signed with a key in use vouches for, through entries the log
     * accepts, so find() does not count it (see unvouchedKeys()).
     *
     * @return array{entries: list<array{entry: ?array<string, mixed>, seq: int, valid: bool, problem: ?string, keyId: string, hash: string, unvouched: bool}>, intact: bool, newest: int}
     */
    public function verify(): array
    {
        return $this->report($this->judged($this->store->records()));
    }

    /**
     * The keys whose window has closed and whose entries nothing in use vouches for yet, by id. Until something
     * does, find() counts nothing they signed, so passkeys enrolled under them cannot approve. Usually the
     * rotation away from such a key has not been recorded yet: recordRotation() ends it. If it has been, an entry
     * after those is damaged or refused, and verify() says which.
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
     * The retired keys whose window has closed while the log records no rotation away from them, by id, in the
     * trusted keys' order. Record each with recordRotation(). This flags a rotation from the moment the old window
     * closes, before any passkey stops approving: unvouchedKeys() names a key only while nothing in use vouches
     * for what it signed, and the first entry the new key signs ends that, recorded rotation or not.
     *
     * @return list<string>
     */
    public function unrecordedRotations(): array
    {
        $now = $this->clock->now();
        $keys = [];
        foreach ($this->keys->all() as $key) {
            if ($key->keyId !== $this->signer->keyId() && null !== $key->validUntil && $key->validUntil < $now && null === $this->rotatedAway($key->keyId)) {
                $keys[] = $key->keyId;
            }
        }

        return $keys;
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
            !$report['intact'] => \sprintf('Entry %d is unchanged, but the chain does not verify: some entry fails its signature, key window or chain check.', $seq),
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
     * The rows of $type for $subject, newest first, whose entries verify and do not follow the rotation away from
     * their key, each with its entry and whether its key is open: what such a key signed counts on its own, and
     * anything else only once verify() accepts it. A damaged newer entry is passed over, never trusted.
     *
     * @return \Generator<int, array{AuditRecord, array<string, mixed>, bool}>
     */
    private function candidates(string $type, string $subject): \Generator
    {
        $now = $this->clock->now();
        foreach ($this->store->recordsFor($type, $subject) as $row) {
            ['entry' => $entry, 'problem' => $problem] = $this->check($row);
            if (null !== $entry && null === $problem && !$this->signedAfterRotation($row['key_id'], (int) $row['seq'])) {
                yield [$row, $entry, $this->open($row['key_id'], $now)];
            }
        }
    }

    /** @return list<AuditRecord> the whole chain, oldest first */
    private function rows(): array
    {
        return iterator_to_array($this->store->records(), false);
    }

    /**
     * What verify() reports of the chain judged from its first entry.
     *
     * @param list<Judgement> $judged
     *
     * @return AuditReport
     */
    private function report(array $judged): array
    {
        // From the newest back: whether a later entry signed with a key in use vouches for each entry, which
        // find() requires of one whose key's window has closed. Only entries the log accepts carry that back: one
        // it refuses could belong to a rewrite, and cuts everything before it off from what comes later.
        $unvouched = [];
        $vouched = false;
        for ($position = \count($judged) - 1; $position >= 0; --$position) {
            $item = $judged[$position];
            if (null === $item['problem'] && !$item['inUse'] && !$vouched) {
                $unvouched[$position] = true;
            }
            $vouched = null === $item['problem'] && ($item['inUse'] || $vouched);
        }
        $entries = [];
        foreach ($judged as $position => $item) {
            $problem = isset($unvouched[$position])
                ? \sprintf('it is signed with key "%s", whose window has closed, and no later entry signed with a key in use vouches for it', $item['row']['key_id'])
                : $item['problem'];
            $entries[] = ['entry' => $item['entry'], 'seq' => (int) $item['row']['seq'], 'valid' => null === $problem, 'problem' => $problem, 'keyId' => $item['row']['key_id'], 'hash' => $item['row']['hash'], 'unvouched' => isset($unvouched[$position])];
        }

        return ['entries' => $entries, 'intact' => [] === array_filter($entries, static fn (array $item): bool => !$item['valid']), 'newest' => [] === $entries ? 0 : $entries[\count($entries) - 1]['seq']];
    }

    /**
     * The sequences of the entries a report accepts, by hash. Each stored hash is the hash of its entry, which names
     * its sequence: equal hashes are the same entry.
     *
     * @param AuditReport $report
     *
     * @return array<string, int>
     */
    private static function accepted(array $report): array
    {
        return array_column(array_filter($report['entries'], static fn (array $item): bool => $item['valid']), 'seq', 'hash');
    }

    /**
     * unrecorded() over the chain as read. Judged from its first entry, as $judged is when given, the chain gives the
     * same answer as judged from start(): what start() leaves unchecked cannot change the verdict on an entry from the
     * earliest in $since on.
     *
     * @param non-empty-array<string, int> $since
     * @param list<AuditRecord>            $rows   the whole chain, oldest first
     * @param list<Judgement>|null         $judged $rows judged from the first entry
     *
     * @return list<string>
     */
    private function ruledOut(string $type, array $since, array $rows, ?array $judged = null): array
    {
        $from = min($since);
        // Each type and subject a row names, in its columns or in its entry, whether that verifies or not; and from
        // $from on, the newest entry with something wrong apart from how it follows the one before it, and the
        // newest the log refuses for any reason.
        $named = [];
        $newest = 0;
        $faulty = 0;
        $refused = 0;
        foreach ($judged ?? $this->judged($rows, $this->start($rows, $from)) as $item) {
            $row = $item['row'];
            $seq = (int) $row['seq'];
            $named[$row['type']][$row['subject']] = true;
            $read = $item['entry'] ?? self::read($row['entry']);
            if (\is_string($read['type'] ?? null) && \is_string($read['subject'] ?? null)) {
                $named[$read['type']][$read['subject']] = true;
            }
            $newest = max($newest, $seq);
            if ($seq >= $from && null !== $item['fault']) {
                $faulty = max($faulty, $seq);
            }
            if ($seq >= $from && null !== $item['problem']) {
                $refused = max($refused, $seq);
            }
        }
        $ruledOut = [];
        foreach ($since as $subject => $at) {
            // How a subject's own entry follows the one before it is not in question, only what follows it.
            if (!isset($named[$type][$subject]) && $at <= $newest && $faulty < $at && $refused <= $at) {
                $ruledOut[] = (string) $subject;
            }
        }

        return $ruledOut;
    }

    /**
     * Where to begin judging the chain to rule out entries from $from on. The chain before $from changes the verdict
     * on a later entry only through rotations and damage, which count against a key that has an end, is not in use or
     * was rotated away from. While every entry since is signed with some other key, what came before need not be
     * checked.
     *
     * @param list<AuditRecord> $rows the whole chain, oldest first
     */
    private function start(array $rows, int $from): int
    {
        $now = $this->clock->now();
        // The keys a row's columns say were rotated away from: a rotation counts only once its row matches what was
        // signed, columns included.
        $rotated = [];
        foreach ($rows as $row) {
            if (self::KEY_ROTATED === $row['type']) {
                $rotated[$row['subject']] = true;
            }
        }
        foreach ($rows as $row) {
            if ((int) $row['seq'] >= $from && (!$this->open($row['key_id'], $now) || isset($rotated[$row['key_id']]))) {
                return 1;
            }
        }

        return $from;
    }

    /**
     * Every row, oldest first, with its entry once its signature verifies, and the first thing verify() finds wrong
     * with it, short of a later entry vouching for it; fault is the same leaving aside how it follows the entry
     * before it. Rows before $from pass through unchecked, with no entry and no problem, and the rotations and
     * damage among them count for nothing: start later only where those could not change the answer.
     *
     * @param iterable<AuditRecord> $rows the whole chain, oldest first
     *
     * @return list<Judgement>
     */
    private function judged(iterable $rows, int $from = 1): array
    {
        $judged = [];
        $previous = null;
        $expected = 1;
        $now = $this->clock->now();
        // Where the chain moved trust away from each key: by key id, the sequence of that rotation.
        $rotatedAway = [];
        // Whether an entry so far was edited, removed or put out of order, or was a rotation the log refuses: any
        // of these could hide where trust moved, for every key.
        $damaged = false;
        // The keys that signed an entry so far that the log refuses: whoever holds such a key used it out of turn.
        $refused = [];
        foreach ($rows as $row) {
            $seq = (int) $row['seq'];
            $item = ['row' => $row, 'entry' => null, 'problem' => null, 'fault' => null, 'inUse' => false];
            if ($seq >= $from) {
                ['entry' => $entry, 'problem' => $checked] = $this->check($row);
                $inUse = $this->inUse($row['key_id'], $now);
                $link = null === $entry ? null : match (true) {
                    $seq !== $expected => 'an entry before it is missing',
                    $entry['previous'] !== $previous => 'it does not follow the entry before it',
                    default => null,
                };
                $ruled = null === $entry ? null : match (true) {
                    isset($rotatedAway[$row['key_id']]) => \sprintf('it is signed with key "%s", which entry %d rotated away from', $row['key_id'], $rotatedAway[$row['key_id']]),
                    !$this->open($row['key_id'], $now) && ($damaged || isset($refused[$row['key_id']])) => \sprintf($inUse
                        ? 'it is signed with key "%s", which is being retired, and an entry before it does not verify'
                        : 'it is signed with key "%s", whose window has closed, and an entry before it does not verify', $row['key_id']),
                    default => null,
                };
                $problem = $checked ?? $link ?? $ruled;
                if (null === $problem && null !== $entry && self::KEY_ROTATED === $entry['type']) {
                    $rotatedAway[$entry['subject']] ??= $seq;
                }
                // An entry that no longer says exactly what was signed, or no longer follows the one before it, is
                // damage for every key, and so is a rotation the log refuses; any other entry the log refuses marks
                // only the key that signed it.
                if (null === $entry || null !== $link || (null !== $problem && self::KEY_ROTATED === $entry['type'])) {
                    $damaged = true;
                } elseif (null !== $problem) {
                    $refused[$row['key_id']] = true;
                }
                $item = ['row' => $row, 'entry' => $entry, 'problem' => $problem, 'fault' => $checked ?? $ruled, 'inUse' => $inUse];
            }
            $judged[] = $item;
            $previous = $row['hash'];
            $expected = $seq + 1;
        }

        return $judged;
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
        if (!hash_equals(hash('sha256', $row['entry']), $row['hash']) || !$this->signed($key, $row)) {
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
     * Whether $key signed the row's entry, for a row whose hash is already known to be its entry's. A signature that
     * verified is not checked again: the answer depends only on the key, the entry, for which its hash stands, and the
     * signature itself, and the Ed25519 check is most of what reading a long chain costs.
     *
     * @param AuditRecord $row
     */
    private function signed(TrustedKey $key, array $row): bool
    {
        // The key id's length comes first and the hash always has 64 characters, so two rows share this digest only
        // when key, hash and signature are all the same.
        $signature = hash('sha256', \strlen($key->keyId).':'.$key->keyId.$row['hash'].$row['signature'], true);
        if (isset($this->verified[$signature])) {
            return true;
        }
        if (!$key->signed($row['entry'], $row['signature'])) {
            return false;
        }
        // Kept, not replaced, once full: starting again would check every entry of a longer chain at each reading.
        if (\count($this->verified) < self::REMEMBERED) {
            $this->verified[$signature] = true;
        }

        return true;
    }

    /**
     * What an entry says, unchecked, or null if it is not one: for a trace that counts whether it verifies or not.
     *
     * @return array<string, mixed>|null
     */
    private static function read(string $entry): ?array
    {
        try {
            return CanonicalJson::map(CanonicalJson::decode($entry));
        } catch (\JsonException|\UnexpectedValueException) {
            return null;
        }
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

    /** Whether $keyId is in use and its window has no end. */
    private function open(string $keyId, \DateTimeImmutable $now): bool
    {
        return $this->inUse($keyId, $now) && null === $this->keys->get($keyId)?->validUntil;
    }
}
