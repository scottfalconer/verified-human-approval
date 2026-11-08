<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use VerifiedHumanApproval\Demo\DemoDatabase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * MCP clients identify themselves in one of two ways the MCP authorization spec allows:
 * Dynamic Client Registration (RFC 7591), or a Client ID Metadata Document published at the
 * HTTPS URL that serves as the client_id. Every client is public and must use PKCE.
 */
final readonly class ClientRegistry
{
    /** How long a validated metadata document is remembered without a Cache-Control header, and the bounds a header can move it within. */
    private const DEFAULT_SECONDS = 300;
    private const MIN_SECONDS = 60;
    private const MAX_SECONDS = 3600;
    /** A document that failed is remembered briefly too, so a bad client ID cannot make this server fetch on every request. */
    private const NEGATIVE_SECONDS = 60;

    private HttpClientInterface $http;
    private CacheInterface $cache;

    public function __construct(private DemoDatabase $database, ?HttpClientInterface $http = null, ?CacheInterface $cache = null)
    {
        // A client ID is a URL anyone can name, so fetching it must not reach this server's own network:
        // loopback, private and link-local addresses (such as cloud metadata) are refused, and the
        // resolved address is pinned, so DNS rebinding cannot swap it in afterwards.
        $this->http = $http ?? new NoPrivateNetworkHttpClient(HttpClient::create());
        $this->cache = $cache ?? new ArrayAdapter();
    }

    /** @return array<string, mixed> the registration response */
    public function register(array $metadata): array
    {
        $uris = $metadata['redirect_uris'] ?? null;
        if (!\is_array($uris) || $uris === [] || \count($uris) > 10 || !array_is_list($uris)) {
            throw new OAuthError('invalid_redirect_uri', 'Provide one to ten redirect_uris.');
        }
        foreach ($uris as $uri) self::assertRedirectUri($uri);
        $name = \is_string($metadata['client_name'] ?? null) && trim($metadata['client_name']) !== '' ? mb_substr(trim($metadata['client_name']), 0, 100) : 'MCP client';
        $id = 'client_'.bin2hex(random_bytes(16));
        $this->database->pdo->prepare('INSERT INTO demo_oauth_clients (client_id, client_name, redirect_uris, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$id, $name, json_encode($uris, JSON_THROW_ON_ERROR), time()]);

        return ['client_id' => $id, 'client_name' => $name, 'redirect_uris' => $uris, 'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']];
    }

    /** @return null|array{client_id: string, client_name: string, redirect_uris: list<string>} */
    public function find(string $clientId): ?array
    {
        if (str_starts_with($clientId, 'https://')) {
            return $this->metadataDocument($clientId);
        }
        $query = $this->database->pdo->prepare('SELECT * FROM demo_oauth_clients WHERE client_id = ?');
        $query->execute([$clientId]);
        $row = $query->fetch();

        return $row === false ? null : ['client_id' => $row['client_id'], 'client_name' => $row['client_name'], 'redirect_uris' => json_decode($row['redirect_uris'], true, 4, JSON_THROW_ON_ERROR)];
    }

    public static function assertRedirectUri(mixed $uri): void
    {
        $parts = \is_string($uri) && \strlen($uri) <= 512 ? parse_url($uri) : false;
        $valid = \is_array($parts) && isset($parts['scheme'], $parts['host']) && !isset($parts['fragment'])
            && ($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && \in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)));
        if (!$valid) {
            throw new OAuthError('invalid_redirect_uri', 'Redirect URIs must use HTTPS, or HTTP on localhost, and carry no fragment.');
        }
    }

    /** Whether every redirect URI stays on this computer: a sign to show the person before they allow it. */
    public static function loopbackOnly(array $redirectUris): bool
    {
        foreach ($redirectUris as $uri) {
            if (!\in_array(parse_url((string) $uri, PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true)) {
                return false;
            }
        }

        return $redirectUris !== [];
    }

    /**
     * A Client ID Metadata Document: fetched once per cache lifetime, small, without redirects, and
     * naming itself. The spec asks authorization servers to cache it, honouring HTTP cache headers.
     */
    private function metadataDocument(string $url): ?array
    {
        $parts = parse_url($url);
        $path = \is_array($parts) ? ($parts['path'] ?? '') : '';
        // The spec requires a path, so a bare origin cannot pose as a client's metadata document.
        if (!\is_array($parts) || $path === '' || $path === '/' || isset($parts['fragment'])) return null;
        $cached = $this->cache->get('cimd.'.hash('sha256', $url), function (ItemInterface $item) use ($url): array {
            [$document, $seconds] = $this->fetchMetadataDocument($url);
            $item->expiresAfter($seconds);

            return ['document' => $document];
        });

        return $cached['document'];
    }

    /** @return array{0: null|array{client_id: string, client_name: string, redirect_uris: list<string>}, 1: int} the validated document, and how long to remember the answer */
    private function fetchMetadataDocument(string $url): array
    {
        try {
            $response = $this->http->request('GET', $url, ['timeout' => 5, 'max_redirects' => 0, 'headers' => ['Accept' => 'application/json']]);
            if ($response->getStatusCode() !== 200) return [null, self::NEGATIVE_SECONDS];
            $body = $response->getContent();
            if (\strlen($body) > 65536) return [null, self::NEGATIVE_SECONDS];
            $document = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            // The document must name itself and carry the fields the spec requires: client_id, client_name and redirect_uris.
            if (!\is_array($document) || ($document['client_id'] ?? null) !== $url || !\is_string($document['client_name'] ?? null) || trim($document['client_name']) === ''
                || !\is_array($document['redirect_uris'] ?? null) || $document['redirect_uris'] === [] || !array_is_list($document['redirect_uris'])) {
                return [null, self::NEGATIVE_SECONDS];
            }
            foreach ($document['redirect_uris'] as $uri) self::assertRedirectUri($uri);
            $seconds = self::lifetime($response->getHeaders(false)['cache-control'] ?? []);
        } catch (\Throwable) {
            return [null, self::NEGATIVE_SECONDS];
        }

        return [['client_id' => $url, 'client_name' => mb_substr(trim($document['client_name']), 0, 100), 'redirect_uris' => $document['redirect_uris']], $seconds];
    }

    /** @param list<string> $cacheControl */
    private static function lifetime(array $cacheControl): int
    {
        foreach ($cacheControl as $header) {
            if (preg_match('/(?:^|[,\s])max-age=(\d{1,9})/i', $header, $match) === 1) {
                return max(self::MIN_SECONDS, min(self::MAX_SECONDS, (int) $match[1]));
            }
        }

        return self::DEFAULT_SECONDS;
    }
}
