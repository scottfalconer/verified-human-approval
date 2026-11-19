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

    public function __construct(private DemoDatabase $database, private OAuthTransaction $transaction)
    {
    }

    /**
     * @param list<string> $scopes
     *
     * @return string the new connection's id
     */
    public function open(string $userId, string $tenantId, string $clientId, string $clientName, array $scopes): string
    {
        return $this->transaction->run(function () use ($userId, $tenantId, $clientId, $clientName, $scopes): string {
            // Requests made under an earlier connection stay with it; the authorizer refuses them once it is closed.
            $this->database->pdo->prepare('UPDATE demo_grants SET active = 0 WHERE user_id = ? AND tenant_id = ? AND client_id = ? AND token_hash IS NULL AND active = 1')
                ->execute([$userId, $tenantId, $clientId]);
            $this->revokeTokens($userId, $clientId);
            $id = 'oauth-'.bin2hex(random_bytes(8));
            $this->database->pdo->prepare('INSERT INTO demo_grants (id, user_id, tenant_id, token_hash, active, scopes, client_id, client_name, created_at) VALUES (?, ?, ?, NULL, 1, ?, ?, ?, ?)')
                ->execute([$id, $userId, $tenantId, json_encode($scopes, \JSON_THROW_ON_ERROR), $clientId, $clientName, time()]);

            return $id;
        });
    }

    /**
     * The current connection for account display. Credentials must use their stored grant id through bound().
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

        return \is_array($row) && self::isLive($row) ? $row : null;
    }

    /** @return array<string, mixed>|null the credential's original connection, never a replacement */
    public function bound(string $grantId, ?string $userId, string $clientId): ?array
    {
        $grant = $this->database->grant($grantId);

        return null !== $grant && self::isLive($grant) && $grant['user_id'] === $userId && $grant['client_id'] === $clientId ? $grant : null;
    }

    /** @return array<string, mixed>|null the connection of an unrevoked access token, for the client it was issued to */
    public function forAccessToken(string $identifier, ?string $userId): ?array
    {
        $query = $this->database->pdo->prepare('SELECT grant_id, client FROM oauth2_access_tokens WHERE identifier = ? AND user_identifier = ? AND revoked = 0');
        $query->execute([$identifier, $userId]);
        $token = $query->fetch();

        return \is_array($token) && \is_string($token['grant_id']) && \is_string($token['client']) ? $this->bound($token['grant_id'], $userId, $token['client']) : null;
    }

    /** @param array<string, mixed> $grant */
    public static function isLive(array $grant): bool
    {
        return (bool) $grant['active'] && null === $grant['token_hash'] && null !== $grant['client_id']
            && null !== $grant['created_at'] && (int) $grant['created_at'] + self::LIFETIME_SECONDS > time();
    }

    /** Ends every code and token this client holds for this person, before a replacement code is saved. */
    public function revokeTokens(string $userId, string $clientId): void
    {
        $this->transaction->assertActive();
        $this->database->pdo->prepare('UPDATE oauth2_refresh_tokens SET revoked = 1 WHERE access_token IN (SELECT identifier FROM oauth2_access_tokens WHERE user_identifier = ? AND client = ?)')
            ->execute([$userId, $clientId]);
        $this->database->pdo->prepare('UPDATE oauth2_access_tokens SET revoked = 1 WHERE user_identifier = ? AND client = ?')->execute([$userId, $clientId]);
        $this->database->pdo->prepare('UPDATE oauth2_authorization_codes SET revoked = 1 WHERE user_identifier = ? AND client = ?')->execute([$userId, $clientId]);
    }

    public function revokeTokensForGrant(string $grantId): void
    {
        $this->transaction->assertActive();
        foreach (['oauth2_refresh_tokens', 'oauth2_access_tokens', 'oauth2_authorization_codes'] as $table) {
            $this->database->pdo->prepare('UPDATE '.$table.' SET revoked = 1 WHERE grant_id = ?')->execute([$grantId]);
        }
    }
}
