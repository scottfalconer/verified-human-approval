<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\WebAuthn;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\Tests\Support\InMemoryAuditStore;
use VerifiedHumanApproval\Tests\Support\InMemoryCredentialRepository;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/**
 * Signed enrollment, approval and revocation over a host's own storage, with no PDO anywhere. What every raw
 * passkey store must do, the in-memory one included, is the passkey storage contract in tests/Contract.
 */
final class PortableCredentialStorageTest extends TestCase
{
    private InMemoryCredentialRepository $stored;
    private InMemoryAuditStore $auditRows;
    private AnchoredCredentialRepository $anchored;
    private WebAuthnVerifier $verifier;
    private Actor $actor;

    protected function setUp(): void
    {
        $this->stored = new InMemoryCredentialRepository();
        $this->auditRows = new InMemoryAuditStore();
        $signer = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->anchored = new AnchoredCredentialRepository($this->stored, new AuditLog($this->auditRows, $signer, $signer->publicKey(), new MockClock()));
        $this->verifier = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), $this->anchored);
        $this->actor = new Actor('editor', 'acme');
    }

    public function testEnrollmentApprovalAndRevocationWorkOverAHostsOwnStorage(): void
    {
        $authenticator = new VirtualAuthenticator();
        $options = $this->verifier->registrationOptions($this->actor, random_bytes(32), 'Editor');
        $credential = $this->verifier->register($this->actor, $options, $authenticator->registration($options));
        self::assertFalse($this->approves($authenticator), 'Registration alone records no enrollment, so it approves nothing.');
        $this->anchored->anchor($credential, ['method' => 'test']);
        self::assertTrue($this->approves($authenticator));
        self::assertTrue($this->approves($authenticator), 'Each approval writes the source back through the host’s compare-and-replace.');

        self::assertFalse($this->anchored->revoke(new Actor('editor', 'other-tenant'), $credential->id, ['by' => 'x']), 'Ownership is user and tenant together.');
        self::assertFalse($this->anchored->revoke(new Actor('mallory', 'acme'), $credential->id, ['by' => 'mallory']));
        self::assertCount(1, $this->auditRows->rows, 'A refused revocation records nothing.');
        self::assertTrue($this->anchored->revoke($this->actor, $credential->id, ['by' => 'editor']));
        self::assertFalse($this->approves($authenticator));
        self::assertFalse($this->anchored->revoke($this->actor, $credential->id, ['by' => 'editor']), 'Revoking again changes nothing.');
        self::assertCount(2, $this->auditRows->rows, 'One enrollment and one revocation: no second revocation entry.');
        self::assertSame($credential->sourceJson, $this->anchored->enrolled($credential->id)?->sourceJson, 'The signed enrollment outlives the revocation, for checking past approvals.');
    }

    private function approves(VirtualAuthenticator $authenticator): bool
    {
        $challenge = random_bytes(32);
        try {
            $options = $this->verifier->requestOptions($this->actor, $challenge);
            $this->verifier->verify($this->actor, $challenge, $authenticator->assertion($options));

            return true;
        } catch (ApprovalException) {
            return false;
        }
    }
}
