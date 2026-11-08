<?php

declare(strict_types=1);

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\AI\Agent\Bridge\Mcp\ClientToolset;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

require dirname(__DIR__).'/vendor/autoload.php';

/** The personal development token authenticates the requester; it cannot approve an action. */
function approvalToolset(): ClientToolset
{
    $endpoint = getenv('VERIFIED_HUMAN_APPROVAL_MCP_URL') ?: 'http://localhost:8080/mcp';
    $parts = parse_url($endpoint);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        || !($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && $parts['host'] === 'localhost'))) {
        throw new InvalidArgumentException('Use an HTTPS MCP URL, or HTTP localhost for development.');
    }
    $token = getenv('VERIFIED_HUMAN_APPROVAL_TOKEN');
    if (!is_string($token) || $token === '') {
        throw new RuntimeException('Set VERIFIED_HUMAN_APPROVAL_TOKEN to your personal demo token.');
    }
    $factory = new Psr17Factory();
    $transport = new HttpTransport(
        endpoint: $endpoint,
        headers: ['Authorization' => 'Bearer '.$token],
        httpClient: new Psr18Client(HttpClient::create(['timeout' => 20, 'max_duration' => 30, 'max_redirects' => 0]), $factory, $factory),
        requestFactory: $factory,
        streamFactory: $factory,
    );
    return new ClientToolset('application', Client::builder()->build(), $transport);
}
