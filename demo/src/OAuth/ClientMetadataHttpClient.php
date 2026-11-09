<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The HTTP client omouren/mcp-oauth-bundle fetches client ID metadata documents with. Anyone can name a client
 * ID URL, so on top of that bundle's own host checks the fetch never reaches this server's own network (checked
 * on the address actually connected to), stops reading at MAX_BYTES while it downloads, and ends after 10 seconds.
 */
final class ClientMetadataHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    public const MAX_BYTES = 65536;

    public function __construct(?HttpClientInterface $client = null)
    {
        $this->client = $client ?? new NoPrivateNetworkHttpClient(HttpClient::create());
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $onProgress = $options['on_progress'] ?? null;
        $options['on_progress'] = static function (int $downloaded, int $size, array $info) use ($onProgress): void {
            if ($downloaded > self::MAX_BYTES || $size > self::MAX_BYTES) {
                throw new TransportException('A client ID metadata document is limited to '.self::MAX_BYTES.' bytes.');
            }
            if (\is_callable($onProgress)) {
                $onProgress($downloaded, $size, $info);
            }
        };
        $requested = (float) ($options['max_duration'] ?? 0);
        $options['max_duration'] = $requested > 0 ? min($requested, 10.0) : 10.0;

        return $this->client->request($method, $url, $options);
    }
}
