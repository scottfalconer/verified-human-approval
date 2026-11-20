<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\DbalAuditStore;
use VerifiedHumanApproval\Doctrine\DbalCredentialRepository;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\Tests\Support\TestDatabase;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/**
 * A revoked passkey on the Doctrine stores, after someone who can write the database, and holds no signing key,
 * flips its row back and edits or deletes its signed revocation: it stays revoked, whatever the edit, even one
 * the store's lookup alone would miss, such as its subject digest.
 */
abstract class PasskeyRevocationTestCase extends TestCase
{
    /** One of TestDatabase::KINDS. */
    protected const KIND = 'sqlite';

    private ?string $location = null;
    private Connection $connection;
    private DbalTransaction $transaction;
    private AuditLog $audit;
    private AnchoredCredentialRepository $anchored;
    private WebAuthnVerifier $verifier;
    private Actor $actor;

    protected function setUp(): void
    {
        $this->location = TestDatabase::create(static::KIND);
        $this->connection = TestDatabase::connect(static::KIND, $this->location);
        ApprovalSchema::install($this->connection);
        $this->transaction = new DbalTransaction($this->connection);
        $signer = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->audit = new AuditLog(new DbalAuditStore($this->transaction), $signer, TrustedKeys::single($signer->keyId(), $signer->publicKey()), new MockClock());
        $this->anchored = new AnchoredCredentialRepository(new DbalCredentialRepository($this->transaction), $this->audit);
        $this->verifier = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), $this->anchored);
        $this->actor = new Actor('editor', 'acme');
    }

    protected function tearDown(): void
    {
        if (null !== $this->location) {
            $this->connection->close();
            TestDatabase::drop(static::KIND, $this->location);
        }
    }

    /** @return iterable<string, array{\Closure(Connection): void}> */
    public static function undoneRevocations(): iterable
    {
        $revocation = static fn (Connection $connection, string $assignment, mixed $value): int => $connection->executeStatement(
            'UPDATE '.ApprovalSchema::AUDIT.' SET '.$assignment.' WHERE type = ?', [$value, AnchoredCredentialRepository::REVOKED],
        );
        yield 'its row deleted' => [static fn (Connection $connection): int => $connection->executeStatement('DELETE FROM '.ApprovalSchema::AUDIT.' WHERE type = ?', [AnchoredCredentialRepository::REVOKED])];
        yield 'its signature broken' => [static function (Connection $connection) use ($revocation): void {
            $signature = (string) $connection->fetchOne('SELECT signature FROM '.ApprovalSchema::AUDIT.' WHERE type = ?', [AnchoredCredentialRepository::REVOKED]);
            $revocation($connection, 'signature = ?', ('A' === $signature[0] ? 'B' : 'A').substr($signature, 1));
        }];
        yield 'its subject pointed elsewhere' => [static fn (Connection $connection): int => $revocation($connection, 'subject = ?', 'another-passkey')];
        yield 'its type changed' => [static fn (Connection $connection): int => $revocation($connection, 'type = ?', 'passkey.renamed')];
        // The lookup selects by this digest, which no signature covers.
        yield 'its subject digest changed' => [static fn (Connection $connection): int => $revocation($connection, 'subject_key = ?', hash('sha256', 'another-passkey'))];
        if ('sqlite' === TestDatabase::platform(static::KIND)) {
            // The same bytes in another storage class, which the lookup misses.
            yield 'its subject stored as a blob' => [static fn (Connection $connection): int => $connection->executeStatement('UPDATE '.ApprovalSchema::AUDIT." SET subject = CAST(subject AS BLOB) WHERE type = 'passkey.revoked'")];
            yield 'its type stored as a blob' => [static fn (Connection $connection): int => $connection->executeStatement('UPDATE '.ApprovalSchema::AUDIT." SET type = CAST(type AS BLOB) WHERE type = 'passkey.revoked'")];
        }
    }

    /** @param \Closure(Connection): void $undo */
    #[DataProvider('undoneRevocations')]
    public function testARevokedPasskeyStaysRevokedWhenTheDatabaseUndoesItsRevocation(\Closure $undo): void
    {
        $authenticator = new VirtualAuthenticator();
        $options = $this->verifier->registrationOptions($this->actor, random_bytes(32), 'Editor');
        $credential = $this->transaction->run(function () use ($options, $authenticator) {
            $credential = $this->verifier->register($this->actor, $options, $authenticator->registration($options));
            $this->anchored->anchor($credential, ['method' => 'test']);

            return $credential;
        });
        self::assertTrue($this->approves($authenticator));
        self::assertTrue($this->transaction->run(fn (): bool => $this->anchored->revoke($this->actor, $credential->id, ['by' => 'editor'])));
        // Another entry after it: cutting the newest entries off the log is a limit of its own.
        $this->transaction->run(fn (): array => $this->audit->append('acme', 'connection.revoked', 'grant-1', ['by' => 'editor']));

        $this->connection->executeStatement('UPDATE '.ApprovalSchema::CREDENTIALS.' SET revoked = 0');
        $undo($this->connection);

        self::assertFalse($this->approves($authenticator));
        self::assertNull($this->anchored->find($credential->id));
    }

    private function approves(VirtualAuthenticator $authenticator): bool
    {
        $challenge = random_bytes(32);
        try {
            $assertion = $authenticator->assertion($this->verifier->requestOptions($this->actor, $challenge));
            $this->transaction->run(fn () => $this->verifier->verify($this->actor, $challenge, $assertion));

            return true;
        } catch (ApprovalException) {
            return false;
        }
    }
}
