<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Testing;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * A PSR-18 client that answers each request with your application's kernel, inside the test's process.
 *
 * Give it to an MCP client (the SDK's HttpTransport takes a PSR-18 client), and the test calls your tools through
 * the real endpoint: the firewall, the token check, the MCP server and the tool, with no web server and no port.
 * Each request gets a new kernel, as each request to a web server gets a new process, so nothing a tool keeps in
 * memory survives to the next call.
 *
 * It carries the request's headers and body, which is what a client with a bearer token sends. It keeps no cookies:
 * for the pages a person uses, take a BrowserKit browser.
 */
final readonly class KernelHttpClient implements ClientInterface
{
    /**
     * @param \Closure(): HttpKernelInterface $kernel    a new kernel for each request
     * @param ResponseFactoryInterface        $responses a PSR-17 factory, such as nyholm/psr7's Psr17Factory
     * @param StreamFactoryInterface          $streams   the same factory will do
     */
    public function __construct(
        private \Closure $kernel,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $kernel = ($this->kernel)();
        try {
            $http = Request::create((string) $request->getUri(), $request->getMethod(), content: (string) $request->getBody());
            $http->headers->replace($request->getHeaders());
            $response = $kernel->handle($http);

            // A streamed answer (server-sent events) flushes as it goes: the handler keeps what it flushes, and
            // returns nothing, so none of it reaches the test's own output.
            $body = '';
            $level = ob_get_level();
            ob_start(static function (string $flushed, int $phase) use (&$body): string {
                if (0 === ($phase & \PHP_OUTPUT_HANDLER_CLEAN)) {
                    $body .= $flushed;
                }

                return '';
            });
            try {
                $response->sendContent();
                // A response may have opened a buffer of its own. Flush it into ours before reading the tail.
                while (ob_get_level() > $level + 1) {
                    ob_end_flush();
                }
                $body .= ob_get_contents();
            } finally {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
            }

            if ($kernel instanceof TerminableInterface) {
                $kernel->terminate($http, $response);
            }

            $answer = $this->responses->createResponse($response->getStatusCode());
            foreach ($response->headers->all() as $name => $values) {
                $values = array_values(array_filter($values, \is_string(...)));
                if ([] !== $values) {
                    $answer = $answer->withHeader((string) $name, $values);
                }
            }

            return $answer->withBody($this->streams->createStream($body));
        } finally {
            if ($kernel instanceof KernelInterface) {
                $kernel->shutdown();
            }
        }
    }
}
