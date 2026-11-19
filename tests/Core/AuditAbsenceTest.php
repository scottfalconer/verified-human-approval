<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Tests\Support\InMemoryAuditStore;

/**
 * What the chain shows was never recorded: AuditLog::unrecorded() rules an entry out only from the whole chain,
 * and only while every entry since the subject's own is one the log accepts.
 */
final class AuditAbsenceTest extends TestCase
{
    private InMemoryAuditStore $store;
    private SodiumEvidenceSigner $signer;
    private AuditLog $log;

    protected function setUp(): void
    {
        $this->store = new InMemoryAuditStore();
        $this->signer = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->log = new AuditLog($this->store, $this->signer, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()), new MockClock('2026-11-08 12:00:00'));
        $this->log->append('acme', 'passkey.enrolled', 'laptop', ['userId' => 'alice']);
        $this->log->append('acme', 'passkey.enrolled', '123', ['userId' => 'alice']);
        $this->log->append('acme', 'connection.revoked', 'grant-1', ['userId' => 'alice']);
        $this->log->append('acme', 'passkey.enrolled', 'phone', ['userId' => 'alice']);
        $this->log->append('acme', 'connection.revoked', 'grant-2', ['userId' => 'alice']);
    }

    public function testEachSubjectIsJudgedFromItsOwnEntryOn(): void
    {
        $since = ['laptop' => 1, '123' => 2, 'phone' => 4];
        self::assertSame(['laptop', '123', 'phone'], $this->log->unrecorded('passkey.revoked', $since));
        $this->log->append('acme', 'passkey.revoked', 'laptop', ['userId' => 'alice']);
        self::assertSame(['123', 'phone'], $this->log->unrecorded('passkey.revoked', $since));

        // Entry 3 no longer matches its stored hash, so entry 4 no longer follows it either.
        $this->store->rows[3]['hash'] = str_repeat('0', 64);
        self::assertSame(['phone'], $this->log->unrecorded('passkey.revoked', $since), 'How the subject\'s own entry follows the one before it is not in question.');
        self::assertSame([], $this->log->unrecorded('passkey.revoked', []));
    }

    /** @return iterable<string, array{\Closure(InMemoryAuditStore): void}> */
    public static function traces(): iterable
    {
        yield 'a row whose columns name it, before its own entry, where nothing is checked' => [static function (InMemoryAuditStore $store): void {
            $store->rows[2]['type'] = 'passkey.revoked';
            $store->rows[2]['subject'] = 'phone';
        }];
        yield 'an entry that names it, though its signature no longer verifies' => [static function (InMemoryAuditStore $store): void {
            $store->rows[3]['entry'] = str_replace(['"connection.revoked"', '"grant-1"'], ['"passkey.revoked"', '"phone"'], $store->rows[3]['entry']);
        }];
    }

    /** @param \Closure(InMemoryAuditStore): void $trace */
    #[DataProvider('traces')]
    public function testAnyTraceCountsWhetherItVerifiesOrNot(\Closure $trace): void
    {
        $trace($this->store);
        self::assertSame([], $this->log->unrecorded('passkey.revoked', ['phone' => 4]));
    }

    public function testAnEntrySignedWithAKeyTheLogDoesNotListRulesNothingOutUntilItIsListed(): void
    {
        // Another host already signs with the next key, which this host's configuration does not list yet.
        $next = new SodiumEvidenceSigner('app-key-2', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        (new AuditLog($this->store, $next, TrustedKeys::single($next->keyId(), $next->publicKey()), new MockClock('2026-11-08 13:00:00')))
            ->append('acme', 'connection.revoked', 'grant-3', ['userId' => 'bob']);
        self::assertSame([], $this->log->unrecorded('passkey.revoked', ['laptop' => 1]), 'An entry it cannot check could be the revocation.');

        $listed = new TrustedKeys([...$this->log->trustedKeys()->all(), new TrustedKey($next->keyId(), $next->publicKey())]);
        $updated = new AuditLog($this->store, $next, $listed, new MockClock('2026-11-08 13:00:00'));
        self::assertSame(['laptop'], $updated->unrecorded('passkey.revoked', ['laptop' => 1]));
    }

    public function testAnEntryTheLogRefusesRulesOutNothingBeforeIt(): void
    {
        // A host that still signs with this key after its window closed: the signature verifies, the log refuses it.
        $late = new AuditLog($this->store, $this->signer, $this->log->trustedKeys(), new MockClock('2026-11-08 13:00:00'));
        $late->append('acme', 'connection.revoked', 'grant-3', ['userId' => 'bob']);
        $this->log->append('acme', 'passkey.enrolled', 'tablet', ['userId' => 'alice']);
        $closed = new AuditLog($this->store, $this->signer, new TrustedKeys([new TrustedKey('app-key', $this->signer->publicKey(), null, TrustedKey::time('2026-11-08T12:30:00Z'))]), new MockClock('2026-11-08 12:00:00'));

        self::assertSame(['tablet'], $closed->unrecorded('passkey.revoked', ['laptop' => 1, 'phone' => 4, 'tablet' => 7]), 'It could be what is left of a revocation rewritten with that key.');
        self::assertSame(['laptop', 'phone', 'tablet'], $this->log->unrecorded('passkey.revoked', ['laptop' => 1, 'phone' => 4, 'tablet' => 7]), 'Keys without a window refuse nothing.');
    }

    public function testDamageBeforeTheSubjectCountsAgainstWhatAClosedKeySignedSince(): void
    {
        // The switch: app-key's window closes, and the new key's first entry vouches for what it signed. No rotation
        // is recorded yet.
        $next = new SodiumEvidenceSigner('app-key-2', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $keys = new TrustedKeys([new TrustedKey('app-key-2', $next->publicKey(), TrustedKey::time('2026-11-08T12:30:00Z')), new TrustedKey('app-key', $this->signer->publicKey(), null, TrustedKey::time('2026-11-08T12:30:00Z'))]);
        $rotated = new AuditLog($this->store, $next, $keys, new MockClock('2026-11-08 13:00:00'));
        $rotated->append('acme', 'connection.revoked', 'grant-3', ['userId' => 'bob']);
        self::assertSame(['phone'], $rotated->unrecorded('passkey.revoked', ['phone' => 4]));

        // Entry 2 no longer matches its hash. It comes before the phone's enrollment, but what the closed key signed
        // after it counts for nothing now, the phone's enrollment and entry 5 among them.
        $this->store->rows[2]['hash'] = str_repeat('0', 64);
        self::assertSame([], $rotated->unrecorded('passkey.revoked', ['phone' => 4]));
    }

    public function testAnEntryAfterTheRotationAwayFromItsKeyRulesNothingOutEvenWithTheKeyReopened(): void
    {
        $next = new SodiumEvidenceSigner('app-key-2', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $closing = new TrustedKeys([new TrustedKey('app-key-2', $next->publicKey(), TrustedKey::time('2026-11-08T12:00:00Z')), new TrustedKey('app-key', $this->signer->publicKey(), null, TrustedKey::time('2026-11-08T12:00:00Z'))]);
        (new AuditLog($this->store, $next, $closing, new MockClock('2026-11-08 12:00:00')))->recordRotation('app-key');
        $this->log->append('acme', 'passkey.enrolled', 'tablet', ['userId' => 'alice']);

        // An operator lists the old key without an end again. The chain still moved trust away from it at entry 6.
        $reopened = new AuditLog($this->store, $next, new TrustedKeys([new TrustedKey('app-key-2', $next->publicKey(), TrustedKey::time('2026-11-08T12:00:00Z')), new TrustedKey('app-key', $this->signer->publicKey())]), new MockClock('2026-11-08 12:00:00'));
        self::assertSame([], $reopened->unrecorded('passkey.revoked', ['tablet' => 7]));
        self::assertSame([], $reopened->unrecorded('passkey.revoked', ['laptop' => 1]), 'Entry 7 follows the laptop\'s enrollment too.');
    }

    public function testAnEntryRemovedSinceOrMissingRulesNothingOut(): void
    {
        unset($this->store->rows[5]);
        self::assertSame(['laptop', 'phone'], $this->log->unrecorded('passkey.revoked', ['laptop' => 1, 'phone' => 4]), 'Only a checkpoint shows the newest entries cut off.');
        unset($this->store->rows[3]);
        self::assertSame(['phone'], $this->log->unrecorded('passkey.revoked', ['laptop' => 1, 'phone' => 4]));
        self::assertSame([], $this->log->unrecorded('passkey.revoked', ['grant-1' => 3]), 'The subject\'s own entry is missing.');
        self::assertSame([], $this->log->unrecorded('passkey.revoked', ['later' => 9]), 'The log does not reach it.');
        $this->store->rows[4]['signature'] = 'AAAA';
        self::assertSame([], $this->log->unrecorded('passkey.revoked', ['phone' => 4]), 'The subject\'s own entry no longer verifies.');
    }
}
