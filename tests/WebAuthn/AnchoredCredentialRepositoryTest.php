<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\WebAuthn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Audit\AuditStore;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\RevocableCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/** Only a passkey with a signed enrollment record can approve; the database alone cannot add or swap one. */
final class AnchoredCredentialRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PdoCredentialRepository $stored;
    private AuditLog $audit;
    private AnchoredCredentialRepository $anchored;
    private WebAuthnVerifier $verifier;
    private Actor $actor;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->stored = new PdoCredentialRepository($this->pdo);
        $this->stored->initialize();
        $signer = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $auditStore = new PdoAuditStore($this->pdo);
        $auditStore->initialize();
        $this->audit = new AuditLog($auditStore, $signer, TrustedKeys::single($signer->keyId(), $signer->publicKey()), new MockClock());
        $this->anchored = new AnchoredCredentialRepository($this->stored, $this->audit);
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

    /** @return iterable<string, array{string}> */
    public static function undoneRevocations(): iterable
    {
        yield 'its entry deleted' => ["DELETE FROM verified_human_approval_audit WHERE type = 'passkey.revoked'"];
        yield 'its signature broken' => ["UPDATE verified_human_approval_audit SET signature = (CASE substr(signature, 1, 1) WHEN 'A' THEN 'B' ELSE 'A' END) || substr(signature, 2) WHERE type = 'passkey.revoked'"];
        yield 'its subject column pointed elsewhere' => ["UPDATE verified_human_approval_audit SET subject = 'another-passkey' WHERE type = 'passkey.revoked'"];
        yield 'its type column changed' => ["UPDATE verified_human_approval_audit SET type = 'passkey.renamed' WHERE type = 'passkey.revoked'"];
    }

    #[DataProvider('undoneRevocations')]
    public function testARevokedPasskeyStaysRevokedWhenTheDatabaseUndoesItsRevocation(string $undo): void
    {
        $authenticator = new VirtualAuthenticator();
        $credential = $this->enroll($authenticator);
        self::assertTrue($this->anchored->revoke($this->actor, $credential->id, ['by' => 'editor', 'why' => 'laptop stolen']));
        // Another entry after it: cutting the newest entries off the log is a limit of its own, below.
        $this->audit->append('acme', 'connection.revoked', 'grant-1', ['by' => 'editor']);

        // Someone who can write the database, and holds no signing key, flips the row back and hides the revocation.
        $this->pdo->exec('UPDATE verified_human_approval_credentials SET revoked = 0');
        $this->pdo->exec($undo);

        self::assertFalse($this->approves($authenticator));
        self::assertNull($this->anchored->find($credential->id));
        self::assertFalse($this->audit->verify()['intact'], 'The audit page shows the damage too.');
    }

    public function testDamageAfterAnEnrollmentStopsThatPasskeyUntilItsOwnerEnrollsAnother(): void
    {
        $authenticator = new VirtualAuthenticator();
        $this->enroll($authenticator);
        $this->audit->append('acme', 'connection.revoked', 'grant-1', ['by' => 'editor']);
        $this->pdo->exec("UPDATE verified_human_approval_audit SET entry = REPLACE(entry, 'editor', 'mallory') WHERE type = 'connection.revoked'");

        // Any entry since the enrollment could have been its revocation, so the passkey stops, and says why.
        self::assertFalse($this->approves($authenticator));
        try {
            $this->verifier->requestOptions($this->actor, random_bytes(32));
            self::fail('The passkey was offered.');
        } catch (ApprovalException $error) {
            self::assertSame('credential_unvouched', $error->reason);
        }
        // Nothing damaged follows an enrollment made now.
        $replacement = new VirtualAuthenticator();
        $this->enroll($replacement);
        self::assertTrue($this->approves($replacement));
    }

    public function testOnlyACheckpointShowsThatTheNewestEntriesWereCutOff(): void
    {
        $authenticator = new VirtualAuthenticator();
        $credential = $this->enroll($authenticator);
        self::assertTrue($this->anchored->revoke($this->actor, $credential->id, ['by' => 'editor']));
        $checkpoint = $this->audit->head();
        self::assertNotNull($checkpoint);

        // The revocation is the newest entry: deleting it leaves a chain that verifies, so the log alone cannot tell.
        $this->pdo->exec('DELETE FROM verified_human_approval_audit WHERE seq = (SELECT MAX(seq) FROM verified_human_approval_audit)');
        $this->pdo->exec('UPDATE verified_human_approval_credentials SET revoked = 0');
        self::assertTrue($this->audit->verify()['intact']);
        self::assertTrue($this->approves($authenticator), 'This is the documented limit: the log cannot show its own newest entries cut off.');
        self::assertSame('The log now ends at entry 1, before the kept entry 2: entries were cut off.', $this->audit->checkpoint($checkpoint['seq'], $checkpoint['hash']));
    }

    public function testSignedEnrollmentCanWrapANonPdoRevocableRepository(): void
    {
        $credential = new Credential('portable-key', $this->actor->userId, $this->actor->tenantId,
            '{"publicKeyCredentialId":"aWQ","credentialPublicKey":"a2V5","userHandle":"dXNlcg"}', false, 'Portable passkey');
        $raw = $this->createMock(RevocableCredentialRepository::class);
        $raw->method('find')->with($credential->id)->willReturn($credential);
        $raw->expects(self::once())->method('revoke')->with($this->actor, $credential->id)->willReturn(true);

        $records = [];
        $store = $this->createMock(AuditStore::class);
        $store->expects(self::exactly(2))->method('append')->willReturnCallback(static function (callable $build) use (&$records): array {
            $last = [] === $records ? null : $records[array_key_last($records)];
            $record = $build(null === $last ? null : ['seq' => $last['seq'], 'hash' => $last['hash']]);
            $records[] = $record;

            return $record;
        });
        $store->method('recordsFor')->willReturnCallback(static function (string $type, string $subject) use (&$records): array {
            return array_values(array_filter(array_reverse($records), static fn (array $row): bool => $row['type'] === $type && $row['subject'] === $subject));
        });
        $store->method('records')->willReturnCallback(static function () use (&$records): array {
            return $records;
        });
        $signer = new SodiumEvidenceSigner('portable-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $anchored = new AnchoredCredentialRepository($raw, new AuditLog($store, $signer, TrustedKeys::single($signer->keyId(), $signer->publicKey()), new MockClock()));
        self::assertNull($anchored->find($credential->id), 'A raw row alone is not a trusted enrollment.');
        $anchored->anchor($credential, ['method' => 'trusted-host']);
        self::assertSame($credential, $anchored->find($credential->id));
        self::assertTrue($anchored->revoke($this->actor, $credential->id, ['by' => 'owner']));
        self::assertNull($anchored->find($credential->id), 'The signed revocation also prevents using a restored raw row.');
        self::assertSame($credential->sourceJson, $anchored->enrolled($credential->id)->sourceJson, 'Historical enrollment survives current revocation.');
    }
}
