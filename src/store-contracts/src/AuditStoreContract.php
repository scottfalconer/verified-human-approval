<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\StoreContracts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Audit\AuditStore;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;

/**
 * The audit storage contract (the rules on AuditStore), run against one store through its harness: rows are kept
 * exactly and returned unfiltered, appenders are serialized before the head is read, even for an empty log, a
 * host's transaction is joined and never ended, and nothing fails halfway. To run it for another store, write an
 * AuditHarness for it and a test class that extends this one and names that harness.
 */
abstract class AuditStoreContract extends TestCase
{
    private ?string $location = null;
    private string $secretKey;
    private SodiumEvidenceSigner $signer;

    /** @var list<AuditHarness> every harness connect() gave this test, closed when it ends */
    private array $harnesses = [];

    /** @return class-string<AuditHarness> */
    abstract protected static function harness(): string;

    protected function setUp(): void
    {
        $this->location = static::harness()::prepare();
        $this->secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->signer = new SodiumEvidenceSigner('app-key', $this->secretKey);
    }

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            $harness->close();
        }
        $this->harnesses = [];
        if (null !== $this->location) {
            static::harness()::destroy($this->location);
        }
    }

    public function testAChainInTheFirstEntryFormatStillVerifiesAndExtends(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../resources/audit-v1-chain.json'), true, 16, \JSON_THROW_ON_ERROR);
        // The fixture's synthetic key comes from a public seed, so this test can append with the same key.
        $pair = sodium_crypto_sign_seed_keypair(hash('sha256', 'verified-human-approval audit fixture v1', true));
        $signer = new SodiumEvidenceSigner($fixture['keyId'], sodium_crypto_sign_secretkey($pair));
        self::assertSame($fixture['publicKey'], $signer->publicKey());
        $harness = $this->connect();
        foreach ($fixture['rows'] as $row) {
            $harness->insertRaw($row);
        }
        $log = new AuditLog($harness->store(), $signer, TrustedKeys::single($fixture['keyId'], $fixture['publicKey']), new MockClock('2026-11-09 10:00:00 UTC'));

        $report = $log->verify();
        self::assertTrue($report['intact']);
        self::assertSame(4, $report['newest']);
        self::assertSame('Laptop', self::found($log->find('passkey.enrolled', 'cred-1'))['data']->label);

        $entry = $log->append('tenant-b', 'connection.revoked', 'grant-10', ['userId' => 'bob', 'by' => 'admin']);
        self::assertSame([5, $fixture['rows'][3]['hash'], AuditLog::FORMAT], [$entry['seq'], $entry['previous'], $entry['format']]);
        $rows = [...$this->connect()->store()->records()];
        self::assertSame($fixture['rows'], \array_slice($rows, 0, 4), 'Earlier rows keep their exact bytes.');
        self::assertStringContainsString('"data":{}', $rows[2]['entry'], 'Empty event data stays a JSON object.');
        self::assertTrue($log->verify()['intact']);
    }

    /** @return iterable<string, array{\Closure(AuditHarness, EvidenceSigner): void}> */
    public static function damage(): iterable
    {
        $row2 = static fn (string $assignment): \Closure => static function (AuditHarness $harness) use ($assignment): void {
            $harness->damage(2, $assignment);
        };
        yield 'its entry' => [$row2("entry = REPLACE(entry, '\"bob\"', '\"eve\"')")];
        yield 'its hash' => [$row2("hash = '".str_repeat('0', 64)."'")];
        yield 'its signature' => [static function (AuditHarness $harness): void {
            $forger = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
            $harness->damage(2, "signature = '".$forger->sign($harness->column(2, 'entry'))."'");
        }];
        yield 'its sequence' => [$row2('seq = 7')];
        yield 'its type' => [$row2("type = 'passkey.revoked'")];
        yield 'its subject' => [$row2("subject = 'key-9'")];
        yield 'its tenant' => [$row2("tenant_id = 'tenant-z'")];
    }

    /** @param \Closure(AuditHarness): void $damage */
    #[DataProvider('damage')]
    public function testEveryPartOfARowThatIsSignedOrBoundIsChecked(\Closure $damage): void
    {
        $log = $this->populatedLog();
        $damage($this->connect());
        self::assertFalse($log->verify()['intact']);
        self::assertNull($log->find('passkey.enrolled', 'key-2'), 'A damaged row vouches for nothing.');
        $log->append('tenant-a', 'passkey.enrolled', 'key-4', ['userId' => 'alice']);
        self::assertFalse($log->verify()['intact'], 'A later append does not erase the damage.');
    }

    public function testTheHeadIsTheNewestStoredRowEvenWhenItIsDamaged(): void
    {
        $log = $this->populatedLog();
        $damager = $this->connect();
        $damager->damage(3, "entry = REPLACE(entry, 'alert-1', 'alert-2')");
        $storedHash = $damager->column(3, 'hash');
        $entry = $log->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice']);
        self::assertSame([4, $storedHash], [$entry['seq'], $entry['previous']], 'An append follows the damaged head, not an older valid row, so the damage stays in view.');
        self::assertSame([true, true, false, true], array_column($log->verify()['entries'], 'valid'));
    }

    public function testFindReturnsTheNewestEntryThatVerifies(): void
    {
        $log = $this->populatedLog();
        $log->append('tenant-a', 'passkey.enrolled', 'key-1', ['userId' => 'alice', 'label' => 'newer']);
        self::assertSame('newer', self::found($log->find('passkey.enrolled', 'key-1'))['data']->label);
        $this->connect()->damage(4, "signature = 'AAAA'");
        self::assertSame(1, self::found($log->find('passkey.enrolled', 'key-1'))['seq'], 'A damaged newer entry is passed over for an older one that verifies.');
    }

    public function testSubjectsAndTypesCompareExactly(): void
    {
        $log = $this->populatedLog();
        $long = str_repeat('Ab_-', 341); // a 1,364-character passkey id, the longest WebAuthn allows
        $log->append('tenant-a', 'passkey.enrolled', $long, ['userId' => 'alice']);
        $log->append('tenant-a', 'passkey.enrolled', 'KEY-1', ['userId' => 'mallory']);
        self::assertSame(4, self::found($log->find('passkey.enrolled', $long))['seq']);
        self::assertSame('alice', self::found($log->find('passkey.enrolled', 'key-1'))['data']->userId, 'Subjects differing only in case are different subjects.');
        self::assertSame('mallory', self::found($log->find('passkey.enrolled', 'KEY-1'))['data']->userId);
        self::assertNull($log->find('passkey.enrolled', 'key-1 '), 'A trailing space makes another subject.');
        self::assertNull($log->find('Passkey.enrolled', 'key-1'));
        self::assertTrue($log->verify()['intact']);
    }

    public function testAnAppendInsideTheHostsTransactionCommitsOrRollsBackWithIt(): void
    {
        $this->populatedLog();
        $host = $this->connect();
        $log = $this->log($host);
        foreach (['rollBack' => 0, 'commit' => 1] as $end => $expected) {
            $host->begin();
            $host->writeBusinessRow();
            $log->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice']);
            self::assertTrue($host->inTransaction(), 'The store leaves the host’s transaction to the host.');
            $host->{$end}();
            self::assertSame($expected, $this->connect()->businessRows());
            self::assertSame(0 === $expected ? null : 4, $this->log($this->connect())->find('passkey.revoked', 'key-1')['seq'] ?? null, 'The entry ends with the change it records: '.$end);
        }
        self::assertTrue($log->verify()['intact']);
    }

    /** @return iterable<string, array{bool}> */
    public static function logs(): iterable
    {
        yield 'an empty log' => [false];
        yield 'a log with entries' => [true];
    }

    #[DataProvider('logs')]
    public function testAJoinedAppendHoldsOffOtherAppendersFromBeforeItReadsTheHead(bool $populated): void
    {
        $prior = 0;
        if ($populated) {
            $this->log($this->connect())->append('tenant-a', 'passkey.enrolled', 'key-1', ['userId' => 'alice']);
            $prior = 1;
        }
        $host = $this->connect();
        $other = $this->connect();
        $other->lockTimeout(200);
        $otherLog = $this->log($other);
        $outcome = null;
        // While the host's append is between reading the head and inserting, another appender must wait.
        $hooked = new class($host->store(), static function () use ($other, $otherLog, &$outcome): void {
            try {
                $otherLog->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob']);
                $outcome = 'went ahead';
            } catch (\Throwable $error) {
                $outcome = $other->isLockTimeout($error) ? 'held off' : $error->getMessage();
            }
        }) implements AuditStore {
            public function __construct(private AuditStore $inner, private \Closure $whileHoldingTheHead)
            {
            }

            public function append(callable $buildRecord): array
            {
                return $this->inner->append(function (?array $head) use ($buildRecord): array {
                    ($this->whileHoldingTheHead)();

                    return $buildRecord($head);
                });
            }

            public function recordsFor(string $type, string $subject): iterable
            {
                return $this->inner->recordsFor($type, $subject);
            }

            public function records(): iterable
            {
                return $this->inner->records();
            }
        };
        $host->begin(); // deferred, as a host's own transaction often is
        $entry = (new AuditLog($hooked, $this->signer, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()), new MockClock()))->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice']);
        self::assertSame('held off', $outcome, 'Appenders are serialized before the head is read, even for an empty log.');
        self::assertSame($prior + 1, $entry['seq']);

        // It lasts until the host's transaction ends, then the other appender follows on.
        try {
            $otherLog->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob']);
            self::fail('Another appender went ahead while the host’s transaction was still open.');
        } catch (\Throwable $error) {
            self::assertTrue($other->isLockTimeout($error), 'Held off by a lock: '.$error->getMessage());
        }
        $host->commit();
        $other->lockTimeout(15000);
        self::assertSame($prior + 2, $otherLog->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob'])['seq']);
        self::assertTrue($otherLog->verify()['intact']);
    }

    /**
     * The host reads the log, another connection appends, and then the host appends in the same transaction. On
     * SQLite the append fails, since an older snapshot cannot become a writer; on a database that reads the newest
     * committed row under a lock, it follows that row. Either way the chain never forks.
     */
    public function testAJoinedAppendAfterTheHostReadAnOlderViewNeverForksTheChain(): void
    {
        $host = $this->connect();
        if ($host->readersBlockWriters()) {
            self::markTestSkipped('Here the host’s read keeps the other appender from committing at all.');
        }
        $hostLog = $this->log($host);
        $hostLog->append('tenant-a', 'passkey.enrolled', 'key-1', ['userId' => 'alice']);
        $host->begin();
        $host->read(); // the host now holds a view of the log
        $this->log($this->connect())->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob']);
        if ($host->appendsFromAnOlderViewFail()) {
            try {
                $hostLog->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice']);
                self::fail('An append from a stale snapshot went through.');
            } catch (\Throwable $error) {
                self::assertTrue($host->isLockTimeout($error), 'Refused by the database: '.$error->getMessage());
            }
            $host->rollBack();
            $newest = 2;
        } else {
            self::assertSame(3, $hostLog->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice'])['seq'], 'The append follows the newest row, not the view the host read.');
            $host->commit();
            $newest = 3;
        }
        $report = $this->log($this->connect())->verify();
        self::assertTrue($report['intact']);
        self::assertSame($newest, $report['newest']);
    }

    public function testASigningOrInsertFailureLeavesNoEntryAndNothingHalfOpen(): void
    {
        $log = $this->populatedLog();
        $harness = $this->connect();
        $outage = new class implements EvidenceSigner {
            public function keyId(): string
            {
                return 'app-key';
            }

            public function sign(string $manifest): string
            {
                throw new \RuntimeException('Injected signer outage.');
            }

            public function publicKey(): string
            {
                throw new \RuntimeException('Injected signer outage.');
            }
        };
        try {
            (new AuditLog($harness->store(), $outage, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()), new MockClock()))->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice']);
            self::fail('An entry was recorded without its signature.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected signer outage.', $error->getMessage());
        }
        self::assertSame(3, $log->verify()['newest']);

        $harness->failInserts();
        $builds = 0;
        try {
            $harness->store()->append(static function (?array $head) use (&$builds): array {
                ++$builds;

                return ['seq' => ($head['seq'] ?? 0) + 1, 'tenant_id' => 'tenant-a', 'type' => 'x', 'subject' => 'y', 'entry' => '{}', 'hash' => 'h', 'key_id' => 'k', 'signature' => 's'];
            });
            self::fail('A rejected insert was reported as appended.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('injected insert failure', $error->getMessage());
        }
        self::assertSame(1, $builds, 'The row was built once: nothing retries it.');
        self::assertFalse($harness->inTransaction(), 'Nothing was left open.');
        $harness->clearInsertFailures();
        self::assertSame(4, $this->log($harness)->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice'])['seq'], 'The next append works.');
    }

    #[DataProvider('logs')]
    public function testConcurrentAppendersKeepOneOrderedChain(bool $populated): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('Real concurrency needs proc_open.');
        }
        $log = $this->log($this->connect());
        $before = $populated ? 3 : 0;
        for ($i = 1; $i <= $before; ++$i) {
            $log->append('tenant-a', 'passkey.enrolled', 'key-'.$i, ['userId' => 'alice']);
        }
        $directory = sys_get_temp_dir().'/verified-human-approval-audit-race-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        try {
            // The workers sign with this test's key, so one trusted public key checks the whole chain.
            $secret = ChallengeBinding::encode($this->secretKey);
            $results = ProcessRace::run(__DIR__.'/../bin/audit-worker.php', array_map(fn (int $worker): array => [
                'harness' => static::harness(), 'location' => $this->location(), 'keyId' => 'app-key', 'secretKey' => $secret,
                'appends' => 5, 'worker' => 'w'.$worker, 'tenant' => 0 === $worker % 2 ? 'tenant-a' : 'tenant-b',
            ], range(1, 4)), $directory);
        } finally {
            rmdir($directory);
        }
        $sequences = array_merge(...array_column($results, 'sequences'));
        sort($sequences);
        self::assertSame(range($before + 1, $before + 20), $sequences, 'Every append got its own place in the chain.');
        $report = $log->verify();
        self::assertTrue($report['intact']);
        self::assertSame($before + 20, $report['newest']);
    }

    /** Where this test's store is: set up before every test. */
    private function location(): string
    {
        return $this->location ?? throw new \LogicException('The store was not prepared.');
    }

    private function connect(): AuditHarness
    {
        $harness = static::harness()::connect($this->location());
        $this->harnesses[] = $harness;

        return $harness;
    }

    private function log(AuditHarness $harness): AuditLog
    {
        return new AuditLog($harness->store(), $this->signer, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()), new MockClock('2026-11-09 10:00:00 UTC'));
    }

    /**
     * @param array<string, mixed>|null $entry
     *
     * @return array<string, mixed>
     */
    private static function found(?array $entry): array
    {
        self::assertNotNull($entry, 'The entry is found.');

        return $entry;
    }

    /** Three entries: two tenants, and one with empty data. */
    private function populatedLog(): AuditLog
    {
        $log = $this->log($this->connect());
        $log->append('tenant-a', 'passkey.enrolled', 'key-1', ['userId' => 'alice']);
        $log->append('tenant-b', 'passkey.enrolled', 'key-2', ['userId' => 'bob']);
        $log->append('tenant-a', 'alert.taken_down', 'alert-1', []);

        return $log;
    }
}
