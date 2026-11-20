<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Audit\AuditStore;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;

/** The security log: signed entries, each naming the one before, so edits and deletions show. */
final class AuditLogTest extends TestCase
{
    private \PDO $pdo;
    private AuditLog $log;
    private SodiumEvidenceSigner $signer;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->signer = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $store = new PdoAuditStore($this->pdo);
        $store->initialize();
        $this->log = new AuditLog($store, $this->signer, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()), new MockClock('2026-11-08 12:00:00'));
        foreach (['one', 'two', 'three'] as $subject) {
            $this->log->append('tenant-a', 'passkey.enrolled', $subject, ['userId' => 'alice', 'label' => $subject]);
        }
    }

    public function testEntriesChainAndVerify(): void
    {
        $report = $this->log->verify();
        self::assertTrue($report['intact']);
        self::assertSame(3, $report['newest']);
        self::assertSame('two', $this->log->find('passkey.enrolled', 'two')['data']->label);
        self::assertNull($this->log->find('passkey.enrolled', 'missing'));
        self::assertSame($report['entries'][0]['entry']['seq'], 1);
        self::assertNull($report['entries'][0]['entry']['previous']);
    }

    public function testAnEditedEntryNoLongerVerifies(): void
    {
        $entry = $this->pdo->query('SELECT entry FROM verified_human_approval_audit WHERE seq = 2')->fetchColumn();
        $this->pdo->prepare('UPDATE verified_human_approval_audit SET entry = ? WHERE seq = 2')->execute([str_replace('"two"', '"mallory"', $entry)]);
        $report = $this->log->verify();
        self::assertFalse($report['intact']);
        self::assertSame('its signature or hash does not verify', $report['entries'][1]['problem']);
        self::assertNull($this->log->find('passkey.enrolled', 'two'), 'An edited enrollment record anchors nothing.');
    }

    public function testAnEntryReSignedWithAnotherKeyDoesNotVerify(): void
    {
        $forger = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $entry = $this->pdo->query('SELECT entry FROM verified_human_approval_audit WHERE seq = 3')->fetchColumn();
        $this->pdo->prepare('UPDATE verified_human_approval_audit SET signature = ? WHERE seq = 3')->execute([$forger->sign($entry)]);
        self::assertFalse($this->log->verify()['intact']);
        self::assertNull($this->log->find('passkey.enrolled', 'three'));
    }

    public function testADeletedEntryBreaksTheChain(): void
    {
        $this->pdo->exec('DELETE FROM verified_human_approval_audit WHERE seq = 2');
        $report = $this->log->verify();
        self::assertFalse($report['intact']);
        self::assertSame('an entry before it is missing', $report['entries'][1]['problem']);
        self::assertNotNull($this->log->find('passkey.enrolled', 'three'), 'Each surviving entry still verifies on its own.');
    }

    /**
     * A log reads its chain several times in one operation, and checks each signature once: a signature that
     * verified is remembered. It never stands in for the row as it is now. A row whose signature, entry or key has
     * changed since is checked afresh, by the same log, and verifies again only as it was.
     */
    public function testARememberedSignatureNeverStandsInForARowThatChanged(): void
    {
        $other = new SodiumEvidenceSigner('other-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $log = new AuditLog(new PdoAuditStore($this->pdo), $this->signer, new TrustedKeys([
            new TrustedKey($this->signer->keyId(), $this->signer->publicKey()), new TrustedKey($other->keyId(), $other->publicKey()),
        ]), new MockClock('2026-11-08 12:00:00'));
        self::assertTrue($log->verify()['intact']);
        $stored = $this->pdo->query('SELECT entry, hash, key_id, signature FROM verified_human_approval_audit WHERE seq = 2')->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        $edited = str_replace('"two"', '"mallory"', $stored['entry']);
        foreach ([
            'its signature, made with another key under its name' => ['signature' => (new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())))->sign($stored['entry'])],
            'its entry, with the hash to match' => ['entry' => $edited, 'hash' => hash('sha256', $edited)],
            'the key it names, another trusted key' => ['key_id' => $other->keyId()],
        ] as $change => $columns) {
            $this->rewrite(2, $columns);
            self::assertSame('its signature or hash does not verify', $log->verify()['entries'][1]['problem'], $change);
            self::assertNull($log->find('passkey.enrolled', 'two'), $change);
            $this->rewrite(2, $stored);
            self::assertTrue($log->verify()['intact'], 'As it was, after '.$change.', it verifies again.');
        }
        self::assertSame('two', $log->find('passkey.enrolled', 'two')['data']->label ?? null);
    }

    public function testAppendJoinsTheCallersTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->log->append('tenant-a', 'alert.taken_down', 'alert-1', []);
        $this->pdo->rollBack();
        self::assertNull($this->log->find('alert.taken_down', 'alert-1'), 'A rolled-back action leaves no log entry.');
        self::assertSame(3, $this->log->verify()['newest']);
    }

    public function testTheRealSignerAndVerifierWorkWithANonPdoStore(): void
    {
        $record = null;
        $store = $this->createMock(AuditStore::class);
        $store->expects(self::once())->method('append')->willReturnCallback(static function (callable $build) use (&$record): array {
            return $record = $build(null);
        });
        $store->method('records')->willReturnCallback(static function () use (&$record): array {
            return [$record];
        });
        $store->method('recordsFor')->willReturnCallback(static function () use (&$record): array {
            return [$record];
        });
        $log = new AuditLog($store, $this->signer, TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey()), new MockClock('2026-11-08 12:00:00'));
        $log->append('tenant-b', 'passkey.enrolled', 'portable', []);
        self::assertSame('{"at":"2026-11-08T12:00:00.000000Z","data":{},"format":"verified-human-approval/audit/v1","previous":null,"seq":1,"subject":"portable","tenantId":"tenant-b","type":"passkey.enrolled"}', $record['entry']);
        self::assertTrue($log->verify()['intact']);
        self::assertNotNull($log->find('passkey.enrolled', 'portable'));

        $record['entry'] = str_replace('portable', 'changed', $record['entry']);
        self::assertFalse($log->verify()['intact'], 'Raw damaged rows still reach the cryptographic verifier.');
        self::assertNull($log->find('passkey.enrolled', 'portable'));
    }

    /** @param array<string, string> $columns */
    private function rewrite(int $seq, array $columns): void
    {
        foreach ($columns as $column => $value) {
            $this->pdo->prepare('UPDATE verified_human_approval_audit SET '.$column.' = ? WHERE seq = ?')->execute([$value, $seq]);
        }
    }
}
