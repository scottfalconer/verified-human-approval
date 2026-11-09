<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Example;

use Nyholm\Psr7\Response as PsrResponse;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

/** Sends an MCP client's HTTP messages through a fresh kernel of the example per request, as a web server would. */
final readonly class KernelHttpClient implements ClientInterface
{
    /** @param \Closure(): KernelInterface $kernel */
    public function __construct(private \Closure $kernel)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $kernel = ($this->kernel)();
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
        if ($kernel instanceof \Symfony\Component\HttpKernel\TerminableInterface) {
            $kernel->terminate($http, $response);
        }
        $kernel->shutdown();

        return new PsrResponse($response->getStatusCode(), $response->headers->all(), $body);
    }
}
