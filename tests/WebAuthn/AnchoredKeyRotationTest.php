<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\WebAuthn;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;
use VerifiedHumanApproval\Tests\Support\VirtualAuthenticator;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/**
 * Passkey enrollment records across a change of the application's signing key: passkeys enrolled under the
 * old key keep approving, and the retired key cannot anchor a passkey after the rotation, nor can a record
 * whose time falls outside its key's window.
 */
final class AnchoredKeyRotationTest extends TestCase
{
    private const ROTATED = '2026-11-09T12:00:00Z';

    private \PDO $pdo;
    private PdoCredentialRepository $stored;
    private PdoAuditStore $auditStore;
    private MockClock $clock;
    private SodiumEvidenceSigner $old;
    private SodiumEvidenceSigner $new;
    private Actor $actor;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->stored = new PdoCredentialRepository($this->pdo);
        $this->stored->initialize();
        $this->auditStore = new PdoAuditStore($this->pdo);
        $this->auditStore->initialize();
        $this->clock = new MockClock('2026-11-05T09:00:00Z');
        $this->old = new SodiumEvidenceSigner('application-1', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->new = new SodiumEvidenceSigner('application-2', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->actor = new Actor('editor', 'acme');
    }

    public function testPasskeysEnrolledBeforeARotationKeepApprovingAndRevocationStillWorks(): void
    {
        $before = $this->repository($this->old, new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'))]));
        $laptop = new VirtualAuthenticator();
        $laptopCredential = $this->enroll($before, $laptop);

        $after = $this->rotate();
        self::assertTrue($this->approves($after, $laptop), 'Its enrollment, signed with the retired key, still vouches.');
        self::assertSame($laptopCredential->sourceJson, $after->enrolled($laptopCredential->id)?->sourceJson);
        $phone = new VirtualAuthenticator();
        $this->enroll($after, $phone);
        self::assertTrue($this->approves($after, $phone));

        // A revocation signed with the new key ends the passkey enrolled under the old one.
        self::assertTrue($after->revoke($this->actor, $laptopCredential->id, ['by' => 'editor']));
        self::assertFalse($this->approves($after, $laptop));
        self::assertTrue($this->approves($after, $phone));
    }

    public function testTheRetiredKeyCannotAnchorAPasskeyAfterTheRotation(): void
    {
        $after = $this->rotate();
        // Someone with the old private key and the database adds their own passkey and signs its enrollment,
        // backdated into the old key's window.
        $intruder = new VirtualAuthenticator();
        $credential = $this->enroll($this->repository($this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-08T10:00:00Z')), $intruder);

        self::assertNull($after->enrollmentRecord($credential->id));
        self::assertFalse($after->anchored($credential));
        self::assertFalse($this->approves($after, $intruder));
    }

    public function testAPasskeyEnrolledBeforeTheSwitchApprovesOnceTheNewKeyHasSigned(): void
    {
        $before = $this->repository($this->old, new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'))]));
        $laptop = new VirtualAuthenticator();
        $this->enroll($before, $laptop);

        $this->clock->modify(self::ROTATED);
        $this->clock->sleep(60);
        $audit = new AuditLog($this->auditStore, $this->new, $this->rotatedKeys(), $this->clock);
        $after = new AnchoredCredentialRepository($this->stored, $audit);
        self::assertFalse($this->approves($after, $laptop), 'Until the new key signs, nothing in use vouches for the enrollment.');
        try {
            (new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), $after))->requestOptions($this->actor, random_bytes(32));
            self::fail('The passkey was offered.');
        } catch (ApprovalException $error) {
            self::assertSame('credential_unvouched', $error->reason, 'Enrolling another passkey would not help, and the person is told so.');
        }
        self::assertCount(1, $after->unvouched($this->actor));
        $audit->recordRotation('application-1');
        self::assertSame([], $after->unvouched($this->actor));
        self::assertTrue($this->approves($after, $laptop));
    }

    public function testAnEnrollmentRecordClaimingATimeOutsideItsKeysWindowAnchorsNothing(): void
    {
        $after = $this->repository($this->new, $this->rotatedKeys());
        // Signed with the retired key a second after its window closed; no rotation entry is needed to refuse it.
        $late = new VirtualAuthenticator();
        $credential = $this->enroll($this->repository($this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-09T12:00:01Z')), $late);

        self::assertNull($after->enrolled($credential->id));
        self::assertFalse($this->approves($after, $late));
    }

    private function rotate(): AnchoredCredentialRepository
    {
        $this->clock->modify(self::ROTATED);
        $this->clock->sleep(60);
        $audit = new AuditLog($this->auditStore, $this->new, $this->rotatedKeys(), $this->clock);
        $audit->recordRotation('application-1');

        return new AnchoredCredentialRepository($this->stored, $audit);
    }

    private function rotatedKeys(): TrustedKeys
    {
        return new TrustedKeys([
            new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED)),
            new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'), TrustedKey::time(self::ROTATED)),
        ]);
    }

    private function repository(SodiumEvidenceSigner $signer, TrustedKeys $keys, ?MockClock $clock = null): AnchoredCredentialRepository
    {
        return new AnchoredCredentialRepository($this->stored, new AuditLog($this->auditStore, $signer, $keys, $clock ?? $this->clock));
    }

    private function enroll(AnchoredCredentialRepository $repository, VirtualAuthenticator $authenticator): Credential
    {
        $verifier = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), $repository);
        $options = $verifier->registrationOptions($this->actor, random_bytes(32), 'Editor');
        $credential = $verifier->register($this->actor, $options, $authenticator->registration($options));
        $repository->anchor($credential, ['method' => 'test']);

        return $credential;
    }

    private function approves(AnchoredCredentialRepository $repository, VirtualAuthenticator $authenticator): bool
    {
        $verifier = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), $repository);
        $challenge = random_bytes(32);
        try {
            $options = $verifier->requestOptions($this->actor, $challenge);
            $verifier->verify($this->actor, $challenge, $authenticator->assertion($options));

            return true;
        } catch (ApprovalException) {
            return false;
        }
    }
}
