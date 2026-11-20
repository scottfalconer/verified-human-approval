<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * The tables of the approval store, the signed audit log and the passkey repository, in portable DBAL types.
 *
 * Canonical JSON is kept in plain text columns, never JSON or JSONB, which rewrite the bytes a digest or signature
 * covers. On MySQL and MariaDB a text column is LONGTEXT, so a 256 KiB proposal fits, and every table is utf8mb4 with
 * a binary collation that does not ignore trailing spaces, so identifiers compare byte for byte.
 *
 * Add the tables to a migration (the ORM schema listener does this for doctrine:migrations:diff), or call install()
 * once. Either way, never while a transaction is open: on MySQL a schema change commits it.
 */
final class ApprovalSchema
{
    public const REQUESTS = 'verified_human_approval_requests';
    public const EXECUTIONS = 'verified_human_approval_executions';
    public const RECEIPTS = 'verified_human_approval_receipts';
    public const CEREMONIES = 'verified_human_approval_ceremonies';
    public const AUDIT = 'verified_human_approval_audit';
    public const CREDENTIALS = 'verified_human_approval_credentials';
    /** One row per lock the stores take on PostgreSQL, MySQL and MariaDB; on SQLite, what the write reservation names. */
    public const LOCKS = 'verified_human_approval_lock';

    /** The lock row the audit log's appenders take before they read its head. */
    public const AUDIT_LOCK = 'audit';

    /**
     * The tables, in an order a database can create them in.
     *
     * @return list<Table>
     */
    public static function tables(AbstractPlatform $platform): array
    {
        $dialect = Dialect::of($platform);
        $id = self::string(...);
        $text = self::text(...);
        $optional = static fn (Column $column): Column => $column->edit()->setNotNull(false)->create();
        $flag = self::flag(...);
        $index = self::index(...);
        $unique = self::unique(...);
        $primaryKey = self::primaryKey(...);
        $request = self::requestReference(...);
        $options = null === $dialect->collation ? [] : ['charset' => 'utf8mb4', 'collation' => $dialect->collation, 'engine' => 'InnoDB'];

        return [
            Table::editor()->setUnquotedName(self::LOCKS)
                ->addColumn($id('name'))
                ->addPrimaryKeyConstraint($primaryKey('name'))
                ->setOptions($options)->create(),
            // seq orders the requests, as SQLite's rowid did; tenant_id and resource_key index the history of one resource.
            Table::editor()->setUnquotedName(self::REQUESTS)
                ->addColumn(Column::editor()->setUnquotedName('seq')->setTypeName(Types::BIGINT)->setAutoincrement(true)->create())
                ->addColumn($id('id'))
                ->addColumn($id('actor_key'))
                ->addColumn($optional($id('idempotency_key')))
                ->addColumn($id('fingerprint'))
                ->addColumn($id('tenant_id', 255))
                ->addColumn($optional($id('resource_key')))
                ->addColumn($text('proposal'))
                ->addColumn($id('digest'))
                ->addColumn($id('status', 16))
                ->addColumn($optional($text('reason')))
                ->addColumn($optional($text('result')))
                ->addColumn($optional($text('proof')))
                ->addPrimaryKeyConstraint($primaryKey('seq'))
                ->addIndex($unique('verified_human_approval_request_id', 'id'))
                ->addIndex($unique('verified_human_approval_request_key', 'actor_key', 'idempotency_key'))
                ->addIndex($index('verified_human_approval_request_pending', 'actor_key', 'fingerprint', 'status'))
                ->addIndex($index('verified_human_approval_request_resource', 'tenant_id', 'resource_key', 'seq'))
                ->setOptions($options)->create(),
            Table::editor()->setUnquotedName(self::EXECUTIONS)
                ->addColumn($id('request_id'))
                ->addColumn($text('record'))
                ->addPrimaryKeyConstraint($primaryKey('request_id'))
                ->addForeignKeyConstraint($request('verified_human_approval_execution_request'))
                ->setOptions($options)->create(),
            // The application's receipt, signed in the completing transaction.
            Table::editor()->setUnquotedName(self::RECEIPTS)
                ->addColumn($id('request_id'))
                ->addColumn($text('key_id'))
                ->addColumn($text('manifest'))
                ->addColumn($text('signature'))
                ->addPrimaryKeyConstraint($primaryKey('request_id'))
                ->addForeignKeyConstraint($request('verified_human_approval_receipt_request'))
                ->setOptions($options)->create(),
            Table::editor()->setUnquotedName(self::CEREMONIES)
                ->addColumn($id('id'))
                ->addColumn($id('request_id'))
                ->addColumn($id('nonce', 255))
                ->addColumn($id('challenge', 255))
                ->addColumn($id('expires_at', 32))
                ->addColumn($text('options'))
                ->addColumn($flag('consumed'))
                ->addPrimaryKeyConstraint($primaryKey('id'))
                ->addIndex($index('verified_human_approval_ceremony_request', 'request_id'))
                ->addForeignKeyConstraint($request('verified_human_approval_ceremony_request_fk'))
                ->setOptions($options)->create(),
            // A subject can be a passkey id of up to 1,364 characters, so its index uses a digest of it.
            Table::editor()->setUnquotedName(self::AUDIT)
                ->addColumn(Column::editor()->setUnquotedName('seq')->setTypeName(Types::BIGINT)->create())
                ->addColumn($id('tenant_id', 255))
                ->addColumn($id('type', 255))
                ->addColumn($text('subject'))
                ->addColumn($id('subject_key'))
                ->addColumn($text('entry'))
                ->addColumn($text('hash'))
                ->addColumn($text('key_id'))
                ->addColumn($text('signature'))
                ->addPrimaryKeyConstraint($primaryKey('seq'))
                ->addIndex($index('verified_human_approval_audit_subject', 'type', 'subject_key'))
                ->setOptions($options)->create(),
            // Keyed by a digest of the passkey id for the same reason; the id itself is kept exactly.
            Table::editor()->setUnquotedName(self::CREDENTIALS)
                ->addColumn($id('credential_key'))
                ->addColumn($text('credential_id'))
                ->addColumn($id('user_id', 255))
                ->addColumn($id('tenant_id', 255))
                ->addColumn($text('source_json'))
                ->addColumn($flag('revoked'))
                ->addColumn($text('label'))
                ->addPrimaryKeyConstraint($primaryKey('credential_key'))
                ->addIndex($index('verified_human_approval_credentials_owner', 'tenant_id', 'user_id', 'revoked'))
                ->setOptions($options)->create(),
        ];
    }

    /**
     * Adds the tables a schema does not have yet, for a schema listener or a migration. Returns the schema with
     * them: on DBAL 4.5 and later a new one, built with the schema editor, so pass it on; with $inPlace, or on an
     * older DBAL, the same object, changed.
     *
     * @param (callable(string): bool)|null $assetFilter the connection's schema assets filter
     */
    public static function addTo(Schema $schema, AbstractPlatform $platform, bool $inPlace = false, ?callable $assetFilter = null): Schema
    {
        // A table the application's schema filter hides (DoctrineBundle's schema_filter) is left out, as it would be
        // left out of the database's schema, so a migration diff never proposes to create a table that exists.
        $missing = array_values(array_filter(self::tables($platform), static fn (Table $table): bool => !$schema->hasTable($table->getObjectName()->toString())
            && (null === $assetFilter || $assetFilter($table->getObjectName()->toString()))));
        if ([] === $missing) {
            return $schema;
        }
        if (!$inPlace && self::supports(Schema::class, 'edit')) {
            $editor = $schema->edit();
            foreach ($missing as $table) {
                $editor->addTable($table);
            }

            return $editor->create();
        }
        // Without a schema editor, the protected method the constructor itself uses adds a built table.
        \Closure::bind(static function (Schema $schema) use ($missing): void {
            foreach ($missing as $table) {
                $schema->_addTable($table);
            }
        }, null, Schema::class)($schema);

        return $schema;
    }

    /**
     * Creates the tables that are missing and the lock rows, for an application without migrations. It never runs
     * inside a transaction, and refuses a table that exists with another layout, such as the PDO store's.
     */
    public static function install(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            throw new \LogicException('Install the approval tables outside any transaction: a schema change is not part of an approval, and on MySQL it commits the open transaction.');
        }
        $schemaManager = $connection->createSchemaManager();
        foreach (self::tables($connection->getDatabasePlatform()) as $table) {
            $name = $table->getObjectName()->toString();
            $columns = array_map(static fn (Column $column): string => $column->getObjectName()->toString(), $table->getColumns());
            try {
                $connection->executeQuery('SELECT '.implode(', ', $columns).' FROM '.$name.' WHERE 1 = 0')->free();
            } catch (TableNotFoundException) {
                $schemaManager->createTable($table);
            } catch (DriverException $error) {
                // SQLite reports a missing column in a SELECT only by its message.
                if (!$error instanceof InvalidFieldNameException && !str_contains($error->getMessage(), 'no such column')) {
                    throw $error;
                }
                throw new \LogicException(\sprintf('The table %s exists with other columns, perhaps the SQLite PDO store\'s. Move its rows to the Doctrine layout before using this store.', $name), 0, $error);
            }
        }
        self::seed($connection);
    }

    /**
     * Inserts the missing lock rows outside an open transaction. Safe to repeat from several processes at once.
     * On PostgreSQL, MySQL and MariaDB, DbalTransaction::run() calls it before opening its first transaction.
     * If a migration created the tables, seed before opening a host transaction that appends to the audit log.
     */
    public static function seed(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            throw new \LogicException('Seed the approval lock rows outside any transaction.');
        }
        $names = self::lockNames();
        try {
            if (\count($names) === (int) $connection->fetchOne('SELECT COUNT(*) FROM '.self::LOCKS)) {
                return;
            }
        } catch (TableNotFoundException $error) {
            throw new \LogicException('The approval tables are not installed: run the migration that creates them, or ApprovalSchema::install().', 0, $error);
        }
        $present = array_map(strval(...), $connection->fetchFirstColumn('SELECT name FROM '.self::LOCKS));
        foreach (array_diff($names, $present) as $name) {
            try {
                $connection->insert(self::LOCKS, ['name' => $name]);
            } catch (UniqueConstraintViolationException) {
                // Another process inserted it first.
            }
        }
    }

    /**
     * The lock rows: the audit log's, and 256 stripes that serialize new requests by the same agent connection.
     *
     * @return list<string>
     */
    public static function lockNames(): array
    {
        return [self::AUDIT_LOCK, ...array_map(static fn (int $stripe): string => self::requestLock(\sprintf('%02x', $stripe)), range(0, 255))];
    }

    /** The lock row that serializes new requests made under an actor key (a SHA-256 hex digest). */
    public static function requestLock(string $actorKey): string
    {
        return 'request:'.substr($actorKey, 0, 2);
    }

    /** @param non-empty-string $name */
    private static function string(string $name, int $length = 64): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::STRING)->setLength($length)->create();
    }

    /** @param non-empty-string $name */
    private static function text(string $name): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::TEXT)->create();
    }

    /** @param non-empty-string $name */
    private static function flag(string $name): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::SMALLINT)->setDefaultValue(0)->create();
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $column
     * @param non-empty-string ...$columns
     */
    private static function index(string $name, string $column, string ...$columns): Index
    {
        return Index::editor()->setUnquotedName($name)->setUnquotedColumnNames($column, ...$columns)->create();
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $column
     * @param non-empty-string ...$columns
     */
    private static function unique(string $name, string $column, string ...$columns): Index
    {
        return Index::editor()->setUnquotedName($name)->setType(IndexType::UNIQUE)->setUnquotedColumnNames($column, ...$columns)->create();
    }

    /** @param non-empty-string $column */
    private static function primaryKey(string $column): PrimaryKeyConstraint
    {
        return PrimaryKeyConstraint::editor()->setUnquotedColumnNames($column)->create();
    }

    /** @param non-empty-string $name */
    private static function requestReference(string $name): ForeignKeyConstraint
    {
        return ForeignKeyConstraint::editor()->setUnquotedName($name)
            ->setUnquotedReferencingColumnNames('request_id')->setUnquotedReferencedTableName(self::REQUESTS)->setUnquotedReferencedColumnNames('id')->create();
    }

    /** Whether this DBAL has a method: the schema editor arrived in 4.5. */
    private static function supports(string $class, string $method): bool
    {
        return class_exists($class) && (new \ReflectionClass($class))->hasMethod($method);
    }
}
