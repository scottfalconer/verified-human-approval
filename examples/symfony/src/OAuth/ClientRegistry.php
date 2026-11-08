<?php

declare(strict_types=1);

namespace ExactApproval\Demo\OAuth;

use ExactApproval\Demo\DemoDatabase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * MCP clients identify themselves in one of two ways the MCP authorization spec allows:
 * Dynamic Client Registration (RFC 7591), or a Client ID Metadata Document published at the
 * HTTPS URL that serves as the client_id. Every client is public and must use PKCE.
 */
final readonly class ClientRegistry
{
    private HttpClientInterface $http;

    public function __construct(private DemoDatabase $database, ?HttpClientInterface $http = null)
    {
        // A client ID is a URL anyone can name, so fetching it must not reach this server's own network:
        // loopback, private and link-local addresses (such as cloud metadata) are refused, and the
        // resolved address is pinned, so DNS rebinding cannot swap it in afterwards.
        $this->http = $http ?? new NoPrivateNetworkHttpClient(HttpClient::create());
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
            && ($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && \in_array($parts['host'], ['localhost', '127.0.0.1'], true)));
        if (!$valid) {
            throw new OAuthError('invalid_redirect_uri', 'Redirect URIs must use HTTPS, or HTTP on localhost, and carry no fragment.');
        }
    }

    /** A Client ID Metadata Document: fetched once, small, without redirects, and naming itself. */
    private function metadataDocument(string $url): ?array
    {
        $parts = parse_url($url);
        $path = \is_array($parts) ? ($parts['path'] ?? '') : '';
        // The spec requires a path, so a bare origin cannot pose as a client's metadata document.
        if (!\is_array($parts) || $path === '' || $path === '/' || isset($parts['fragment'])) return null;
        try {
            $response = $this->http->request('GET', $url, ['timeout' => 5, 'max_redirects' => 0, 'headers' => ['Accept' => 'application/json']]);
            if ($response->getStatusCode() !== 200) return null;
            $body = $response->getContent();
            if (\strlen($body) > 65536) return null;
            $document = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (!\is_array($document) || ($document['client_id'] ?? null) !== $url || !\is_array($document['redirect_uris'] ?? null) || !array_is_list($document['redirect_uris'])) return null;
            foreach ($document['redirect_uris'] as $uri) self::assertRedirectUri($uri);
        } catch (\Throwable) {
            return null;
        }
        $name = \is_string($document['client_name'] ?? null) && trim($document['client_name']) !== '' ? mb_substr(trim($document['client_name']), 0, 100) : (string) $parts['host'];

        return ['client_id' => $url, 'client_name' => $name, 'redirect_uris' => $document['redirect_uris']];
    }
}
