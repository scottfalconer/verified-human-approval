<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use VerifiedHumanApproval\Demo\DemoKernel;
use Nyholm\Psr7\Response as PsrResponse;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;

/** Test adapter transports real HTTP messages through a fresh Symfony kernel per request. */
final readonly class SymfonyKernelHttpClient implements ClientInterface
{
    public function __construct(private string $runtime) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $kernel = new DemoKernel($this->runtime, 'test');
        $http = Request::create((string) $request->getUri(), $request->getMethod(), content: (string) $request->getBody());
        $http->headers->replace($request->getHeaders());
        $response = $kernel->handle($http);
        ob_start();
        try {
            $response->sendContent();
            $body = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $kernel->terminate($http, $response);
        $kernel->shutdown();

        return new PsrResponse($response->getStatusCode(), $response->headers->all(), $body);
    }
}
