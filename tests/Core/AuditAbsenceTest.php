<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Tests\Support\InMemoryAuditStore;

/**
 * What the chain shows was never recorded: AuditLog::unrecorded() rules an entry out only from the whole chain,
 * and only while everything since the subject's own entry is kept as it was written.
 */
final class AuditAbsenceTest extends TestCase
{
    private InMemoryAuditStore $store;
    private AuditLog $log;

    protected function setUp(): void
    {
        $this->store = new InMemoryAuditStore();
        $signer = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->log = new AuditLog($this->store, $signer, TrustedKeys::single($signer->keyId(), $signer->publicKey()), new MockClock('2026-11-08 12:00:00'));
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
