<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Audit\AuditStore;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Tests\Support\InMemoryAuditStore;

/**
 * What still stands: AuditLog::standing() weighs several subjects at once, each as find() and unrecorded() weigh it
 * alone, from one reading of the chain.
 */
final class AuditStandingTest extends TestCase
{
    private const SUBJECTS = ['laptop', '123', 'phone', 'tablet', 'missing'];

    private InMemoryAuditStore $store;
    private SodiumEvidenceSigner $old;
    private SodiumEvidenceSigner $new;

    protected function setUp(): void
    {
        // Three passkeys enrolled under app-key; then the switch to app-key-2, recorded, a fourth passkey enrolled
        // under the new key, and the first passkey revoked.
        $this->store = new InMemoryAuditStore();
        $this->old = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->new = new SodiumEvidenceSigner('app-key-2', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $before = new AuditLog($this->store, $this->old, TrustedKeys::single($this->old->keyId(), $this->old->publicKey()), new MockClock('2026-11-08 12:00:00'));
        $before->append('acme', 'passkey.enrolled', 'laptop', ['userId' => 'alice']);
        $before->append('acme', 'passkey.enrolled', '123', ['userId' => 'alice']);
        $before->append('acme', 'connection.revoked', 'grant-1', ['userId' => 'alice']);
        $before->append('acme', 'passkey.enrolled', 'phone', ['userId' => 'alice']);
        $after = $this->log($this->store);
        $after->recordRotation('app-key');
        $after->append('acme', 'passkey.enrolled', 'tablet', ['userId' => 'alice']);
        $after->append('acme', 'passkey.revoked', 'laptop', ['userId' => 'alice']);
    }

    /** @return iterable<string, array{\Closure(InMemoryAuditStore, SodiumEvidenceSigner): void, list<string>}> the edit, and the subjects that stand after it */
    public static function chains(): iterable
    {
        yield 'as recorded' => [static function (): void {
        }, ['123', 'phone', 'tablet']];
        yield 'the revocation, the newest entry, cut off' => [static function (InMemoryAuditStore $store): void {
            unset($store->rows[7]);
        }, ['laptop', '123', 'phone', 'tablet']];
        yield 'the revocation\'s signature broken' => [static function (InMemoryAuditStore $store): void {
            $store->rows[7]['signature'] = 'AAAA';
        }, []];
        yield 'an entry before the rotation edited' => [static function (InMemoryAuditStore $store): void {
            $store->rows[3]['hash'] = str_repeat('0', 64);
        }, ['tablet']];
        yield 'the rotation signed again with the retired key' => [static function (InMemoryAuditStore $store, SodiumEvidenceSigner $old): void {
            $store->rows[5]['key_id'] = $old->keyId();
            $store->rows[5]['signature'] = $old->sign($store->rows[5]['entry']);
        }, ['tablet']];
    }

    /**
     * @param \Closure(InMemoryAuditStore, SodiumEvidenceSigner): void $edit
     * @param list<string>                                             $standing
     */
    #[DataProvider('chains')]
    public function testEachSubjectStandsAsFindAndUnrecordedSayFromOneReadingOfTheChain(\Closure $edit, array $standing): void
    {
        $edit($this->store, $this->old);
        $alone = [];
        $log = $this->log($this->store);
        foreach (self::SUBJECTS as $subject) {
            $entry = $log->find('passkey.enrolled', $subject);
            if (null !== $entry && [] !== $log->unrecorded('passkey.revoked', [$subject => $entry['seq']])) {
                $alone[$subject] = $entry;
            }
        }

        $counted = $this->counted();
        $weighed = $this->log($counted)->standing('passkey.enrolled', self::SUBJECTS, 'passkey.revoked');
        self::assertSame($standing, array_map(strval(...), array_keys($weighed)));
        self::assertEquals($alone, $weighed, 'Each stands as it would alone, with the entry find() returns.');
        self::assertSame(1, $counted->readings, 'Three of them were enrolled under the retired key, and the chain was read once.');
    }

    public function testNothingToWeighReadsNothing(): void
    {
        $counted = $this->counted();
        self::assertSame([], $this->log($counted)->standing('passkey.enrolled', ['missing'], 'passkey.revoked'));
        self::assertSame([], $this->log($counted)->standing('passkey.enrolled', [], 'passkey.revoked'));
        self::assertSame(0, $counted->readings);
    }

    private function log(AuditStore $store): AuditLog
    {
        return new AuditLog($store, $this->new, new TrustedKeys([
            new TrustedKey('app-key-2', $this->new->publicKey(), TrustedKey::time('2026-11-08T12:30:00Z')),
            new TrustedKey('app-key', $this->old->publicKey(), null, TrustedKey::time('2026-11-08T12:30:00Z')),
        ]), new MockClock('2026-11-08 13:00:00'));
    }

    /** The fixture's store, counting how often the whole chain is read. */
    private function counted(): AuditStore
    {
        return new class($this->store) implements AuditStore {
            public int $readings = 0;

            public function __construct(private AuditStore $inner)
            {
            }

            public function append(callable $buildRecord): array
            {
                return $this->inner->append($buildRecord);
            }

            public function recordsFor(string $type, string $subject): iterable
            {
                return $this->inner->recordsFor($type, $subject);
            }

            public function records(): iterable
            {
                ++$this->readings;

                return $this->inner->records();
            }
        };
    }
}
