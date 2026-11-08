<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use VerifiedHumanApproval\Demo\DemoDatabase;

/**
 * Authorization codes with PKCE and rotating refresh tokens. An access token is an ordinary
 * delegation grant, so the authorizer rechecks it, and its scopes, whenever an approval completes.
 */
final readonly class OAuthServer
{
    public const SCOPES = ['announcement.read', 'announcement.publish', 'approval.read'];
    private const CODE_SECONDS = 300;
    private const TOKEN_SECONDS = 3600;
    /** However often it is refreshed, a connection ends after this; the person connects again. */
    private const CONNECTION_SECONDS = 30 * 86400;

    public function __construct(private DemoDatabase $database)
    {
    }

    /** @return list<string> */
    public static function scopes(?string $requested): array
    {
        if (null === $requested || '' === trim($requested)) {
            return self::SCOPES;
        }
        $scopes = array_values(array_unique(preg_split('/\s+/', trim($requested)) ?: []));
        if ([] !== array_diff($scopes, self::SCOPES)) {
            throw new OAuthError('invalid_scope', 'Unknown scope requested.');
        }

        return $scopes;
    }

    /** @param list<string> $scopes */
    public function issueCode(string $clientId, string $redirectUri, string $userId, string $tenantId, array $scopes, string $challenge, ?string $clientName = null): string
    {
        $code = self::random();
        $this->database->pdo->prepare('INSERT INTO demo_oauth_codes (code_hash, client_id, redirect_uri, user_id, tenant_id, scopes, code_challenge, expires_at, client_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $code), $clientId, $redirectUri, $userId, $tenantId, json_encode($scopes, \JSON_THROW_ON_ERROR), $challenge, time() + self::CODE_SECONDS, $clientName]);

        return $code;
    }

    /** @return array<string, mixed> the token response */
    public function exchangeCode(string $code, string $clientId, string $redirectUri, string $verifier): array
    {
        $hash = hash('sha256', $code);
        // Single use, even under a race: only one exchange can flip the flag.
        $take = $this->database->pdo->prepare('UPDATE demo_oauth_codes SET used = 1 WHERE code_hash = ? AND used = 0');
        $take->execute([$hash]);
        $query = $this->database->pdo->prepare('SELECT * FROM demo_oauth_codes WHERE code_hash = ?');
        $query->execute([$hash]);
        $row = $query->fetch();
        if (1 !== $take->rowCount() || false === $row || (int) $row['expires_at'] < time() || !hash_equals($row['client_id'], $clientId) || !hash_equals($row['redirect_uri'], $redirectUri)) {
            throw new OAuthError('invalid_grant', 'The authorization code is invalid, expired, already used or for another client.');
        }
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) || !hash_equals($row['code_challenge'], self::challenge($verifier))) {
            throw new OAuthError('invalid_grant', 'The PKCE code_verifier does not match.');
        }
        $access = self::random();
        $refresh = self::random();
        $grant = 'oauth-'.bin2hex(random_bytes(8));
        $this->database->pdo->prepare('INSERT INTO demo_grants (id, user_id, tenant_id, token_hash, active, scopes, expires_at, client_id, refresh_hash, client_name, created_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)')
            ->execute([$grant, $row['user_id'], $row['tenant_id'], hash('sha256', $access), $row['scopes'], time() + self::TOKEN_SECONDS, $clientId, hash('sha256', $refresh), $row['client_name'] ?? null, time()]);

        return self::tokens($access, $refresh, json_decode($row['scopes'], true, 4, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> the token response; the refresh token rotates, the grant stays the same */
    public function refresh(string $refreshToken, string $clientId): array
    {
        $query = $this->database->pdo->prepare('SELECT * FROM demo_grants WHERE refresh_hash = ? AND client_id = ? AND active = 1');
        $query->execute([hash('sha256', $refreshToken), $clientId]);
        $grant = $query->fetch();
        if (false === $grant) {
            throw new OAuthError('invalid_grant', 'The refresh token is invalid.');
        }
        if (null !== $grant['created_at'] && (int) $grant['created_at'] + self::CONNECTION_SECONDS < time()) {
            throw new OAuthError('invalid_grant', 'This connection has ended. Connect again.');
        }
        $access = self::random();
        $refresh = self::random();
        $update = $this->database->pdo->prepare('UPDATE demo_grants SET token_hash = ?, refresh_hash = ?, expires_at = ? WHERE id = ? AND refresh_hash = ?');
        $update->execute([hash('sha256', $access), hash('sha256', $refresh), time() + self::TOKEN_SECONDS, $grant['id'], hash('sha256', $refreshToken)]);
        if (1 !== $update->rowCount()) {
            throw new OAuthError('invalid_grant', 'The refresh token was already used.');
        }

        return self::tokens($access, $refresh, json_decode($grant['scopes'], true, 4, \JSON_THROW_ON_ERROR));
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @param list<string> $scopes */
    private static function tokens(string $access, string $refresh, array $scopes): array
    {
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::TOKEN_SECONDS, 'refresh_token' => $refresh, 'scope' => implode(' ', $scopes)];
    }

    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
