<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;
use VerifiedHumanApproval\Demo\Account\PasskeyRevocation;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;

/** A passkey revocation and its signed entry commit together: if the entry cannot be signed or stored, neither happens. */
final class PasskeyRevocationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function failures(): iterable
    {
        yield 'the entry cannot be signed' => ['signing'];
        yield 'the entry cannot be stored' => ['storage'];
    }

    #[DataProvider('failures')]
    public function testARevocationThatCannotBeRecordedLeavesThePasskeyActive(string $failure): void
    {
        $pdo = DemoDatabase::connect(':memory:');
        $database = new DemoDatabase($pdo);
        $credentials = new PdoCredentialRepository($pdo);
        $credentials->initialize();
        $store = new PdoAuditStore($pdo);
        $store->initialize();
        $signer = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $audit = new AuditLog($store, $signer, TrustedKeys::single($signer->keyId(), $signer->publicKey()), new MockClock());
        $anchored = new AnchoredCredentialRepository($credentials, $audit);
        $credential = new Credential('key-1', 'editor', 'acme', (string) json_encode(['publicKeyCredentialId' => 'a2V5LTE', 'credentialPublicKey' => 'pk', 'userHandle' => 'uh']), false, 'Passkey');
        $credentials->add($credential);
        $anchored->anchor($credential, ['method' => 'test']);

        if ('storage' === $failure) {
            $pdo->exec("CREATE TRIGGER reject_audit BEFORE INSERT ON verified_human_approval_audit BEGIN SELECT RAISE(ABORT, 'injected audit failure'); END");
        }
        $recording = 'signing' === $failure ? new AuditLog($store, self::outage(), TrustedKeys::single($signer->keyId(), $signer->publicKey()), new MockClock()) : $audit;
        $revocation = new PasskeyRevocation($database, $credentials, new AnchoredCredentialRepository($credentials, $recording));
        foreach (['byOwner' => new Actor('editor', 'acme'), 'byAdministrator' => new Actor('manager', 'acme')] as $route => $who) {
            try {
                $revocation->{$route}($who, 'key-1');
                self::fail($route.': a revocation went through without its signed entry.');
            } catch (\Throwable $error) {
                self::assertStringContainsString('signing' === $failure ? 'Injected signer outage' : 'injected audit failure', $error->getMessage());
            }
            self::assertFalse($credentials->find('key-1')?->revoked, $route.': the passkey stays active.');
            self::assertNotNull($anchored->find('key-1'), $route.': it still approves.');
            self::assertNull($audit->find(AnchoredCredentialRepository::REVOKED, 'key-1'));
            self::assertFalse($pdo->inTransaction(), $route.': nothing is left open.');
        }

        if ('storage' === $failure) {
            $pdo->exec('DROP TRIGGER reject_audit');
        }
        $working = new PasskeyRevocation($database, $credentials, $anchored);
        self::assertFalse($working->byAdministrator(new Actor('manager', 'other-organization'), 'key-1'), 'Only within the administrator’s organization.');
        self::assertFalse($credentials->find('key-1')?->revoked);
        self::assertTrue($working->byAdministrator(new Actor('manager', 'acme'), 'key-1'));
        self::assertTrue($credentials->find('key-1')?->revoked);
        self::assertSame('manager', $audit->find(AnchoredCredentialRepository::REVOKED, 'key-1')['data']->by);
    }

    private static function outage(): EvidenceSigner
    {
        return new class implements EvidenceSigner {
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
    }
}
