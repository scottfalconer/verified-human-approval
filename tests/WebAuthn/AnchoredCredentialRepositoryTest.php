<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\WebAuthn;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Tests\Support\VirtualAuthenticator;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/** Only a passkey with a signed enrollment record can approve; the database alone cannot add or swap one. */
final class AnchoredCredentialRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PdoCredentialRepository $stored;
    private AnchoredCredentialRepository $anchored;
    private WebAuthnVerifier $verifier;
    private Actor $actor;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->stored = new PdoCredentialRepository($this->pdo);
        $this->stored->initialize();
        $signer = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $audit = new AuditLog($this->pdo, $signer, $signer->publicKey(), new MockClock());
        $audit->initialize();
        $this->anchored = new AnchoredCredentialRepository($this->stored, $audit);
        $this->verifier = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), $this->anchored);
        $this->actor = new Actor('editor', 'acme');
    }

    private function enroll(VirtualAuthenticator $authenticator, bool $anchor = true): Credential
    {
        $options = $this->verifier->registrationOptions($this->actor, random_bytes(32), 'Editor');
        $credential = $this->verifier->register($this->actor, $options, $authenticator->registration($options));
        if ($anchor) {
            $this->anchored->anchor($credential, ['method' => 'test']);
        }

        return $credential;
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

    public function testAnEnrolledPasskeyApprovesAndKeepsWorkingAsItsCounterMoves(): void
    {
        $authenticator = new VirtualAuthenticator();
        $this->enroll($authenticator);
        self::assertTrue($this->approves($authenticator));
        self::assertTrue($this->approves($authenticator), 'Counter updates do not break the anchor.');
    }

    public function testAPasskeyWithoutASignedEnrollmentRecordCannotApprove(): void
    {
        $authenticator = new VirtualAuthenticator();
        $this->enroll($authenticator, anchor: false);
        self::assertFalse($this->approves($authenticator), 'A credential row added straight to the database approves nothing.');
    }

    public function testSwappingAnEnrolledPasskeysPublicKeyInTheDatabaseIsRefused(): void
    {
        $genuine = new VirtualAuthenticator();
        $credential = $this->enroll($genuine);
        $attacker = new VirtualAuthenticator();
        // The attacker registers their own key elsewhere, then copies its public key over the person's row.
        $other = new PdoCredentialRepository(new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
        $other->initialize();
        $scratch = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), $other);
        $options = $scratch->registrationOptions($this->actor, random_bytes(32), 'Editor');
        $theirs = json_decode($scratch->register($this->actor, $options, $attacker->registration($options))->sourceJson, true);
        $row = json_decode($credential->sourceJson, true);
        $row['credentialPublicKey'] = $theirs['credentialPublicKey'];
        $this->pdo->prepare('UPDATE verified_human_approval_credentials SET source_json = ? WHERE credential_id = ?')->execute([json_encode($row), $credential->id]);

        self::assertNull($this->anchored->find($credential->id));
        self::assertFalse($this->approves($genuine));
        self::assertNotNull($this->anchored->enrolled($credential->id), 'The signed record still names the key that was enrolled.');
    }

    public function testARevokedPasskeyCannotApproveAndOnlyItsOwnerCanRevokeIt(): void
    {
        $authenticator = new VirtualAuthenticator();
        $credential = $this->enroll($authenticator);
        self::assertFalse($this->anchored->revoke(new Actor('mallory', 'acme'), $credential->id, ['by' => 'mallory']));
        self::assertTrue($this->approves($authenticator));
        self::assertTrue($this->anchored->revoke($this->actor, $credential->id, ['by' => 'editor']));
        self::assertFalse($this->approves($authenticator));
    }
}
