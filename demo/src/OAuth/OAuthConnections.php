<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use VerifiedHumanApproval\Demo\DemoDatabase;

/**
 * Ties the OAuth server's tokens to the demo's agent connections (demo_grants), whose id is the delegation an
 * approval request records and the authorizer rechecks. league/oauth2-server issues and checks the tokens; this
 * only decides which connection a token belongs to, and ends them all when a connection is revoked.
 *
 * Allowing a client opens a new connection (when league saves the code it issues: ConnectionOpeningCodeManager),
 * which replaces the person's earlier one with that client. However
 * often the client refreshes its tokens, a connection ends after LIFETIME_SECONDS and the person connects again.
 */
final readonly class OAuthConnections
{
    public const LIFETIME_SECONDS = 30 * 86400;

    public function __construct(private DemoDatabase $database)
    {
    }

    /**
     * @param list<string> $scopes
     *
     * @return string the new connection's id
     */
    public function open(string $userId, string $tenantId, string $clientId, string $clientName, array $scopes): string
    {
        return $this->database->transactional(function () use ($userId, $tenantId, $clientId, $clientName, $scopes): string {
            // Requests made under an earlier connection stay with it; the authorizer refuses them once it is closed.
            $this->database->pdo->prepare('UPDATE demo_grants SET active = 0 WHERE user_id = ? AND tenant_id = ? AND client_id = ? AND token_hash IS NULL AND active = 1')
                ->execute([$userId, $tenantId, $clientId]);
            $id = 'oauth-'.bin2hex(random_bytes(8));
            $this->database->pdo->prepare('INSERT INTO demo_grants (id, user_id, tenant_id, token_hash, active, scopes, client_id, client_name, created_at) VALUES (?, ?, ?, NULL, 1, ?, ?, ?, ?)')
                ->execute([$id, $userId, $tenantId, json_encode($scopes, \JSON_THROW_ON_ERROR), $clientId, $clientName, time()]);

            return $id;
        });
    }

    /**
     * The open connection a token for this person and client belongs to, or null once it was revoked or has ended.
     *
     * @return array<string, mixed>|null
     */
    public function active(?string $userId, string $clientId): ?array
    {
        if (null === $userId) {
            return null;
        }
        $query = $this->database->pdo->prepare('SELECT * FROM demo_grants WHERE user_id = ? AND client_id = ? AND token_hash IS NULL AND active = 1 ORDER BY created_at DESC LIMIT 1');
        $query->execute([$userId, $clientId]);
        $row = $query->fetch();

        return \is_array($row) && (int) $row['created_at'] + self::LIFETIME_SECONDS >= time() ? $row : null;
    }

    /** Ends every code and token this client holds for this person. Call it in the transaction that revokes the connection. */
    public function revokeTokens(string $userId, string $clientId): void
    {
        $this->database->pdo->prepare('UPDATE oauth2_refresh_tokens SET revoked = 1 WHERE access_token IN (SELECT identifier FROM oauth2_access_tokens WHERE user_identifier = ? AND client = ?)')
            ->execute([$userId, $clientId]);
        $this->database->pdo->prepare('UPDATE oauth2_access_tokens SET revoked = 1 WHERE user_identifier = ? AND client = ?')->execute([$userId, $clientId]);
        $this->database->pdo->prepare('UPDATE oauth2_authorization_codes SET revoked = 1 WHERE user_identifier = ? AND client = ?')->execute([$userId, $clientId]);
    }
}
