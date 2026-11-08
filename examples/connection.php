<?php

declare(strict_types=1);

use Mcp\Client;
use Mcp\Client\Handler\Request\ElicitationRequestHandler;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\ClientCapabilities;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\AI\Agent\Bridge\Mcp\ClientToolset;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/PrintReviewLink.php';

/** The personal development token authenticates the requester; it cannot approve an action. */
function approvalToolset(): ClientToolset
{
    $endpoint = getenv('VERIFIED_HUMAN_APPROVAL_MCP_URL') ?: 'http://localhost:8080/mcp';
    $parts = parse_url($endpoint);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        || !('https' === $parts['scheme'] || ('http' === $parts['scheme'] && 'localhost' === $parts['host']))) {
        throw new InvalidArgumentException('Use an HTTPS MCP URL, or HTTP localhost for development.');
    }
    $token = getenv('VERIFIED_HUMAN_APPROVAL_TOKEN');
    if (!is_string($token) || '' === $token) {
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

    // The client names itself, and takes URL-mode prompts: it prints the review link. Printing is not
    // approving: the person approves on the site with their passkey, and the server reports what it recorded.
    $client = Client::builder()
        ->setClientInfo('verified-human-approval-example', '0.1.0')
        ->setCapabilities(new ClientCapabilities(elicitation: true, elicitationUrl: true))
        ->addRequestHandler(new ElicitationRequestHandler(new PrintReviewLink()))
        ->build();

    return new ClientToolset('application', $client, $transport);
}
