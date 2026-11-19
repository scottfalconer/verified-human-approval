<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\DbalApprovalStore;
use VerifiedHumanApproval\Doctrine\DbalAuditStore;
use VerifiedHumanApproval\Doctrine\DbalCredentialRepository;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAuthorizer;
use VerifiedHumanApproval\StoreContracts\Fixture\FixturePolicies;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureSchema;
use VerifiedHumanApproval\StoreContracts\Fixture\TestProofVerifier;
use VerifiedHumanApproval\Tests\Doctrine\Fixture\Document;
use VerifiedHumanApproval\Tests\Doctrine\Fixture\Memo;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Credential;

/**
 * persistence: { driver: doctrine } wires the approval transaction, the three stores, the signed audit log and the
 * anchored passkey repository on a DoctrineBundle connection; the install command creates the tables; the ORM's
 * schema includes them; and protected entities are guarded on that connection's flushes.
 */
final class DoctrinePersistenceTest extends TestCase
{
    private string $directory;
    private string $database;
    /** @var list<DoctrineKernel> */
    private array $kernels = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/verified-human-approval-doctrine-kernel-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->database = $this->directory.'/application.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        (new Filesystem())->remove($this->directory);
    }

    public function testTheBundleWiresTheDoctrineStorageOnTheNamedConnection(): void
    {
        $container = $this->boot(['protected_entities' => [Memo::class => ['signedOff']]])->getContainer()->get('test.service_container');
        $connection = $container->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $transaction = $container->get(DbalTransaction::class);
        self::assertInstanceOf(DbalTransaction::class, $transaction);
        self::assertSame($connection, $transaction->connection(), 'The stores share the application’s own connection.');
        self::assertInstanceOf(DbalApprovalStore::class, $container->get('verified_human_approval.doctrine.approval_store'));
        $probe = $container->get(WiringProbe::class);
        self::assertInstanceOf(WiringProbe::class, $probe);
        self::assertSame($transaction, $probe->transaction);
        self::assertInstanceOf(DbalApprovalStore::class, $probe->history);
        self::assertInstanceOf(DbalAuditStore::class, $probe->auditStore);
        self::assertInstanceOf(DbalCredentialRepository::class, $probe->rawCredentials);
        self::assertSame($probe->anchored, $probe->credentials, 'Verification reads passkeys through the anchored wrapper.');
        self::assertSame($connection, $container->get('doctrine.orm.entity_manager')->getConnection());
    }

    public function testTheInstallCommandTheServiceAndTheSignedLogWorkOnThatConnection(): void
    {
        $kernel = $this->boot();
        $tester = new CommandTester((new Application($kernel))->find('verified-human-approval:install'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Approval storage is ready.', $tester->getDisplay());
        $container = $kernel->getContainer()->get('test.service_container');
        $connection = $container->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        foreach (FixtureSchema::statements('sqlite') as $statement) {
            $connection->executeStatement($statement);
        }

        $service = $container->get(ApprovalService::class);
        \assert($service instanceof ApprovalService);
        $provider = new FixedActorProvider();
        $request = $service->request($provider->current(), 'article.publish', ['id' => 1]);
        $ceremony = $service->begin($provider->interactive(), $request->request->id);
        $completed = $service->complete($provider->interactive(), $ceremony->id, ['test_only' => true, 'challenge' => $ceremony->challenge, 'user' => 'alice', 'tenant' => 'tenant-a', 'credential' => 'alice-key']);
        self::assertSame('completed', $completed->status);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM '.ApprovalSchema::RECEIPTS));
        $signer = WiringProbe::signer();
        self::assertSame(Receipt::COMMIT, (new ReceiptVerifier())->verify((new EvidenceExporter())->export($completed), TrustedKeys::single($signer->keyId(), $signer->publicKey()))['receiptSignedAt']);

        // A passkey enrolled and anchored in one transaction is vouched for by the signed log.
        $transaction = $container->get(DbalTransaction::class);
        $anchored = $container->get(AnchoredCredentialRepository::class);
        \assert($transaction instanceof DbalTransaction && $anchored instanceof AnchoredCredentialRepository);
        $credential = new Credential('cred-1', 'alice', 'tenant-a', '{"publicKeyCredentialId":"Y3JlZC0x","credentialPublicKey":"a2V5","userHandle":"aGFuZGxl"}');
        $transaction->run(static function () use ($anchored, $credential): void {
            $anchored->add($credential);
            $anchored->anchor($credential, ['method' => 'test']);
        });
        self::assertNotNull($anchored->find('cred-1'));
        $log = $container->get(AuditLog::class);
        \assert($log instanceof AuditLog);
        self::assertTrue($log->verify()['intact']);
    }

    public function testTheOrmSchemaHasTheApprovalTablesAndProtectedEntitiesAreGuarded(): void
    {
        $kernel = $this->boot(['protected_entities' => [Memo::class => ['signedOff']]]);
        $manager = $kernel->getContainer()->get('test.service_container')->get('doctrine.orm.entity_manager');
        \assert($manager instanceof EntityManagerInterface);
        $tool = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $create = implode("\n", $tool->getCreateSchemaSql($metadata));
        self::assertStringContainsString('CREATE TABLE '.ApprovalSchema::REQUESTS, $create, 'doctrine:migrations:diff creates the approval tables with the entities’.');
        $tool->createSchema($metadata);
        ApprovalSchema::install($manager->getConnection());
        $update = implode("\n", $tool->getUpdateSchemaSql($metadata));
        self::assertStringNotContainsString('verified_human_approval', $update, 'Nothing proposed for the approval tables: no change, and no drop.');

        $manager->persist(new Document(1, 'tenant-a', 'Draft', 'Draft words.'));
        $manager->flush();
        $document = $manager->find(Document::class, 1);
        \assert($document instanceof Document);
        $document->publishedTitle = 'Published without approval';
        try {
            $manager->flush();
            self::fail('A protected field was flushed without an approval.');
        } catch (ApprovalException $error) {
            self::assertSame('approval_required', $error->reason);
        }
        $manager->clear();
        $memo = new Memo(1, 'A memo');
        $memo->signedOff = 'nobody';
        $manager->persist($memo);
        $this->expectException(ApprovalException::class);
        $manager->flush();
    }

    public function testStoreAndPersistenceAreExclusiveAndPersistenceNeedsASigner(): void
    {
        foreach ([
            'both' => [['store' => 'some.store'], 'not both'],
            'neither' => [['persistence' => null], 'Name the approval store'],
            'no signer' => [['receipt_signer' => null], 'needs a "receipt_signer"'],
        ] as $case => [$change, $message]) {
            try {
                $this->boot([], $change);
                self::fail('Accepted: '.$case);
            } catch (InvalidConfigurationException $error) {
                self::assertStringContainsString($message, $error->getMessage(), $case);
            }
        }
    }

    /**
     * @param array<string, mixed> $persistence
     * @param array<string, mixed> $change
     */
    private function boot(array $persistence = [], array $change = []): DoctrineKernel
    {
        $approval = [
            'public_origin' => 'http://localhost', 'actor_provider' => FixedActorProvider::class,
            'policy_provider' => FixturePolicies::class, 'authorizer' => FixtureAuthorizer::class,
            'verifier' => TestProofVerifier::class, 'receipt_signer' => SodiumEvidenceSigner::class,
            'persistence' => ['driver' => 'doctrine', 'connection' => 'default'] + $persistence,
        ];
        foreach ($change as $key => $value) {
            if (null === $value) {
                unset($approval[$key]);
            } else {
                $approval[$key] = $value;
            }
        }
        $kernel = new DoctrineKernel($this->directory.'/'.bin2hex(random_bytes(4)), $this->database, $approval);
        $this->kernels[] = $kernel;
        $kernel->boot();

        return $kernel;
    }
}
