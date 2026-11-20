<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;

/**
 * The signed audit log across a change of signing key: entries the old key signed keep verifying, the chain
 * records where trust moved, and the old key vouches for nothing it signs afterwards, whatever time it claims.
 */
final class AuditKeyRotationTest extends TestCase
{
    private const OPENED = '2026-11-01T00:00:00Z';
    private const ROTATED = '2026-11-09T12:00:00Z';

    private \PDO $pdo;
    private PdoAuditStore $store;
    private MockClock $clock;
    private SodiumEvidenceSigner $old;
    private SodiumEvidenceSigner $new;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->store = new PdoAuditStore($this->pdo);
        $this->store->initialize();
        $this->clock = new MockClock('2026-11-05T09:00:00Z');
        $this->old = new SodiumEvidenceSigner('application-1', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->new = new SodiumEvidenceSigner('application-2', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    }

    public function testAChainSpanningARotationVerifiesAndRecordsWhereTrustMoved(): void
    {
        $before = $this->before();
        $before->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice', 'label' => 'Laptop']);
        $before->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob']);

        $after = $this->rotate();
        $after->append('tenant-a', 'passkey.enrolled', 'cred-2', ['userId' => 'alice', 'label' => 'Phone']);

        $report = $after->verify();
        self::assertTrue($report['intact'], json_encode($report['entries']));
        self::assertSame(4, $report['newest']);
        self::assertSame(['application-1', 'application-1', 'application-2', 'application-2'], array_column($report['entries'], 'keyId'));
        $rotation = $report['entries'][2]['entry'];
        self::assertSame([AuditLog::KEY_ROTATED, AuditLog::ALL_TENANTS, 'application-1'], [$rotation['type'], $rotation['tenantId'], $rotation['subject']]);
        self::assertEquals((object) ['from' => 'application-1', 'to' => 'application-2', 'publicKey' => $this->new->publicKey(), 'reason' => 'scheduled'], $rotation['data']);
        self::assertSame('Laptop', $after->find('passkey.enrolled', 'cred-1')['data']->label, 'What the old key signed still vouches.');
        self::assertSame('Phone', $after->find('passkey.enrolled', 'cred-2')['data']->label);
        self::assertSame(['seq' => 4, 'hash' => $report['entries'][3]['hash'], 'keyId' => 'application-2', 'at' => '2026-11-09T12:02:00.000000Z', 'intact' => true], $after->head());
    }

    public function testTheRetiredKeyVouchesForNothingAfterTheRotationEvenBackdated(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $after = $this->rotate();

        // Someone holding a copy of the old private key appends an enrollment for their own passkey, claiming a
        // time inside the old key's window, so the window alone would accept it.
        $thief = new AuditLog($this->store, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-08T10:00:00Z'));
        $thief->append('tenant-a', 'passkey.enrolled', 'cred-thief', ['userId' => 'alice', 'label' => 'Not Alice’s']);

        self::assertNull($after->find('passkey.enrolled', 'cred-thief'), 'The chain had moved trust away from that key.');
        $report = $after->verify();
        self::assertFalse($report['intact']);
        self::assertSame('it is signed with key "application-1", which entry 2 rotated away from', $report['entries'][2]['problem']);
        self::assertNotNull($report['entries'][2]['entry'], 'Its signature verifies, so the administrator can see what it claims.');
        self::assertNotNull($after->find('passkey.enrolled', 'cred-1'), 'What the old key signed before the rotation still vouches.');
    }

    public function testDamagingTheRotationEntryLeavesWhatTheRetiredKeySignedCountingForNothing(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $after = $this->rotate();

        // Someone holding the old private key and the database breaks the rotation entry's signature, so it no
        // longer shows that trust moved away from the old key, and appends a backdated enrollment signed with it.
        $this->pdo->exec("UPDATE verified_human_approval_audit SET signature = (CASE substr(signature, 1, 1) WHEN 'A' THEN 'B' ELSE 'A' END) || substr(signature, 2) WHERE type = 'signing_key.rotated'");
        $thief = new AuditLog($this->store, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-08T10:00:00Z'));
        $thief->append('tenant-a', 'passkey.enrolled', 'cred-thief', ['userId' => 'alice']);
        // The application's next entry, signed with the new key, follows the forged one.
        $after->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob']);

        self::assertNull($after->find('passkey.enrolled', 'cred-thief'));
        self::assertNull($after->find('passkey.enrolled', 'cred-1'), 'While the entries before the new key took over are damaged, nothing the old key signed counts.');
        self::assertSame([
            1 => 'it is signed with key "application-1", whose window has closed, and no later entry signed with a key in use vouches for it',
            2 => 'its signature or hash does not verify',
            3 => 'it is signed with key "application-1", whose window has closed, and an entry before it does not verify',
            4 => null,
        ], array_column($after->verify()['entries'], 'problem', 'seq'));
    }

    public function testAnEntryRemovedFromBeforeTheRotationLeavesWhatTheOldKeySignedAfterItCountingForNothing(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        // A rollout: both keys sign for an hour, then the old key's window closes and the rotation is recorded.
        $overlap = new TrustedKeys([
            new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED)),
            new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time(self::OPENED), TrustedKey::time('2026-11-09T13:00:00Z')),
        ]);
        $this->clock->modify('2026-11-09T12:10:00Z');
        $after = new AuditLog($this->store, $this->new, $overlap, $this->clock);
        $after->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob']);
        $this->clock->modify('2026-11-09T12:20:00Z');
        (new AuditLog($this->store, $this->old, $overlap, $this->clock))->append('tenant-a', 'passkey.enrolled', 'cred-3', ['userId' => 'alice']);
        $this->clock->modify('2026-11-09T14:00:00Z');
        $after->recordRotation('application-1');

        // The removed entry could have been a rotation away from the old key, so nothing that key signed after the gap
        // counts, though every other entry still verifies on its own.
        $this->pdo->exec('DELETE FROM verified_human_approval_audit WHERE seq = 1');

        self::assertNull($after->find('passkey.enrolled', 'cred-3'));
        self::assertSame([
            2 => 'an entry before it is missing',
            3 => 'it is signed with key "application-1", whose window has closed, and an entry before it does not verify',
            4 => null,
        ], array_column($after->verify()['entries'], 'problem', 'seq'));
    }

    public function testAnEntrySlippedInFrontOfARewrittenRotationIsNotVouchedForThroughIt(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $after = $this->rotate();

        // The rotation is the newest entry. Someone holding the old private key and the database takes it off,
        // appends a backdated enrollment signed with the old key, and puts the rotation back after it, renumbered.
        $rotation = $this->pdo->query('SELECT * FROM verified_human_approval_audit WHERE seq = 2')->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($rotation);
        $this->pdo->exec('DELETE FROM verified_human_approval_audit WHERE seq = 2');
        $thief = new AuditLog($this->store, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-08T10:00:00Z'));
        $thief->append('tenant-a', 'passkey.enrolled', 'cred-thief', ['userId' => 'alice']);
        $forged = (string) $this->pdo->query('SELECT entry FROM verified_human_approval_audit WHERE seq = 2')->fetchColumn();
        $moved = CanonicalJson::encode(['seq' => 3, 'previous' => hash('sha256', $forged)] + CanonicalJson::map(CanonicalJson::decode($rotation['entry'])));
        $this->pdo->prepare('INSERT INTO verified_human_approval_audit (seq, tenant_id, type, subject, entry, hash, key_id, signature) VALUES (3, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$rotation['tenant_id'], $rotation['type'], $rotation['subject'], $moved, hash('sha256', $moved), $rotation['key_id'], $rotation['signature']]);
        // The application's next entry follows the rewritten rotation, which links to the forged entry.
        $after->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob']);

        self::assertNull($after->find('passkey.enrolled', 'cred-thief'), 'Only entries that verify carry the new key\'s vouching back.');
        $unvouched = 'it is signed with key "application-1", whose window has closed, and no later entry signed with a key in use vouches for it';
        self::assertSame([1 => $unvouched, 2 => $unvouched, 3 => 'its signature or hash does not verify', 4 => null], array_column($after->verify()['entries'], 'problem', 'seq'));
    }

    /** @return iterable<string, array{int, array<int, ?string>}> entries the new key signs after the rotation, and what verify() then reports */
    public static function reSignedRotations(): iterable
    {
        $unvouched = 'it is signed with key "application-1", whose window has closed, and no later entry signed with a key in use vouches for it';
        $outside = 'it claims a time outside the window of its key "application-1"';
        $closed = 'it is signed with key "application-1", whose window has closed, and an entry before it does not verify';
        yield 'the rotation is the newest entry' => [0, [1 => $unvouched, 2 => $outside, 3 => $closed, 4 => null]];
        yield 'the new key has signed since' => [2, [1 => $unvouched, 2 => $outside, 3 => null, 4 => null, 5 => $closed, 6 => null]];
    }

    /** @param array<int, ?string> $problems */
    #[DataProvider('reSignedRotations')]
    public function testARotationEntrySignedAgainWithTheRetiredKeyStillEndsWhatThatKeySigns(int $since, array $problems): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $after = $this->rotate();
        for ($entry = 0; $entry < $since; ++$entry) {
            $after->append('tenant-b', 'connection.revoked', 'grant-'.$entry, ['userId' => 'bob']);
        }

        // Someone holding the old private key and the database signs the rotation entry again with that key. What it
        // says, its hash and every link stay as they were, but it no longer shows that the new key moved trust.
        $rotation = (string) $this->pdo->query("SELECT entry FROM verified_human_approval_audit WHERE type = 'signing_key.rotated'")->fetchColumn();
        $this->pdo->prepare("UPDATE verified_human_approval_audit SET key_id = 'application-1', signature = ? WHERE type = 'signing_key.rotated'")->execute([$this->old->sign($rotation)]);
        $thief = new AuditLog($this->store, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-08T10:00:00Z'));
        $thief->append('tenant-a', 'passkey.enrolled', 'cred-thief', ['userId' => 'alice']);
        $after->append('tenant-b', 'connection.revoked', 'grant-9', ['userId' => 'bob']);

        self::assertNull($after->find('passkey.enrolled', 'cred-thief'));
        self::assertNull($after->find('passkey.enrolled', 'cred-1'), 'Until an operator looks, what the old key signed counts for nothing.');
        self::assertSame($problems, array_column($after->verify()['entries'], 'problem', 'seq'));
    }

    public function testARotationEntrySignedAgainWithAnotherRetiredKeyStillEndsWhatTheOldKeySigns(): void
    {
        // Two rotations: application-0 to application-1, then application-1 to application-2.
        $first = new SodiumEvidenceSigner('application-0', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->clock->modify('2026-10-30T09:00:00Z');
        (new AuditLog($this->store, $first, TrustedKeys::single('application-0', $first->publicKey()), $this->clock))->append('tenant-a', 'passkey.enrolled', 'cred-0', ['userId' => 'alice']);
        $this->clock->modify(self::OPENED);
        $this->clock->sleep(60);
        $handover = new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time(self::OPENED)), new TrustedKey('application-0', $first->publicKey(), null, TrustedKey::time(self::OPENED))]);
        $middle = new AuditLog($this->store, $this->old, $handover, $this->clock);
        $middle->recordRotation('application-0');
        $middle->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $this->clock->modify(self::ROTATED);
        $this->clock->sleep(60);
        $after = new AuditLog($this->store, $this->new, new TrustedKeys([...$this->rotatedKeys()->all(), new TrustedKey('application-0', $first->publicKey(), null, TrustedKey::time(self::OPENED))]), $this->clock);
        $after->recordRotation('application-1');

        // Someone holding both retired keys and the database signs the newer rotation again with the oldest key, then
        // enrolls their own passkey with application-1, backdated into its window, whose rotation no longer counts.
        $rotation = (string) $this->pdo->query('SELECT entry FROM verified_human_approval_audit WHERE seq = 4')->fetchColumn();
        $this->pdo->prepare("UPDATE verified_human_approval_audit SET key_id = 'application-0', signature = ? WHERE seq = 4")->execute([$first->sign($rotation)]);
        $thief = new AuditLog($this->store, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-08T10:00:00Z'));
        $thief->append('tenant-a', 'passkey.enrolled', 'cred-thief', ['userId' => 'alice']);
        $after->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob']);

        self::assertNull($after->find('passkey.enrolled', 'cred-thief'), 'A rotation the log refuses could hide where trust moved, for any key.');
        $unvouched = static fn (string $key): string => \sprintf('it is signed with key "%s", whose window has closed, and no later entry signed with a key in use vouches for it', $key);
        self::assertSame([
            1 => $unvouched('application-0'),
            2 => $unvouched('application-1'),
            3 => $unvouched('application-1'),
            4 => 'it claims a time outside the window of its key "application-0"',
            5 => 'it is signed with key "application-1", whose window has closed, and an entry before it does not verify',
            6 => null,
        ], array_column($after->verify()['entries'], 'problem', 'seq'));
    }

    public function testOnceTheRetiredKeySignsAnEntryTheLogRefusesNothingItSignsLaterCounts(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $after = $this->rotate();

        // Someone holding the old private key and the database rewrites the rotation, the newest entry, as another
        // event signed with that key. It keeps its time, after the old key's window closed, so the log refuses it,
        // though every link holds. Then they enroll their own passkey with the old key, backdated into its window.
        $this->rewrite(2, ['tenantId' => 'tenant-b', 'type' => 'connection.revoked', 'subject' => 'grant-1', 'data' => (object) ['userId' => 'bob']]);
        $thief = new AuditLog($this->store, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-08T10:00:00Z'));
        $thief->append('tenant-a', 'passkey.enrolled', 'cred-thief', ['userId' => 'alice']);
        $after->append('tenant-b', 'connection.revoked', 'grant-2', ['userId' => 'bob']);

        self::assertNull($after->find('passkey.enrolled', 'cred-thief'));
        self::assertSame([
            1 => 'it is signed with key "application-1", whose window has closed, and no later entry signed with a key in use vouches for it',
            2 => 'it claims a time outside the window of its key "application-1"',
            3 => 'it is signed with key "application-1", whose window has closed, and an entry before it does not verify',
            4 => null,
        ], array_column($after->verify()['entries'], 'problem', 'seq'));
    }

    public function testNoEntryVouchesAcrossOneTheLogRefuses(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $after = $this->rotate();

        // The rotation is the newest entry. Someone holding the old private key and the database takes it off,
        // appends a backdated enrollment signed with that key, and puts the rotation back after it, renumbered and
        // signed again with the old key, so that every link holds.
        $rotation = $this->pdo->query('SELECT * FROM verified_human_approval_audit WHERE seq = 2')->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($rotation);
        $this->pdo->exec('DELETE FROM verified_human_approval_audit WHERE seq = 2');
        $thief = new AuditLog($this->store, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-08T10:00:00Z'));
        $thief->append('tenant-a', 'passkey.enrolled', 'cred-thief', ['userId' => 'alice']);
        $forged = (string) $this->pdo->query('SELECT entry FROM verified_human_approval_audit WHERE seq = 2')->fetchColumn();
        $moved = CanonicalJson::encode(['seq' => 3, 'previous' => hash('sha256', $forged)] + CanonicalJson::map(CanonicalJson::decode($rotation['entry'])));
        $this->pdo->prepare("INSERT INTO verified_human_approval_audit (seq, tenant_id, type, subject, entry, hash, key_id, signature) VALUES (3, ?, ?, ?, ?, ?, 'application-1', ?)")
            ->execute([$rotation['tenant_id'], $rotation['type'], $rotation['subject'], $moved, hash('sha256', $moved), $this->old->sign($moved)]);
        $after->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob']);

        self::assertNull($after->find('passkey.enrolled', 'cred-thief'), 'The new key\'s vouching does not pass back across an entry the log refuses.');
        $unvouched = 'it is signed with key "application-1", whose window has closed, and no later entry signed with a key in use vouches for it';
        self::assertSame([1 => $unvouched, 2 => $unvouched, 3 => 'it claims a time outside the window of its key "application-1"', 4 => null], array_column($after->verify()['entries'], 'problem', 'seq'));
    }

    public function testOnlyACheckpointShowsTheChainRewrittenFromTheRotationOnWithTheRetiredKey(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $after = $this->rotate();
        $checkpoint = $after->head();
        self::assertNotNull($checkpoint);

        // As above, but the rewritten rotation is backdated into the old key's window, so every entry verifies and the
        // chain shows no rotation at all: the new key's entries are gone, as if cut off. This is the documented limit.
        $this->rewrite(2, ['at' => '2026-11-08T09:00:00.000000Z', 'tenantId' => 'tenant-b', 'type' => 'connection.revoked', 'subject' => 'grant-1', 'data' => (object) ['userId' => 'bob']]);
        $thief = new AuditLog($this->store, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-08T10:00:00Z'));
        $thief->append('tenant-a', 'passkey.enrolled', 'cred-thief', ['userId' => 'alice']);
        $after->append('tenant-b', 'connection.revoked', 'grant-2', ['userId' => 'bob']);

        self::assertNotNull($after->find('passkey.enrolled', 'cred-thief'), 'The log alone cannot tell.');
        self::assertTrue($after->verify()['intact']);
        self::assertSame(['application-1'], $after->unrecordedRotations(), 'The rotation the log recorded is missing again.');
        self::assertSame('Entry 2 is no longer the entry kept: it, or the chain before it, was rewritten.', $after->checkpoint($checkpoint['seq'], $checkpoint['hash']));
    }

    public function testWhatAClosedKeySignedCountsOnceTheNewKeyHasSigned(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice', 'label' => 'Laptop']);
        // The switch: the old key's window has closed, and the new key has signed nothing yet.
        $this->clock->modify(self::ROTATED);
        $this->clock->sleep(60);
        $after = new AuditLog($this->store, $this->new, $this->rotatedKeys(), $this->clock);
        self::assertNull($after->find('passkey.enrolled', 'cred-1'), 'No entry signed with a key in use vouches for it yet.');
        $report = $after->verify();
        self::assertFalse($report['intact']);
        self::assertSame('it is signed with key "application-1", whose window has closed, and no later entry signed with a key in use vouches for it', $report['entries'][0]['problem']);
        self::assertTrue($report['entries'][0]['unvouched']);
        self::assertSame(['application-1'], $after->unvouchedKeys(), 'The log says which key waits for its rotation to be recorded.');
        self::assertSame(['application-1'], $after->unrecordedRotations());

        // Recording the rotation is the new key's first entry, and it vouches for the chain before it.
        $after->recordRotation('application-1');
        self::assertSame('Laptop', $after->find('passkey.enrolled', 'cred-1')['data']->label);
        self::assertTrue($after->verify()['intact']);
        self::assertSame([], $after->unvouchedKeys());
        self::assertSame([], $after->unrecordedRotations());
    }

    public function testARotationTheLogDoesNotRecordIsNamedFromTheMomentTheOldWindowCloses(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        // While the old key's window is still open, nothing is due.
        $overlap = new TrustedKeys([
            new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED)),
            new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time(self::OPENED), TrustedKey::time('2026-11-09T13:00:00Z')),
        ]);
        $this->clock->modify('2026-11-09T12:30:00Z');
        $log = new AuditLog($this->store, $this->new, $overlap, $this->clock);
        self::assertSame([], $log->unrecordedRotations());

        // Once it has closed, the rotation is due, and stays due after the new key's first entry vouches for the
        // old key's, until it is recorded.
        $this->clock->modify('2026-11-09T13:00:01Z');
        self::assertSame(['application-1'], $log->unrecordedRotations());
        $log->append('tenant-a', 'passkey.enrolled', 'cred-2', ['userId' => 'alice']);
        self::assertSame([], $log->unvouchedKeys());
        self::assertNotNull($log->find('passkey.enrolled', 'cred-1'));
        self::assertSame(['application-1'], $log->unrecordedRotations());
        $log->recordRotation('application-1');
        self::assertSame([], $log->unrecordedRotations());
    }

    public function testACheckpointHoldsUntilTheChainIsCutOrRewritten(): void
    {
        $log = $this->before();
        $log->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $log->append('tenant-a', 'passkey.enrolled', 'cred-2', ['userId' => 'alice']);
        $head = $log->head();
        self::assertNotNull($head);
        self::assertNull($log->checkpoint($head['seq'], $head['hash']), 'The checkpoint holds.');
        self::assertSame('Entry 2 is no longer the entry kept: it, or the chain before it, was rewritten.', $log->checkpoint(2, str_repeat('0', 64)));
        $log->append('tenant-a', 'passkey.revoked', 'cred-2', ['userId' => 'alice']);
        self::assertNull($log->checkpoint($head['seq'], $head['hash']), 'A later entry leaves an earlier checkpoint holding.');
        $this->pdo->exec('DELETE FROM verified_human_approval_audit WHERE seq >= 2');
        self::assertSame('The log now ends at entry 1, before the kept entry 2: entries were cut off.', $log->checkpoint($head['seq'], $head['hash']));
    }

    public function testWhileTheOldKeysWindowIsStillOpenWhatItSignedCountsOnItsOwn(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        // A rollout: the new key signs from the rotation, and the old key's window stays open an hour longer.
        $overlap = new TrustedKeys([
            new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED)),
            new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time(self::OPENED), TrustedKey::time('2026-11-09T13:00:00Z')),
        ]);
        $this->clock->modify('2026-11-09T12:30:00Z');
        $log = new AuditLog($this->store, $this->new, $overlap, $this->clock);
        self::assertNotNull($log->find('passkey.enrolled', 'cred-1'));
        self::assertTrue($log->verify()['intact']);
    }

    public function testWhileTheOldKeysWindowIsStillOpenDamagingTheRotationLetsNothingItSignsCount(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        // A rollout: the rotation is recorded while the old key's window stays open an hour longer.
        $overlap = new TrustedKeys([
            new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED)),
            new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time(self::OPENED), TrustedKey::time('2026-11-09T13:00:00Z')),
        ]);
        $this->clock->modify('2026-11-09T12:30:00Z');
        $log = new AuditLog($this->store, $this->new, $overlap, $this->clock);
        $log->recordRotation('application-1');

        // Someone holding the old private key and the database breaks the rotation entry's signature, then enrolls
        // their own passkey with the old key, inside its window.
        $this->pdo->exec("UPDATE verified_human_approval_audit SET signature = (CASE substr(signature, 1, 1) WHEN 'A' THEN 'B' ELSE 'A' END) || substr(signature, 2) WHERE type = 'signing_key.rotated'");
        $thief = new AuditLog($this->store, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-09T12:40:00Z'));
        $thief->append('tenant-a', 'passkey.enrolled', 'cred-thief', ['userId' => 'alice']);

        self::assertNull($log->find('passkey.enrolled', 'cred-thief'));
        self::assertNotNull($log->find('passkey.enrolled', 'cred-1'), 'What the old key signed before the damage still counts.');
        self::assertSame([
            1 => null,
            2 => 'its signature or hash does not verify',
            3 => 'it is signed with key "application-1", which is being retired, and an entry before it does not verify',
        ], array_column($log->verify()['entries'], 'problem', 'seq'));
    }

    public function testARotationHiddenFromTheStoresLookupStillEndsTheOldKeysEntries(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        // A rollout: the rotation is recorded while the old key's window stays open an hour longer.
        $overlap = new TrustedKeys([
            new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED)),
            new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time(self::OPENED), TrustedKey::time('2026-11-09T13:00:00Z')),
        ]);
        $this->clock->modify('2026-11-09T12:30:00Z');
        $log = new AuditLog($this->store, $this->new, $overlap, $this->clock);
        $log->recordRotation('application-1');

        // Someone holding the old private key and the database stores the rotation's type as a BLOB, which keeps
        // what was signed but hides the row from the lookup, then enrolls their own passkey inside the old window.
        $this->pdo->exec("UPDATE verified_human_approval_audit SET type = CAST(type AS BLOB) WHERE type = 'signing_key.rotated'");
        $thief = new AuditLog($this->store, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-09T12:40:00Z'));
        $thief->append('tenant-a', 'passkey.enrolled', 'cred-thief', ['userId' => 'alice']);

        self::assertNull($log->find('passkey.enrolled', 'cred-thief'), 'The chain had moved trust away from that key.');
        self::assertNotNull($log->find('passkey.enrolled', 'cred-1'));
        self::assertSame('it is signed with key "application-1", which entry 2 rotated away from', $log->verify()['entries'][2]['problem']);
    }

    public function testARecordClaimingATimeOutsideItsKeysWindowFails(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $after = new AuditLog($this->store, $this->new, $this->rotatedKeys(), $this->clock);

        // The retired key signs after its window closed. No rotation is recorded yet: the window alone refuses it.
        $late = new AuditLog($this->store, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-09T12:00:01Z'));
        $late->append('tenant-a', 'passkey.enrolled', 'cred-late', ['userId' => 'alice']);
        // The new key signs a record claiming a time before its window opened.
        $early = new AuditLog($this->store, $this->new, TrustedKeys::single('application-2', $this->new->publicKey()), new MockClock('2026-11-09T11:59:59Z'));
        $early->append('tenant-a', 'passkey.enrolled', 'cred-early', ['userId' => 'alice']);

        self::assertNull($after->find('passkey.enrolled', 'cred-late'));
        self::assertNull($after->find('passkey.enrolled', 'cred-early'));
        self::assertNotNull($after->find('passkey.enrolled', 'cred-1'));
        $problems = array_column($after->verify()['entries'], 'problem', 'seq');
        self::assertSame([1 => null, 2 => 'it claims a time outside the window of its key "application-1"', 3 => 'it claims a time outside the window of its key "application-2"'], $problems);
    }

    public function testAnUnknownKeyIdFailsAndSoDoesARowNamingAnotherTrustedKey(): void
    {
        $before = $this->before();
        $before->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $stranger = new SodiumEvidenceSigner('application-9', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        (new AuditLog($this->store, $stranger, TrustedKeys::single($stranger->keyId(), $stranger->publicKey()), $this->clock))->append('tenant-a', 'passkey.enrolled', 'cred-9', ['userId' => 'alice']);

        $after = new AuditLog($this->store, $this->new, $this->rotatedKeys(), $this->clock);
        self::assertNull($after->find('passkey.enrolled', 'cred-9'));
        $report = $after->verify();
        self::assertSame('it is signed with key "application-9", which is not one of the trusted keys', $report['entries'][1]['problem']);
        self::assertNull($report['entries'][1]['entry'], 'Nothing vouches for what it says.');

        // The stored key id is not signed; pointing a genuine row at the other trusted key only breaks it.
        $this->pdo->exec("UPDATE verified_human_approval_audit SET key_id = 'application-2' WHERE seq = 1");
        self::assertNull($after->find('passkey.enrolled', 'cred-1'));
        self::assertSame('its signature or hash does not verify', $after->verify()['entries'][0]['problem']);
    }

    public function testTheLogNeverSignsAnEntryItsOwnKeysWouldRefuse(): void
    {
        $refusals = [
            'before the new key\'s window opens' => new AuditLog($this->store, $this->new, $this->rotatedKeys(), new MockClock('2026-11-09T11:00:00Z')),
            'after the old key\'s window closed' => new AuditLog($this->store, $this->old, $this->rotatedKeys(), new MockClock('2026-11-09T13:00:00Z')),
            'with a key the set does not list' => new AuditLog($this->store, $this->new, new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey())]), $this->clock),
            'with another key under its id' => new AuditLog($this->store, $this->new, TrustedKeys::single('application-2', $this->old->publicKey()), $this->clock),
        ];
        foreach ($refusals as $case => $log) {
            try {
                $log->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
                self::fail('It signed '.$case.'.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('is not one of the trusted keys at this time', $error->getMessage(), $case);
            }
        }
        self::assertSame(0, $this->before()->verify()['newest'], 'Nothing was written.');
    }

    public function testARotationIsRecordedOnceFromAClosedKeyToAnOpenedNewOne(): void
    {
        $this->before()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $this->clock->modify(self::ROTATED);
        $this->clock->sleep(60);
        $open = new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time(self::OPENED));
        $refusals = [
            'from a key the set does not list' => [new AuditLog($this->store, $this->new, $this->rotatedKeys(), $this->clock), 'application-7', 'is not one of the trusted keys'],
            'to the same key' => [new AuditLog($this->store, $this->new, $this->rotatedKeys(), $this->clock), 'application-2', 'moves to a new key'],
            'from a key still open' => [new AuditLog($this->store, $this->new, new TrustedKeys([new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED)), $open]), $this->clock), 'application-1', 'Close the window of key "application-1"'],
            'to a key with no start' => [new AuditLog($this->store, $this->new, new TrustedKeys([new TrustedKey('application-2', $this->new->publicKey()), $this->retired()]), $this->clock), 'application-1', 'Open the window of key "application-2"'],
        ];
        foreach ($refusals as $case => [$log, $from, $message]) {
            try {
                $log->recordRotation($from);
                self::fail('It recorded a rotation '.$case.'.');
            } catch (\InvalidArgumentException|\LogicException $error) {
                self::assertStringContainsString($message, $error->getMessage(), $case);
            }
        }

        $after = new AuditLog($this->store, $this->new, $this->rotatedKeys(), $this->clock);
        self::assertSame(2, $after->recordRotation('application-1')['seq']);
        try {
            $after->recordRotation('application-1');
            self::fail('The same rotation was recorded twice.');
        } catch (\LogicException $error) {
            self::assertSame('Entry 2 already records the rotation away from key "application-1".', $error->getMessage());
        }
        // Signing with the retired key again, even with its window reopened, cannot rotate back to it.
        $back = new AuditLog($this->store, $this->old, new TrustedKeys([$open, new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED), TrustedKey::time('2026-11-09T12:01:30Z'))]), $this->clock);
        try {
            $back->recordRotation('application-2');
            self::fail('Trust moved back to a key the log had rotated away from.');
        } catch (\LogicException $error) {
            self::assertSame('Entry 2 rotated away from key "application-1": rotate to a new key instead of returning to it.', $error->getMessage());
        }
        self::assertTrue($after->verify()['intact']);
    }

    public function testARotationEntryTheNewKeyDidNotSignMovesNoTrust(): void
    {
        $before = $this->before();
        // Written with plain append() by the old key: it claims trust moved to a key that never signed it.
        $before->append(AuditLog::ALL_TENANTS, AuditLog::KEY_ROTATED, 'application-1', ['from' => 'application-1', 'to' => 'application-2', 'publicKey' => $this->new->publicKey()]);
        $before->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);

        // A host that is retiring the old key counts nothing it signed after the entry: it could be a genuine rotation
        // signed again.
        $report = (new AuditLog($this->store, $this->old, $this->rotatedKeys(), $this->clock))->verify();
        self::assertSame(['it records a key rotation that its own signature does not match', 'it is signed with key "application-1", which is being retired, and an entry before it does not verify'], array_column($report['entries'], 'problem'));
        self::assertNotNull($this->before()->find('passkey.enrolled', 'cred-1'), 'No trust moved.');
    }

    /** The old key alone, open since it was created: the log before the rotation. */
    private function before(): AuditLog
    {
        return new AuditLog($this->store, $this->old, new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time(self::OPENED))]), $this->clock);
    }

    /** The new key signs from the rotation; the old one is closed then. A minute later the rotation is recorded. */
    private function rotate(): AuditLog
    {
        $this->clock->modify(self::ROTATED);
        $after = new AuditLog($this->store, $this->new, $this->rotatedKeys(), $this->clock);
        $this->clock->sleep(60);
        $after->recordRotation('application-1', 'scheduled');
        $this->clock->sleep(60);

        return $after;
    }

    private function rotatedKeys(): TrustedKeys
    {
        return new TrustedKeys([new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED)), $this->retired()]);
    }

    /**
     * Rewrites entry $seq with $changes and signs it with the old key, as someone holding that key and the database
     * would: its row matches what it now says. Done to the newest entry, every link still holds.
     *
     * @param array<string, mixed> $changes
     */
    private function rewrite(int $seq, array $changes): void
    {
        $entry = CanonicalJson::encode($changes + CanonicalJson::map(CanonicalJson::decode((string) $this->pdo->query('SELECT entry FROM verified_human_approval_audit WHERE seq = '.$seq)->fetchColumn())));
        $row = CanonicalJson::map(CanonicalJson::decode($entry));
        $this->pdo->prepare("UPDATE verified_human_approval_audit SET tenant_id = ?, type = ?, subject = ?, entry = ?, hash = ?, key_id = 'application-1', signature = ? WHERE seq = ?")
            ->execute([$row['tenantId'], $row['type'], $row['subject'], $entry, hash('sha256', $entry), $this->old->sign($entry), $seq]);
    }

    private function retired(): TrustedKey
    {
        return new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time(self::OPENED), TrustedKey::time(self::ROTATED));
    }
}
