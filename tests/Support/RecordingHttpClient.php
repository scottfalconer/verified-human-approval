<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Records what passed over the wire: the JSON-RPC method, the protocol and session headers, and the response. */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<array{method: ?string, protocol: string, session: string, status: int, contentType: string, body: string}> */
    public array $exchanges = [];

    public function __construct(private readonly ClientInterface $inner) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);
        $sent = json_decode((string) $request->getBody(), true);
        $this->exchanges[] = [
            'method' => \is_array($sent) ? ($sent['method'] ?? null) : null,
            'protocol' => $request->getHeaderLine('MCP-Protocol-Version'),
            'session' => $request->getHeaderLine('Mcp-Session-Id'),
            'status' => $response->getStatusCode(),
            'contentType' => $response->getHeaderLine('Content-Type'),
            'body' => (string) $response->getBody(),
        ];
        $response->getBody()->rewind();

        return $response;
    }

    /** @return list<array{method: ?string, protocol: string, session: string, status: int, contentType: string, body: string}> */
    public function of(string $method): array
    {
        return array_values(array_filter($this->exchanges, static fn (array $exchange): bool => $exchange['method'] === $method));
    }

    /** The JSON-RPC messages a response body carried, whether it was plain JSON or an event stream. */
    public static function messages(array $exchange): array
    {
        if (str_contains($exchange['contentType'], 'text/event-stream')) {
            preg_match_all('/^data: (.+)$/m', $exchange['body'], $matches);

            return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
        }
        $decoded = json_decode($exchange['body'], true, 512, JSON_THROW_ON_ERROR);

        return array_is_list($decoded) ? $decoded : [$decoded];
    }
}
