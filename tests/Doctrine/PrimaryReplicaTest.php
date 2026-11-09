<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\DbalApprovalStore;
use VerifiedHumanApproval\Doctrine\DbalAuditStore;
use VerifiedHumanApproval\Doctrine\DbalCredentialRepository;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Tests\Support\CoreFixture;
use VerifiedHumanApproval\Tests\Support\TestDatabase;
use VerifiedHumanApproval\WebAuthn\Credential;

/**
 * With a primary/replica connection, every read of the stores reaches the primary, inside the approval transaction
 * or outside it, even after the application has switched the connection to a replica that lags behind.
 */
final class PrimaryReplicaTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            TestDatabase::drop('sqlite', $file);
        }
    }

    public function testEveryReadReachesThePrimary(): void
    {
        $primary = $this->database();
        $replica = $this->database(); // a replica that has not caught up: the same tables, none of the rows
        $connection = DriverManager::getConnection([
            'wrapperClass' => PrimaryReadReplicaConnection::class, 'driver' => 'pdo_sqlite', 'keepReplica' => true,
            'primary' => ['path' => $primary], 'replica' => [['path' => $replica]],
        ]);
        self::assertInstanceOf(PrimaryReadReplicaConnection::class, $connection);
        $transaction = new DbalTransaction($connection);
        $store = new DbalApprovalStore($transaction);
        $signer = new SodiumEvidenceSigner('key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $log = new AuditLog(new DbalAuditStore($transaction), $signer, $signer->publicKey(), new MockClock());
        $credentials = new DbalCredentialRepository($transaction);

        $request = (new CoreFixture())->service->request(new Actor('alice', 'tenant-a', 'agent-1'), 'article.publish', ['id' => 1]);
        $transaction->run(static fn () => $store->insert($request, 'key-1', 'fingerprint'));
        $log->append('tenant-a', 'passkey.enrolled', 'cred-1', ['userId' => 'alice']);
        $transaction->run(static fn () => $credentials->add(new Credential('cred-1', 'alice', 'tenant-a', '{"counter":0}')));

        $connection->ensureConnectedToReplica();
        self::assertSame($request->request->canonical, $store->get($request->request->id)?->request->canonical);
        $connection->ensureConnectedToReplica();
        self::assertSame(1, $log->find('passkey.enrolled', 'cred-1')['seq'] ?? null);
        $connection->ensureConnectedToReplica();
        self::assertNotNull($credentials->find('cred-1'));
        $connection->ensureConnectedToReplica();
        self::assertCount(1, $store->forResource('tenant-a', '1'));
        $connection->ensureConnectedToReplica();
        self::assertSame($request->request->id, $transaction->run(static fn () => $store->findPending($request->request->actor(), 'fingerprint')?->request->id), 'The transaction reads the primary too.');
    }

    private function database(): string
    {
        $file = TestDatabase::create('sqlite');
        $this->files[] = $file;
        ApprovalSchema::install(TestDatabase::connect('sqlite', $file));

        return $file;
    }
}
