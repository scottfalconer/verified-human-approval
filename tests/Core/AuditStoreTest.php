<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Audit\AuditStore;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;
use VerifiedHumanApproval\Tests\Support\InMemoryAuditStore;
use VerifiedHumanApproval\Tests\Support\ProcessRace;

/**
 * The audit log's signing and checking over any store, and the SQLite store's rules: appenders are
 * serialized before the head is read, a host's transaction is joined and never ended, and nothing fails halfway.
 */
final class AuditStoreTest extends TestCase
{
    private const TABLE = 'verified_human_approval_audit';
    private string $directory;
    private string $secretKey;
    private SodiumEvidenceSigner $signer;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/verified-human-approval-audit-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->signer = new SodiumEvidenceSigner('app-key', $this->secretKey);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testAChainWrittenBeforeTheStoreWasExtractedStillVerifiesAndExtends(): void
    {
        $fixture = json_decode((string) file_get_contents(\dirname(__DIR__).'/fixtures/audit/v1-chain.json'), true, 16, \JSON_THROW_ON_ERROR);
        // The fixture's synthetic key comes from a public seed, so this test can append with the same key.
        $pair = sodium_crypto_sign_seed_keypair(hash('sha256', 'verified-human-approval audit fixture v1', true));
        $signer = new SodiumEvidenceSigner($fixture['keyId'], sodium_crypto_sign_secretkey($pair));
        self::assertSame($fixture['publicKey'], $signer->publicKey());
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $store = new PdoAuditStore($pdo);
        $store->initialize();
        $insert = $pdo->prepare('INSERT INTO '.self::TABLE.' (seq, tenant_id, type, subject, entry, hash, key_id, signature) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($fixture['rows'] as $row) {
            $insert->execute(array_values($row));
        }
        $log = new AuditLog($store, $signer, $fixture['publicKey'], new MockClock('2026-11-09 10:00:00 UTC'));

        $report = $log->verify();
        self::assertTrue($report['intact']);
        self::assertSame(4, $report['newest']);
        self::assertSame('Laptop', $log->find('passkey.enrolled', 'cred-1')['data']->label);

        $entry = $log->append('tenant-b', 'connection.revoked', 'grant-10', ['userId' => 'bob', 'by' => 'admin']);
        self::assertSame([5, $fixture['rows'][3]['hash'], AuditLog::FORMAT], [$entry['seq'], $entry['previous'], $entry['format']]);
        $rows = [...$store->records()];
        self::assertSame($fixture['rows'], \array_slice($rows, 0, 4), 'Earlier rows keep their exact bytes.');
        self::assertStringContainsString('"data":{}', $rows[2]['entry'], 'Empty event data stays a JSON object.');
        self::assertTrue($log->verify()['intact']);
    }

    public function testTheLogSignsAndChecksOverAStoreWithoutPdo(): void
    {
        $store = new InMemoryAuditStore();
        $log = new AuditLog($store, $this->signer, $this->signer->publicKey(), new MockClock('2026-11-09 10:00:00 UTC'));
        $log->append('tenant-a', 'passkey.enrolled', 'key-1', ['userId' => 'alice']);
        $log->append('tenant-b', 'passkey.enrolled', 'key-2', ['userId' => 'bob']);
        $log->append('tenant-a', 'alert.taken_down', 'alert-1', []);

        $report = $log->verify();
        self::assertTrue($report['intact'], 'One chain spans both tenants.');
        self::assertSame([1, 2, 3], array_column($report['entries'], 'seq'));
        self::assertSame('tenant-b', $log->find('passkey.enrolled', 'key-2')['tenantId']);
        self::assertStringContainsString('"data":{}', $store->rows[3]['entry']);
        self::assertSame(3, $store->builds, 'One build per append.');

        $store->rows[2]['tenant_id'] = 'tenant-a';
        self::assertFalse($log->verify()['intact'], 'Checking needs no database.');
        self::assertNull($log->find('passkey.enrolled', 'key-2'));
    }

    /** @return iterable<string, array{\Closure(\PDO): void}> */
    public static function damage(): iterable
    {
        $row2 = static fn (string $set): \Closure => static function (\PDO $pdo) use ($set): void {
            $pdo->exec('UPDATE '.self::TABLE.' SET '.$set.' WHERE seq = 2');
        };
        yield 'its entry' => [$row2("entry = replace(entry, '\"bob\"', '\"eve\"')")];
        yield 'its hash' => [$row2("hash = '".str_repeat('0', 64)."'")];
        yield 'its signature' => [static function (\PDO $pdo): void {
            $forger = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
            $entry = (string) $pdo->query('SELECT entry FROM '.self::TABLE.' WHERE seq = 2')->fetchColumn();
            $pdo->prepare('UPDATE '.self::TABLE.' SET signature = ? WHERE seq = 2')->execute([$forger->sign($entry)]);
        }];
        yield 'its sequence' => [$row2('seq = 7')];
        yield 'its type' => [$row2("type = 'passkey.revoked'")];
        yield 'its subject' => [$row2("subject = 'key-9'")];
        yield 'its tenant' => [$row2("tenant_id = 'tenant-z'")];
    }

    /** @param \Closure(\PDO): void $damage */
    #[DataProvider('damage')]
    public function testEveryPartOfARowThatIsSignedOrBoundIsChecked(\Closure $damage): void
    {
        [$pdo, $log] = $this->memoryLog();
        $damage($pdo);
        self::assertFalse($log->verify()['intact']);
        self::assertNull($log->find('passkey.enrolled', 'key-2'), 'A damaged row vouches for nothing.');
        $log->append('tenant-a', 'passkey.enrolled', 'key-4', ['userId' => 'alice']);
        self::assertFalse($log->verify()['intact'], 'A later append does not erase the damage.');
    }

    public function testTheHeadIsTheNewestStoredRowEvenWhenItIsDamaged(): void
    {
        [$pdo, $log] = $this->memoryLog();
        $pdo->exec('UPDATE '.self::TABLE." SET entry = replace(entry, 'alert-1', 'alert-2') WHERE seq = 3");
        $storedHash = $pdo->query('SELECT hash FROM '.self::TABLE.' WHERE seq = 3')->fetchColumn();
        $entry = $log->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice']);
        self::assertSame([4, $storedHash], [$entry['seq'], $entry['previous']], 'An append follows the damaged head, not an older valid row, so the damage stays in view.');
        self::assertSame([true, true, false, true], array_column($log->verify()['entries'], 'valid'));
    }

    public function testFindReturnsTheNewestEntryThatVerifies(): void
    {
        [$pdo, $log] = $this->memoryLog();
        $log->append('tenant-a', 'passkey.enrolled', 'key-1', ['userId' => 'alice', 'label' => 'newer']);
        self::assertSame('newer', $log->find('passkey.enrolled', 'key-1')['data']->label);
        $pdo->exec('UPDATE '.self::TABLE." SET signature = 'AAAA' WHERE seq = 4");
        self::assertSame(1, $log->find('passkey.enrolled', 'key-1')['seq'], 'A damaged newer entry is passed over for an older one that verifies.');
    }

    public function testAnAppendInsideTheHostsTransactionCommitsOrRollsBackWithIt(): void
    {
        [$pdo, $log] = $this->memoryLog();
        $pdo->exec('CREATE TABLE business (note TEXT)');
        foreach (['rollBack' => 0, 'commit' => 1] as $end => $expected) {
            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO business (note) VALUES ('revoked')");
            $log->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice']);
            self::assertTrue($pdo->inTransaction(), 'The store leaves the host’s transaction to the host.');
            $pdo->{$end}();
            self::assertSame($expected, (int) $pdo->query('SELECT COUNT(*) FROM business')->fetchColumn());
            self::assertSame(0 === $expected ? null : 4, $log->find('passkey.revoked', 'key-1')['seq'] ?? null, 'The entry ends with the change it records: '.$end);
        }
        self::assertTrue($log->verify()['intact']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function journals(): iterable
    {
        foreach (['delete', 'wal'] as $journal) {
            yield $journal.' journal, empty log' => [$journal, false];
            yield $journal.' journal, log with entries' => [$journal, true];
        }
    }

    #[DataProvider('journals')]
    public function testAJoinedAppendReservesTheLogBeforeReadingItsHead(string $journal, bool $populated): void
    {
        $file = $this->directory.'/audit.sqlite';
        [$host, , $hostStore] = $this->fileLog($file, $journal, initialize: true);
        $prior = $populated ? 1 : 0;
        if ($populated) {
            $this->fileLog($file, $journal)[1]->append('tenant-a', 'passkey.enrolled', 'key-1', ['userId' => 'alice']);
        }
        [, $other] = $this->fileLog($file, $journal, busyTimeoutMs: 100);
        $outcome = null;
        // While the host's append is between reading the head and inserting, another appender must wait.
        $hooked = new class($hostStore, static function () use ($other, &$outcome): void {
            try {
                $other->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob']);
                $outcome = 'went ahead';
            } catch (\PDOException $error) {
                $outcome = str_contains($error->getMessage(), 'locked') ? 'held off' : $error->getMessage();
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
        $host->beginTransaction(); // deferred, as a host's own transaction often is
        $entry = (new AuditLog($hooked, $this->signer, $this->signer->publicKey(), new MockClock()))->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice']);
        self::assertSame('held off', $outcome, 'The reservation is taken before the head is read, even for an empty log.');
        self::assertSame($prior + 1, $entry['seq']);

        // It lasts until the host's transaction ends, then the other appender follows on.
        try {
            $other->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob']);
            self::fail('Another appender went ahead while the host’s transaction was still open.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('locked', $error->getMessage());
        }
        $host->commit();
        self::assertSame($prior + 2, $other->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob'])['seq']);
        self::assertTrue($other->verify()['intact']);
    }

    public function testAJoinedAppendOnAStaleSnapshotFailsInsteadOfForkingTheChain(): void
    {
        $file = $this->directory.'/audit.sqlite';
        [$host, $hostLog] = $this->fileLog($file, 'wal', initialize: true);
        $hostLog->append('tenant-a', 'passkey.enrolled', 'key-1', ['userId' => 'alice']);
        [, $other] = $this->fileLog($file, 'wal');
        $host->beginTransaction();
        $host->query('SELECT COUNT(*) FROM '.self::TABLE)->fetchColumn(); // the host now reads a snapshot
        $other->append('tenant-b', 'connection.revoked', 'grant-1', ['userId' => 'bob']);
        try {
            $hostLog->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice']);
            self::fail('An append from a stale snapshot went through.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('locked', $error->getMessage());
        }
        $host->rollBack();
        $report = $hostLog->verify();
        self::assertTrue($report['intact']);
        self::assertSame(2, $report['newest']);
    }

    public function testASigningOrInsertFailureLeavesNoEntryAndNothingHalfOpen(): void
    {
        [$pdo, $log, $store] = $this->memoryLog();
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
            (new AuditLog($store, $outage, $this->signer->publicKey(), new MockClock()))->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice']);
            self::fail('An entry was recorded without its signature.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected signer outage.', $error->getMessage());
        }
        self::assertSame(3, $log->verify()['newest']);

        $pdo->exec('CREATE TRIGGER reject_audit BEFORE INSERT ON '.self::TABLE." BEGIN SELECT RAISE(ABORT, 'injected insert failure'); END");
        $builds = 0;
        try {
            $store->append(static function (?array $head) use (&$builds): array {
                ++$builds;

                return ['seq' => ($head['seq'] ?? 0) + 1, 'tenant_id' => 'tenant-a', 'type' => 'x', 'subject' => 'y', 'entry' => '{}', 'hash' => 'h', 'key_id' => 'k', 'signature' => 's'];
            });
            self::fail('A rejected insert was reported as appended.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('injected insert failure', $error->getMessage());
        }
        self::assertSame(1, $builds, 'The row was built once: nothing retries it.');
        $pdo->exec('DROP TRIGGER reject_audit');
        self::assertSame(4, $log->append('tenant-a', 'passkey.revoked', 'key-1', ['userId' => 'alice'])['seq'], 'Nothing was left open: the next append works.');
    }

    #[DataProvider('journals')]
    public function testConcurrentAppendersKeepOneOrderedChain(string $journal, bool $populated): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('Real concurrency needs proc_open.');
        }
        $file = $this->directory.'/audit.sqlite';
        [, $log] = $this->fileLog($file, $journal, initialize: true);
        $before = $populated ? 3 : 0;
        for ($i = 1; $i <= $before; ++$i) {
            $log->append('tenant-a', 'passkey.enrolled', 'key-'.$i, ['userId' => 'alice']);
        }
        // The workers sign with this test's key, so one trusted public key checks the whole chain.
        $secret = ChallengeBinding::encode($this->secretKey);
        $results = ProcessRace::run(\dirname(__DIR__).'/Support/audit-append-worker.php', array_map(static fn (int $worker): array => [
            'database' => $file, 'keyId' => 'app-key', 'secretKey' => $secret, 'appends' => 5, 'worker' => 'w'.$worker, 'tenant' => 0 === $worker % 2 ? 'tenant-a' : 'tenant-b',
        ], range(1, 4)), $this->directory);
        $sequences = array_merge(...array_column($results, 'sequences'));
        sort($sequences);
        self::assertSame(range($before + 1, $before + 20), $sequences, 'Every append got its own place in the chain.');
        $report = $log->verify();
        self::assertTrue($report['intact']);
        self::assertSame($before + 20, $report['newest']);
    }

    /** @return array{\PDO, AuditLog, PdoAuditStore} three entries: two tenants, and one with empty data */
    private function memoryLog(): array
    {
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $store = new PdoAuditStore($pdo);
        $store->initialize();
        $log = new AuditLog($store, $this->signer, $this->signer->publicKey(), new MockClock('2026-11-09 10:00:00 UTC'));
        $log->append('tenant-a', 'passkey.enrolled', 'key-1', ['userId' => 'alice']);
        $log->append('tenant-b', 'passkey.enrolled', 'key-2', ['userId' => 'bob']);
        $log->append('tenant-a', 'alert.taken_down', 'alert-1', []);

        return [$pdo, $log, $store];
    }

    /** @return array{\PDO, AuditLog, PdoAuditStore} a connection of its own to a database file */
    private function fileLog(string $file, string $journal, bool $initialize = false, int $busyTimeoutMs = 15000): array
    {
        $pdo = new \PDO('sqlite:'.$file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = '.$busyTimeoutMs);
        self::assertSame($journal, $pdo->query('PRAGMA journal_mode = '.$journal)->fetchColumn());
        $store = new PdoAuditStore($pdo);
        if ($initialize) {
            $store->initialize();
        }

        return [$pdo, new AuditLog($store, $this->signer, $this->signer->publicKey(), new MockClock('2026-11-09 10:00:00 UTC')), $store];
    }
}
