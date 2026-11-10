<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\Orm\ApprovalSchemaListener;
use VerifiedHumanApproval\Tests\Support\TestDatabase;

/**
 * The approval tables on one database: installed once, outside any transaction, with their lock rows; exactly as
 * defined, so a migration diff finds nothing to change; canonical JSON in text columns; and in the ORM's schema
 * for the approval connection, so doctrine:migrations:diff and doctrine:schema:update create rather than drop them.
 */
abstract class ApprovalSchemaTestCase extends TestCase
{
    /** One of TestDatabase::KINDS. */
    protected const KIND = 'sqlite';

    private ?string $location = null;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->location = TestDatabase::create(static::KIND);
        $this->connection = TestDatabase::connect(static::KIND, $this->location);
    }

    protected function tearDown(): void
    {
        if (null !== $this->location) {
            TestDatabase::drop(static::KIND, $this->location);
        }
    }

    public function testInstallCreatesTheTablesAndLockRowsOnceAndRefusesInsideATransaction(): void
    {
        ApprovalSchema::install($this->connection);
        ApprovalSchema::install($this->connection);
        $tables = array_map(static fn (Table $table): string => $table->getObjectName()->toString(), ApprovalSchema::tables($this->connection->getDatabasePlatform()));
        self::assertCount(7, $tables);
        foreach ($tables as $table) {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE 1 = 0'), $table.' exists');
        }
        self::assertSame(257, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.ApprovalSchema::LOCKS));
        $this->connection->executeStatement('DELETE FROM '.ApprovalSchema::LOCKS." WHERE name = 'audit'");
        ApprovalSchema::seed($this->connection);
        self::assertSame(257, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.ApprovalSchema::LOCKS), 'A missing lock row is put back.');

        $this->connection->beginTransaction();
        try {
            ApprovalSchema::install($this->connection);
            self::fail('The schema was installed inside a transaction.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('outside any transaction', $error->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testATableWithAnotherLayoutIsRefused(): void
    {
        if ('sqlite' === TestDatabase::platform(static::KIND)) {
            $native = $this->connection->getNativeConnection();
            self::assertInstanceOf(\PDO::class, $native);
            (new PdoApprovalStore($native))->initialize();
        } else {
            $this->connection->executeStatement('CREATE TABLE '.ApprovalSchema::REQUESTS.' (id VARCHAR(64) NOT NULL PRIMARY KEY, proposal TEXT NOT NULL)');
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('exists with other columns');
        ApprovalSchema::install($this->connection);
    }

    public function testTheInstalledTablesAreExactlyAsDefinedSoAMigrationDiffIsEmpty(): void
    {
        ApprovalSchema::install($this->connection);
        $schemaManager = $this->connection->createSchemaManager();
        // As DBAL 5 compares: a changed index counts as one dropped and one added, so it still shows as a difference.
        $comparator = $schemaManager->createComparator((new ComparatorConfig())->withReportModifiedIndexes(false));
        foreach (ApprovalSchema::tables($this->connection->getDatabasePlatform()) as $defined) {
            $name = $defined->getObjectName()->toString();
            $installed = self::introspect($schemaManager, $name);
            self::assertTrue($comparator->compareTables($installed, $defined)->isEmpty(), $name.' differs from its definition.');
        }
    }

    public function testCanonicalJsonIsKeptInTextColumnsNeverJson(): void
    {
        ApprovalSchema::install($this->connection);
        $schemaManager = $this->connection->createSchemaManager();
        $json = ['proposal', 'result', 'proof', 'record', 'manifest', 'options', 'entry', 'source_json'];
        $seen = 0;
        foreach (ApprovalSchema::tables($this->connection->getDatabasePlatform()) as $defined) {
            foreach (self::introspect($schemaManager, $defined->getObjectName()->toString())->getColumns() as $column) {
                self::assertNotInstanceOf(JsonType::class, self::typeOf($column));
                if (\in_array($column->getObjectName()->getIdentifier()->getValue(), $json, true)) {
                    self::assertInstanceOf(TextType::class, self::typeOf($column));
                    ++$seen;
                }
            }
        }
        self::assertSame(8, $seen);
        if ('mysql' === TestDatabase::platform(static::KIND)) {
            // LONGTEXT holds a 256 KiB proposal, and the binary no-pad collation compares identifiers exactly.
            $columns = $this->connection->fetchAllAssociative("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'verified\\_human\\_approval\\_%' AND DATA_TYPE IN ('varchar', 'longtext', 'text')");
            self::assertNotEmpty($columns);
            foreach ($columns as $column) {
                self::assertSame('utf8mb4', $column['CHARACTER_SET_NAME'], $column['COLUMN_NAME']);
                self::assertContains($column['COLLATION_NAME'], ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin'], $column['COLUMN_NAME']);
                if (\in_array($column['COLUMN_NAME'], $json, true)) {
                    self::assertSame('longtext', $column['DATA_TYPE'], $column['COLUMN_NAME']);
                }
            }
        }
    }

    public function testTheOrmSchemaForTheApprovalConnectionHasTheTables(): void
    {
        $tool = new SchemaTool($this->entityManager($this->connection));
        $create = implode("\n", $tool->getCreateSchemaSql([]));
        foreach (ApprovalSchema::tables($this->connection->getDatabasePlatform()) as $table) {
            self::assertStringContainsString($table->getObjectName()->toString(), $create, 'doctrine:migrations:diff would create it.');
        }
        ApprovalSchema::install($this->connection);
        self::assertSame([], $tool->getUpdateSchemaSql([]), 'Once installed, nothing is proposed: no change, and no drop.');

        // An entity manager on another connection is left alone, even on the same database.
        $other = new SchemaTool($this->entityManager(TestDatabase::connect(static::KIND, (string) $this->location), $this->connection));
        self::assertSame([], $other->getCreateSchemaSql([]));

        // So is a table the application's schema filter hides, as DoctrineBundle's schema_filter does.
        $filtered = TestDatabase::connect(static::KIND, (string) $this->location);
        $filtered->getConfiguration()->setSchemaAssetsFilter(static fn (string $name): bool => ApprovalSchema::AUDIT !== $name);
        $create = implode("\n", (new SchemaTool($this->entityManager($filtered)))->getCreateSchemaSql([]));
        self::assertStringNotContainsString(ApprovalSchema::AUDIT.' ', $create);
        self::assertStringContainsString(ApprovalSchema::REQUESTS.' ', $create);
    }

    private function entityManager(Connection $connection, ?Connection $approvals = null): EntityManager
    {
        $config = method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig([], true)
            : ORMSetup::createAttributeMetadataConfiguration([], true);
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir().'/verified-human-approval-orm-proxies');
            $config->setProxyNamespace('VerifiedHumanApprovalOrmProxies');
        }
        $manager = new EntityManager($connection, $config);
        $manager->getEventManager()->addEventListener('postGenerateSchema', new ApprovalSchemaListener($approvals ?? $connection));

        return $manager;
    }

    /** @param non-empty-string $name */
    private static function introspect(AbstractSchemaManager $schemaManager, string $name): Table
    {
        return method_exists($schemaManager, 'introspectTableByUnquotedName') ? $schemaManager->introspectTableByUnquotedName($name) : $schemaManager->introspectTable($name);
    }

    /** DBAL 4.5 names a column's type and deprecates handing out the object; 4.4 has only the object. */
    private static function typeOf(Column $column): Type
    {
        return method_exists($column, 'getTypeName') ? Type::getType($column->getTypeName()) : $column->getType();
    }
}
