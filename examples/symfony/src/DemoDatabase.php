<?php

declare(strict_types=1);

namespace ExactApproval\Demo;

/** Example application data. The library has no dependency on these tables. */
final readonly class DemoDatabase
{
    public function __construct(public \PDO $pdo) {}

    public static function connect(string $path): \PDO
    {
        $pdo = new \PDO('sqlite:'.$path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000');

        return $pdo;
    }

    public function initialize(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS demo_users (
    id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, display_name TEXT NOT NULL, password_hash TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1, can_publish INTEGER NOT NULL DEFAULT 0, is_admin INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS demo_grants (
    id TEXT PRIMARY KEY, user_id TEXT NOT NULL, tenant_id TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE,
    active INTEGER NOT NULL DEFAULT 1, scopes TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS demo_announcements (
    id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, revision INTEGER NOT NULL, title TEXT NOT NULL, body TEXT NOT NULL,
    published_revision INTEGER, published_title TEXT, published_body TEXT, mutation_count INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS demo_policies (
    action_id TEXT PRIMARY KEY, version INTEGER NOT NULL, statement TEXT NOT NULL, required INTEGER NOT NULL
);
SQL);
    }

    public function user(string $id): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM demo_users WHERE id = ?');
        $query->execute([$id]);

        return $query->fetch() ?: null;
    }

    public function grant(string $id): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM demo_grants WHERE id = ?');
        $query->execute([$id]);

        return $query->fetch() ?: null;
    }

    public function tokenGrant(string $token): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM demo_grants WHERE token_hash = ?');
        $query->execute([hash('sha256', $token)]);

        return $query->fetch() ?: null;
    }

    public function announcement(string $id): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM demo_announcements WHERE id = ?');
        $query->execute([$id]);

        return $query->fetch() ?: null;
    }

    public function policy(string $action): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM demo_policies WHERE action_id = ?');
        $query->execute([$action]);

        return $query->fetch() ?: null;
    }
}
