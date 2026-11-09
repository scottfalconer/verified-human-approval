<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
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
        $this->log = new AuditLog($store, $this->signer, $this->signer->publicKey(), new MockClock('2026-11-08 12:00:00'));
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

    public function testAppendJoinsTheCallersTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->log->append('tenant-a', 'alert.taken_down', 'alert-1', []);
        $this->pdo->rollBack();
        self::assertNull($this->log->find('alert.taken_down', 'alert-1'), 'A rolled-back action leaves no log entry.');
        self::assertSame(3, $this->log->verify()['newest']);
    }
}
