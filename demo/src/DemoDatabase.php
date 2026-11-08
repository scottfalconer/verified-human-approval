<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo;

/** Example application data. The library has no dependency on these tables. */
final readonly class DemoDatabase
{
    public function __construct(public \PDO $pdo)
    {
    }

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
    enabled INTEGER NOT NULL DEFAULT 1, can_publish INTEGER NOT NULL DEFAULT 0, is_admin INTEGER NOT NULL DEFAULT 0,
    role TEXT NOT NULL DEFAULT 'reader'
);
CREATE TABLE IF NOT EXISTS demo_grants (
    id TEXT PRIMARY KEY, user_id TEXT NOT NULL, tenant_id TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE,
    active INTEGER NOT NULL DEFAULT 1, scopes TEXT NOT NULL,
    expires_at INTEGER, client_id TEXT, refresh_hash TEXT UNIQUE,
    client_name TEXT, created_at INTEGER
);
-- OAuth for remote MCP clients: registered public clients and single-use PKCE authorization codes.
CREATE TABLE IF NOT EXISTS demo_oauth_clients (
    client_id TEXT PRIMARY KEY, client_name TEXT NOT NULL, redirect_uris TEXT NOT NULL, created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS demo_oauth_codes (
    code_hash TEXT PRIMARY KEY, client_id TEXT NOT NULL, redirect_uri TEXT NOT NULL, user_id TEXT NOT NULL, tenant_id TEXT NOT NULL,
    scopes TEXT NOT NULL, code_challenge TEXT NOT NULL, expires_at INTEGER NOT NULL, used INTEGER NOT NULL DEFAULT 0,
    client_name TEXT
);
CREATE TABLE IF NOT EXISTS demo_announcements (
    id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, revision INTEGER NOT NULL, title TEXT NOT NULL, body TEXT NOT NULL,
    published_revision INTEGER, published_title TEXT, published_body TEXT, mutation_count INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS demo_policies (
    action_id TEXT PRIMARY KEY, version INTEGER NOT NULL, statement TEXT NOT NULL, required INTEGER NOT NULL
);
-- A person's first approval passkey needs a one-time link from setup or an administrator; a password is not enough.
CREATE TABLE IF NOT EXISTS demo_passkey_links (
    token_hash TEXT PRIMARY KEY, user_id TEXT NOT NULL, tenant_id TEXT NOT NULL, issued_by TEXT NOT NULL,
    issued_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, used_at INTEGER, credential_id TEXT
);
-- Per role: 'direct', 'approval' (verified human approval of each exact action) or 'denied'.
CREATE TABLE IF NOT EXISTS demo_role_policies (
    action_id TEXT NOT NULL, role TEXT NOT NULL, mode TEXT NOT NULL CHECK (mode IN ('direct', 'approval', 'denied')),
    PRIMARY KEY (action_id, role)
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

    /** @return list<array<string, mixed>> */
    public function users(string $tenantId): array
    {
        $query = $this->pdo->prepare('SELECT id, tenant_id, display_name, enabled, can_publish, is_admin, role FROM demo_users WHERE tenant_id = ? ORDER BY id');
        $query->execute([$tenantId]);

        return array_values($query->fetchAll());
    }

    /** @return list<array<string, mixed>> agent connections: personal tokens and OAuth grants */
    public function grants(string $tenantId, ?string $userId = null): array
    {
        $query = $this->pdo->prepare('SELECT id, user_id, tenant_id, active, scopes, expires_at, client_id, client_name, created_at FROM demo_grants WHERE tenant_id = ?'.(null === $userId ? '' : ' AND user_id = ?').' ORDER BY created_at DESC, id');
        $query->execute(null === $userId ? [$tenantId] : [$tenantId, $userId]);

        return array_values($query->fetchAll());
    }

    /** Ends an agent connection: its access and refresh tokens stop working at once. */
    public function revokeGrant(string $id, string $tenantId, ?string $userId = null): bool
    {
        $query = $this->pdo->prepare('UPDATE demo_grants SET active = 0, refresh_hash = NULL WHERE id = ? AND tenant_id = ? AND active = 1'.(null === $userId ? '' : ' AND user_id = ?'));
        $query->execute(null === $userId ? [$id, $tenantId] : [$id, $tenantId, $userId]);

        return 1 === $query->rowCount();
    }

    /** Issues a one-time link for adding a first passkey. Returns the token, which is stored only as a hash. */
    public function issuePasskeyLink(string $userId, string $tenantId, string $issuedBy, int $lifetime): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->pdo->prepare('INSERT INTO demo_passkey_links (token_hash, user_id, tenant_id, issued_by, issued_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $token), $userId, $tenantId, $issuedBy, time(), time() + $lifetime]);

        return $token;
    }

    /** @return array<string, mixed>|null an unused, unexpired link */
    public function passkeyLink(string $token): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM demo_passkey_links WHERE token_hash = ? AND used_at IS NULL AND expires_at >= ?');
        $query->execute([hash('sha256', $token), time()]);

        return $query->fetch() ?: null;
    }

    /** Marks a link used by one registration; only one registration can use it. */
    public function usePasskeyLink(string $tokenHash, string $credentialId): bool
    {
        $query = $this->pdo->prepare('UPDATE demo_passkey_links SET used_at = ?, credential_id = ? WHERE token_hash = ? AND used_at IS NULL AND expires_at >= ?');
        $query->execute([time(), $credentialId, $tokenHash, time()]);

        return 1 === $query->rowCount();
    }

    /** @return list<array<string, mixed>> */
    public function passkeyLinks(string $tenantId): array
    {
        $query = $this->pdo->prepare('SELECT user_id, issued_by, issued_at, expires_at, used_at, credential_id FROM demo_passkey_links WHERE tenant_id = ? ORDER BY issued_at DESC');
        $query->execute([$tenantId]);

        return array_values($query->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function liveAlert(): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM demo_announcements WHERE published_revision IS NOT NULL ORDER BY id LIMIT 1');
        $query->execute();

        return $query->fetch() ?: null;
    }

    public function roleMode(string $action, string $role): ?string
    {
        $query = $this->pdo->prepare('SELECT mode FROM demo_role_policies WHERE action_id = ? AND role = ?');
        $query->execute([$action, $role]);
        $mode = $query->fetchColumn();

        return false === $mode ? null : (string) $mode;
    }

    public function policy(string $action): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM demo_policies WHERE action_id = ?');
        $query->execute([$action]);

        return $query->fetch() ?: null;
    }
}
