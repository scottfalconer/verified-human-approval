<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Ceremony;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\DbalApprovalStore;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Doctrine\Orm\ApprovalSchemaListener;
use VerifiedHumanApproval\Doctrine\Orm\ProtectedEntityListener;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAuthorizer;
use VerifiedHumanApproval\StoreContracts\Fixture\FixturePolicies;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureSchema;
use VerifiedHumanApproval\StoreContracts\Fixture\TestProofVerifier;
use VerifiedHumanApproval\Tests\Doctrine\Fixture\Document;
use VerifiedHumanApproval\Tests\Doctrine\Fixture\Memo;
use VerifiedHumanApproval\Tests\Doctrine\Fixture\Notice;
use VerifiedHumanApproval\Tests\Doctrine\Fixture\PublishDocument;
use VerifiedHumanApproval\Tests\Support\DbalFixtureDatabase;
use VerifiedHumanApproval\Tests\Support\TestDatabase;

/**
 * An application on Doctrine ORM: entities, the approval stores and the fixture application's permissions share one
 * DBAL connection and the approval transaction, with the schema listener and the protected-entity listener on the
 * entity manager.
 */
final class OrmFixture
{
    public readonly Connection $connection;
    public readonly DbalTransaction $transaction;
    public readonly TestManagerRegistry $doctrine;
    public readonly ProtectedEntityListener $protection;
    public readonly PublishDocument $action;
    public readonly ApprovalService $service;
    public readonly SodiumEvidenceSigner $signer;
    public readonly Actor $actor;
    public readonly Actor $reviewer;

    /** @param array<class-string, list<string>|null> $protected */
    public function __construct(public readonly string $kind, public readonly string $location, array $protected = [Memo::class => ['signedOff']])
    {
        $this->connection = TestDatabase::connect($kind, $location);
        $this->transaction = new DbalTransaction($this->connection);
        $guard = new ExecutionGuard();
        $this->protection = new ProtectedEntityListener($guard, $protected);
        $this->doctrine = new TestManagerRegistry($this->connection, $this->entityManager(...));
        $database = new DbalFixtureDatabase($this->transaction);
        $this->action = new PublishDocument($this->doctrine, $guard, $this->transaction, $this->protection);
        $this->signer = new SodiumEvidenceSigner('orm-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->service = new ApprovalService(
            new ActionRegistry([$this->action]),
            new FixturePolicies($database),
            new FixtureAuthorizer($database),
            new DbalApprovalStore($this->transaction),
            new TestProofVerifier($database),
            new MockClock('2026-11-08T10:00:00+00:00'),
            $guard,
            $this->signer,
        );
        $this->actor = new Actor('alice', 'tenant-a', 'agent-1');
        $this->reviewer = new Actor('alice', 'tenant-a');
    }

    /** A new database with the approval tables, the fixture application and the entities' tables, and two documents. */
    public static function prepare(string $kind): string
    {
        $location = TestDatabase::create($kind);
        $fixture = new self($kind, $location);
        // The ORM's schema includes the approval tables, through the schema listener; installing then finds them.
        $manager = $fixture->manager();
        (new SchemaTool($manager))->createSchema(array_map($manager->getClassMetadata(...), [Document::class, Notice::class, Memo::class]));
        ApprovalSchema::install($fixture->connection);
        foreach (FixtureSchema::statements(TestDatabase::platform($kind)) as $statement) {
            $fixture->connection->executeStatement($statement);
        }
        $fixture->connection->insert('test_policies', ['action_id' => 'document.publish', 'version' => 1, 'statement' => 'I approve publishing {{ title }}, revision {{ revision }}.', 'required' => 1]);
        $fixture->connection->insert('orm_documents', ['id' => 1, 'tenant' => 'tenant-a', 'title' => 'Spring notice', 'body' => 'Approved words.', 'revision' => 3]);
        $fixture->connection->insert('orm_documents', ['id' => 2, 'tenant' => 'tenant-a', 'title' => 'Other notice', 'body' => 'Other words.', 'revision' => 1]);
        $fixture->connection->insert('orm_notices', ['id' => 1, 'text' => 'Opening hours']);
        $fixture->connection->insert('orm_memos', ['id' => 1, 'text' => 'A memo']);
        $fixture->connection->close();

        return $location;
    }

    public function manager(): EntityManagerInterface
    {
        $manager = $this->doctrine->getManager();
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    /** @return array{0: \VerifiedHumanApproval\Core\ApprovalState, 1: Ceremony} */
    public function pending(int $document = 1): array
    {
        $request = $this->service->request($this->actor, 'document.publish', ['id' => $document]);

        return [$request, $this->service->begin($this->reviewer, $request->request->id)];
    }

    /** @return array<string, mixed> */
    public function proof(Ceremony $ceremony): array
    {
        return ['test_only' => true, 'challenge' => $ceremony->challenge, 'user' => 'alice', 'tenant' => 'tenant-a', 'credential' => 'alice-key'];
    }

    /** @return array<string, mixed> the document's row, read on a connection of its own */
    public function stored(int $id = 1): array
    {
        $fresh = TestDatabase::connect($this->kind, $this->location);
        $row = $fresh->fetchAssociative('SELECT * FROM orm_documents WHERE id = ?', [$id]);
        $fresh->close();
        \assert(\is_array($row));

        return $row;
    }

    private function entityManager(): EntityManagerInterface
    {
        $config = method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig([__DIR__.'/Fixture'], true)
            : ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/Fixture'], true);
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir().'/verified-human-approval-orm-proxies');
            $config->setProxyNamespace('VerifiedHumanApprovalOrmProxies');
            $config->setAutoGenerateProxyClasses(true);
        }
        $manager = new EntityManager($this->connection, $config);
        $events = $manager->getEventManager();
        $events->addEventListener([Events::onFlush, Events::preUpdate], $this->protection);
        $events->addEventListener('postGenerateSchema', new ApprovalSchemaListener($this->connection));

        return $manager;
    }
}
