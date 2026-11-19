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
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/**
 * Passkey enrollment records across a change of the application's signing key: passkeys enrolled under the
 * old key keep approving, and after the rotation the retired key can neither anchor a passkey nor undo a
 * revocation, and a record whose time falls outside its key's window anchors nothing.
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

    public function testAnEntryTheRetiredKeySignsAfterTheRotationStopsThePasskeysEnrolledBeforeIt(): void
    {
        $laptop = new VirtualAuthenticator();
        $this->enroll($this->repository($this->old, new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'))])), $laptop);
        $after = $this->rotate();

        // A worker that still signs with the old key. The log refuses its entry, and from the chain alone it could as
        // well be what is left of a revocation rewritten with that key, so passkeys enrolled before it stop.
        (new AuditLog($this->auditStore, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-08T10:00:00Z')))->append('acme', 'connection.revoked', 'grant-1', ['by' => 'worker']);

        self::assertFalse((new AuditLog($this->auditStore, $this->new, $this->rotatedKeys(), $this->clock))->verify()['intact'], 'The audit page shows it.');
        self::assertFalse($this->approves($after, $laptop));
        $phone = new VirtualAuthenticator();
        $this->enroll($after, $phone);
        self::assertTrue($this->approves($after, $phone), 'A passkey enrolled after it approves.');
    }

    /** @return iterable<string, array{?string, ?string}> the window an operator gives the retired key again: its start and its end */
    public static function windowsGivenAgain(): iterable
    {
        yield 'an end that covers the entry' => ['2026-11-01T00:00:00Z', '2026-11-09T14:00:00Z'];
        yield 'no end' => ['2026-11-01T00:00:00Z', null];
        yield 'no window at all' => [null, null];
    }

    #[DataProvider('windowsGivenAgain')]
    public function testAnEntryTheRetiredKeySignsAfterTheRotationStaysRefusedWhateverWindowThatKeyIsGiven(?string $from, ?string $until): void
    {
        $laptop = new VirtualAuthenticator();
        $this->enroll($this->repository($this->old, new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'))])), $laptop);
        $this->rotate();
        (new AuditLog($this->auditStore, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-09T12:02:00Z')))->append('acme', 'connection.revoked', 'grant-1', ['by' => 'worker']);
        $this->clock->sleep(600);

        $keys = new TrustedKeys([
            new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED)),
            new TrustedKey('application-1', $this->old->publicKey(), null === $from ? null : TrustedKey::time($from), null === $until ? null : TrustedKey::time($until)),
        ]);
        self::assertFalse($this->approves($this->repository($this->new, $keys), $laptop));
        self::assertSame('it is signed with key "application-1", which entry 2 rotated away from', (new AuditLog($this->auditStore, $this->new, $keys, $this->clock))->verify()['entries'][2]['problem']);
    }

    public function testAStaleEntryRecordedBeforeTheRotationStopsPasskeysOnlyUntilTheOldKeysWindowCoversIt(): void
    {
        $laptop = new VirtualAuthenticator();
        $this->enroll($this->repository($this->old, new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'))])), $laptop);
        // The switch: the old key's window closes and the new key signs, before the rotation is recorded.
        $this->clock->modify(self::ROTATED);
        $this->clock->sleep(60);
        $phone = new VirtualAuthenticator();
        $this->enroll($this->repository($this->new, $this->rotatedKeys()), $phone);
        // A worker still on the old configuration signs an entry a minute later, after the old key's window closed.
        (new AuditLog($this->auditStore, $this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-09T12:02:00Z')))->append('acme', 'connection.revoked', 'grant-1', ['by' => 'worker']);
        $this->clock->sleep(120);
        (new AuditLog($this->auditStore, $this->new, $this->rotatedKeys(), $this->clock))->recordRotation('application-1');
        $this->clock->sleep(600);

        $after = $this->repository($this->new, $this->rotatedKeys());
        self::assertFalse($this->approves($after, $laptop));
        self::assertFalse($this->approves($after, $phone));

        // The operator moves the old key's end to cover the worker's entry, which comes before the rotation entry.
        $covering = new TrustedKeys([
            new TrustedKey('application-2', $this->new->publicKey(), TrustedKey::time(self::ROTATED)),
            new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'), TrustedKey::time('2026-11-09T12:02:00Z')),
        ]);
        $covered = $this->repository($this->new, $covering);
        self::assertTrue($this->approves($covered, $laptop), 'Neither passkey was enrolled again.');
        self::assertTrue($this->approves($covered, $phone));
        self::assertTrue((new AuditLog($this->auditStore, $this->new, $covering, $this->clock))->verify()['intact']);
    }

    public function testAnEntrySignedWithAKeyAHostDoesNotListYetStopsPasskeysOnlyUntilItIsListed(): void
    {
        $opened = new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'))]);
        $laptop = new VirtualAuthenticator();
        $this->enroll($this->repository($this->old, $opened), $laptop);
        // Another host already signs with the new key, which this host's configuration does not list yet.
        $this->clock->modify(self::ROTATED);
        $this->clock->sleep(60);
        (new AuditLog($this->auditStore, $this->new, $this->rotatedKeys(), $this->clock))->append('acme', 'connection.revoked', 'grant-1', ['by' => 'editor']);

        self::assertFalse($this->approves($this->repository($this->old, $opened), $laptop), 'An entry this host cannot check could be the revocation.');
        self::assertTrue($this->approves($this->repository($this->new, $this->rotatedKeys()), $laptop), 'Once the host lists the new key, the passkey approves without being enrolled again.');
    }

    /** @return iterable<string, array{string}> the time each rewritten entry claims: the one it had, or one inside the old key's window */
    public static function rewrittenRevocations(): iterable
    {
        yield 'keeping their times' => [''];
        yield 'backdated into the old key\'s window' => ['2026-11-08T10:00:00.000000Z'];
    }

    #[DataProvider('rewrittenRevocations')]
    public function testTheRetiredKeyCannotUndoARevocationRecordedAfterTheRotation(string $at): void
    {
        $after = $this->rotate();
        $audit = new AuditLog($this->auditStore, $this->new, $this->rotatedKeys(), $this->clock);
        $phone = new VirtualAuthenticator();
        $credential = $this->enroll($after, $phone);
        self::assertTrue($after->revoke($this->actor, $credential->id, ['by' => 'editor', 'why' => 'phone stolen']));
        $audit->append('acme', 'connection.revoked', 'grant-1', ['by' => 'editor']);

        // Someone with the old private key and the database rewrites the revocation, and every entry after it, as
        // connection revocations signed with that key, mending each link, then flips the passkey's row back.
        $previous = null;
        foreach ($this->pdo->query("SELECT seq, entry FROM verified_human_approval_audit WHERE seq >= (SELECT seq FROM verified_human_approval_audit WHERE type = 'passkey.revoked') ORDER BY seq")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $entry = CanonicalJson::map(CanonicalJson::decode((string) $row['entry']));
            $entry = ['type' => 'connection.revoked', 'subject' => 'grant-'.$row['seq']] + ('' === $at ? [] : ['at' => $at]) + (null === $previous ? [] : ['previous' => $previous]) + $entry;
            $text = CanonicalJson::encode($entry);
            $this->pdo->prepare("UPDATE verified_human_approval_audit SET type = ?, subject = ?, entry = ?, hash = ?, key_id = 'application-1', signature = ? WHERE seq = ?")
                ->execute([$entry['type'], $entry['subject'], $text, hash('sha256', $text), $this->old->sign($text), $row['seq']]);
            $previous = hash('sha256', $text);
        }
        $this->pdo->exec('UPDATE verified_human_approval_credentials SET revoked = 0');
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM verified_human_approval_audit WHERE type = 'passkey.revoked' OR entry LIKE '%passkey.revoked%'")->fetchColumn(), 'No trace of the revocation is left.');

        self::assertFalse($this->approves($after, $phone));
        self::assertNotNull($after->enrollmentRecord($credential->id), 'Its enrollment, signed with the new key, still verifies.');
    }

    /** @return iterable<string, array{\Closure(\PDO, SodiumEvidenceSigner): void}> */
    public static function rotationEdits(): iterable
    {
        yield 'its signature broken' => [static function (\PDO $pdo): void {
            $pdo->exec("UPDATE verified_human_approval_audit SET signature = (CASE substr(signature, 1, 1) WHEN 'A' THEN 'B' ELSE 'A' END) || substr(signature, 2) WHERE type = 'signing_key.rotated'");
        }];
        // What the entry says, its hash and every link stay as they were.
        yield 'signed again with the retired key' => [static function (\PDO $pdo, SodiumEvidenceSigner $old): void {
            $entry = (string) $pdo->query("SELECT entry FROM verified_human_approval_audit WHERE type = 'signing_key.rotated'")->fetchColumn();
            $pdo->prepare("UPDATE verified_human_approval_audit SET key_id = 'application-1', signature = ? WHERE type = 'signing_key.rotated'")->execute([$old->sign($entry)]);
        }];
    }

    /** @param \Closure(\PDO, SodiumEvidenceSigner): void $edit */
    #[DataProvider('rotationEdits')]
    public function testTheRetiredKeyCannotAnchorAPasskeyByEditingTheRotationEntry(\Closure $edit): void
    {
        $laptop = new VirtualAuthenticator();
        $this->enroll($this->repository($this->old, new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'))])), $laptop);
        $after = $this->rotate();

        // Someone with the old private key and the database edits the rotation entry so that it no longer moves trust,
        // adds their own passkey with an enrollment signed with the old key and backdated, then waits for the
        // application's next entry, signed with the new key, to follow it.
        $edit($this->pdo, $this->old);
        $intruder = new VirtualAuthenticator();
        $credential = $this->enroll($this->repository($this->old, TrustedKeys::single('application-1', $this->old->publicKey()), new MockClock('2026-11-08T10:00:00Z')), $intruder);
        $this->clock->sleep(3600);
        (new AuditLog($this->auditStore, $this->new, $this->rotatedKeys(), $this->clock))->append('acme', 'connection.revoked', 'grant-1', ['by' => 'mallory']);

        self::assertNull($after->enrollmentRecord($credential->id));
        self::assertFalse($this->approves($after, $intruder));
        self::assertFalse($this->approves($after, $laptop), 'Until an operator looks, what the old key signed counts for nothing.');
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
            self::assertSame('credential_unvouched', $error->reason, 'The person is told their passkey cannot approve now, not asked to enroll a first one.');
        }
        self::assertCount(1, $after->unvouched($this->actor));
        $audit->recordRotation('application-1');
        self::assertSame([], $after->unvouched($this->actor));
        self::assertTrue($this->approves($after, $laptop));
    }

    public function testEachApprovalStepReadsTheChainOnceHoweverManyPasskeysItWeighs(): void
    {
        $before = $this->repository($this->old, new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'))]));
        $passkeys = [new VirtualAuthenticator(), new VirtualAuthenticator(), new VirtualAuthenticator()];
        foreach ($passkeys as $passkey) {
            $this->enroll($before, $passkey);
        }
        $this->rotate();

        // Each passkey was enrolled under the retired key, so whether it stands is read from the whole chain.
        $store = $this->counted();
        $verifier = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), new AnchoredCredentialRepository($this->stored, new AuditLog($store, $this->new, $this->rotatedKeys(), $this->clock)));
        $challenge = random_bytes(32);
        $options = $verifier->requestOptions($this->actor, $challenge);
        self::assertCount(3, $options['allowCredentials'] ?? []);
        self::assertSame(1, $store->readings, 'Opening the review weighs all three passkeys from one reading of the chain.');
        $verifier->verify($this->actor, $challenge, $passkeys[1]->assertion($options));
        self::assertSame(2, $store->readings, 'Approving reads it once more.');
    }

    public function testOpeningAReviewThatNoPasskeyCanApproveReadsTheChainOnce(): void
    {
        $before = $this->repository($this->old, new TrustedKeys([new TrustedKey('application-1', $this->old->publicKey(), TrustedKey::time('2026-11-01T00:00:00Z'))]));
        foreach ([new VirtualAuthenticator(), new VirtualAuthenticator()] as $passkey) {
            $this->enroll($before, $passkey);
        }
        // The old key's window has closed and the new key has signed nothing yet, so nothing vouches for either passkey.
        $this->clock->modify(self::ROTATED);
        $this->clock->sleep(60);

        $store = $this->counted();
        $verifier = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), new AnchoredCredentialRepository($this->stored, new AuditLog($store, $this->new, $this->rotatedKeys(), $this->clock)));
        try {
            $verifier->requestOptions($this->actor, random_bytes(32));
            self::fail('A passkey was offered.');
        } catch (ApprovalException $error) {
            self::assertSame('credential_unvouched', $error->reason);
        }
        self::assertSame(1, $store->readings, 'Which refusal to give comes from the same reading of the chain.');
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

    /** The fixture's audit store, counting how often the whole chain is read. */
    private function counted(): AuditStore
    {
        return new class($this->auditStore) implements AuditStore {
            public int $readings = 0;

            public function __construct(private AuditStore $inner)
            {
            }

            public function append(callable $buildRecord): array
            {
                return $this->inner->append($buildRecord);
            }

            public function recordsFor(string $type, string $subject): iterable
            {
                return $this->inner->recordsFor($type, $subject);
            }

            public function records(): iterable
            {
                ++$this->readings;

                return $this->inner->records();
            }
        };
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
