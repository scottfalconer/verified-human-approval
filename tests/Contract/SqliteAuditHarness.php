<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\Core\Audit\AuditStore;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;

/** The SQLite reference audit store, PdoAuditStore, in SQLite's default rollback journal. The location is a database file. */
class SqliteAuditHarness extends AuditHarness
{
    protected const JOURNAL = 'delete';

    private readonly \PDO $pdo;
    private readonly PdoAuditStore $store;

    final public function __construct(private readonly string $location)
    {
        $this->pdo = self::open($location);
        $this->store = new PdoAuditStore($this->pdo);
    }

    public static function prepare(): string
    {
        $location = sys_get_temp_dir().'/verified-human-approval-audit-'.bin2hex(random_bytes(8)).'.sqlite';
        $pdo = self::open($location);
        if (static::JOURNAL !== $pdo->query('PRAGMA journal_mode = '.static::JOURNAL)->fetchColumn()) {
            throw new \RuntimeException('SQLite did not switch to the '.static::JOURNAL.' journal.');
        }
        (new PdoAuditStore($pdo))->initialize();
        $pdo->exec('CREATE TABLE business (note TEXT)');

        return $location;
    }

    public static function destroy(string $location): void
    {
        foreach ([$location, $location.'-wal', $location.'-shm', $location.'-journal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public static function connect(string $location): static
    {
        return new static($location);
    }

    public function store(): AuditStore
    {
        return $this->store;
    }

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function writeBusinessRow(): void
    {
        $this->pdo->exec("INSERT INTO business (note) VALUES ('revoked')");
    }

    public function businessRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM business')->fetchColumn();
    }

    public function read(): void
    {
        $this->pdo->query('SELECT COUNT(*) FROM '.self::TABLE)->fetchColumn();
    }

    public function lockTimeout(int $milliseconds): void
    {
        $this->pdo->exec('PRAGMA busy_timeout = '.$milliseconds);
    }

    public function isLockTimeout(\Throwable $error): bool
    {
        return $error instanceof \PDOException && str_contains($error->getMessage(), 'database is locked');
    }

    public function readersBlockWriters(): bool
    {
        return 'delete' === static::JOURNAL;
    }

    public function appendsFromAnOlderViewFail(): bool
    {
        return true;
    }

    public function failInserts(): void
    {
        $this->pdo->exec('CREATE TRIGGER reject_audit BEFORE INSERT ON '.self::TABLE." BEGIN SELECT RAISE(ABORT, 'injected insert failure'); END");
    }

    public function clearInsertFailures(): void
    {
        $this->pdo->exec('DROP TRIGGER IF EXISTS reject_audit');
    }

    public function insertRaw(array $row): void
    {
        $this->pdo->prepare('INSERT INTO '.self::TABLE.' (seq, tenant_id, type, subject, entry, hash, key_id, signature) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$row['seq'], $row['tenant_id'], $row['type'], $row['subject'], $row['entry'], $row['hash'], $row['key_id'], $row['signature']]);
    }

    public function damage(int $seq, string $assignment): void
    {
        $this->pdo->prepare('UPDATE '.self::TABLE.' SET '.$assignment.' WHERE seq = ?')->execute([$seq]);
    }

    public function column(int $seq, string $column): string
    {
        $query = $this->pdo->prepare('SELECT '.$column.' FROM '.self::TABLE.' WHERE seq = ?');
        $query->execute([$seq]);

        return (string) $query->fetchColumn();
    }

    private static function open(string $location): \PDO
    {
        $pdo = new \PDO('sqlite:'.$location, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 15000');

        return $pdo;
    }
}
