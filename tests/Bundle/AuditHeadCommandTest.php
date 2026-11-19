<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use VerifiedHumanApproval\Bundle\Command\AuditHeadCommand;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;

/**
 * What audit-head tells an operator around a key rotation: the entries that fail and why, a closed key whose
 * entries nothing vouches for, and a rotation that is due but not recorded.
 */
final class AuditHeadCommandTest extends TestCase
{
    private const OPENED = '2026-11-01T00:00:00Z';
    private const ROTATED = '2026-11-09T12:00:00Z';

    private \PDO $database;
    private PdoAuditStore $store;
    private MockClock $clock;
    private SodiumEvidenceSigner $old;
    private SodiumEvidenceSigner $new;

    protected function setUp(): void
    {
        $this->database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->store = new PdoAuditStore($this->database);
        $this->store->initialize();
        $this->clock = new MockClock('2026-11-05T09:00:00Z');
        $this->old = new SodiumEvidenceSigner('application-1', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->new = new SodiumEvidenceSigner('application-2', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->opened()->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
    }

    public function testItsJsonIsTheHeadAsStoredEvenWhereAValueLooksLikeFormatting(): void
    {
        // The stored key id is not signed: whoever can write the table can put anything in it.
        $this->database->exec("UPDATE verified_human_approval_audit SET key_id = '<fg=green>application-1</>' WHERE seq = 1");

        $head = $this->auditHead($this->opened(), ['--json' => true]);

        self::assertSame(Command::FAILURE, $head->getStatusCode());
        $json = json_decode(trim($head->getDisplay()), true, 4, \JSON_THROW_ON_ERROR);
        self::assertSame(['<fg=green>application-1</>', false], [$json['keyId'] ?? null, $json['intact'] ?? null]);
    }

    public function testItNamesTheFailingEntryAndTheRotationToRecordAfterAKeyChange(): void
    {
        $this->clock->modify(self::ROTATED);
        $this->clock->sleep(60);
        $log = $this->rotated();

        $head = $this->auditHead($log);
        self::assertSame(Command::FAILURE, $head->getStatusCode());
        $display = $head->getDisplay();
        self::assertStringContainsString('The chain does not verify, so this is no checkpoint to keep.', $display);
        self::assertStringContainsString('Entry 1: it is signed with key "application-1", whose window has closed, and no later entry signed with a key in use vouches for it.', $display);
        self::assertStringContainsString('Record the rotation away from it: verified-human-approval:record-key-rotation application-1', $display);
        self::assertStringNotContainsString('does not record the rotation', $display, 'One key is named once.');

        $check = $this->auditHead($log, ['--check' => '1:'.$log->verify()['entries'][0]['hash']]);
        self::assertSame(Command::FAILURE, $check->getStatusCode());
        self::assertStringContainsString('Entry 1 is unchanged, but the chain does not verify: some entry fails its signature, key window or chain check.', $check->getDisplay());
        self::assertStringContainsString('Entry 1: it is signed with key "application-1"', $check->getDisplay());
    }

    public function testItNamesARotationThatIsDueButNotRecordedOnceTheChainVerifiesAgain(): void
    {
        $this->clock->modify(self::ROTATED);
        $this->clock->sleep(60);
        $log = $this->rotated();
        // The new key signs an entry before anyone records the rotation: it vouches for the old key's entries.
        $log->append('tenant-a', 'passkey.enrolled', 'cred-2', ['userId' => 'alice']);

        $head = $this->auditHead($log);
        self::assertSame(Command::SUCCESS, $head->getStatusCode(), $head->getDisplay());
        self::assertStringContainsString('All 2 entries verify in an unbroken chain.', $head->getDisplay());
        self::assertStringContainsString('Key "application-1" has closed, and the log does not record the rotation away from it. Record it: verified-human-approval:record-key-rotation application-1', $head->getDisplay());

        $log->recordRotation('application-1');
        $after = $this->auditHead($log);
        self::assertSame(Command::SUCCESS, $after->getStatusCode());
        self::assertStringNotContainsString('application-1', $after->getDisplay());
    }

    /** The log as the application kept it before the rotation: the old key signs, and it is trusted from OPENED on. */
    private function opened(): AuditLog
    {
        return new AuditLog($this->store, $this->old, new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time(self::OPENED))]), $this->clock);
    }

    private function rotated(): AuditLog
    {
        return new AuditLog($this->store, $this->new, new TrustedKeys([
            new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED)),
            new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time(self::OPENED), TrustedKey::time(self::ROTATED)),
        ]), $this->clock);
    }

    /** @param array<string, string> $input */
    private function auditHead(AuditLog $log, array $input = []): CommandTester
    {
        $tester = new CommandTester(new AuditHeadCommand($log));
        $tester->execute($input);

        return $tester;
    }
}
