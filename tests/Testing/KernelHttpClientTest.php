<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Testing;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use VerifiedHumanApproval\Testing\KernelHttpClient;

/** The in-process HTTP client: what reaches the kernel, and what comes back, for a plain answer and a streamed one. */
final class KernelHttpClientTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        // Symfony keeps the hosts a kernel trusts for the whole process: an application booted by an earlier test
        // may have left its own behind, and these requests name other hosts.
        Request::setTrustedHosts([]);
    }

    public function testTheRequestReachesTheKernelAsTheClientSentIt(): void
    {
        $kernel = new RecordingKernel(static fn (): Response => new JsonResponse(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200, ['Mcp-Session-Id' => 'session-1']));
        $client = new KernelHttpClient(static fn () => $kernel, $this->factory, $this->factory);
        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';

        $response = $client->sendRequest(
            $this->factory->createRequest('POST', 'https://app.test/mcp?trace=1')
                ->withHeader('Authorization', 'Bearer token-1')
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Accept', ['application/json', 'text/event-stream'])
                ->withHeader('Mcp-Session-Id', 'session-0')
                ->withBody($this->factory->createStream($body)),
        );

        self::assertCount(1, $kernel->handled);
        $handled = $kernel->handled[0];
        self::assertSame('POST', $handled->getMethod());
        self::assertSame('https://app.test/mcp?trace=1', $handled->getUri());
        self::assertTrue($handled->isSecure());
        self::assertSame('app.test', $handled->getHost());
        self::assertSame('/mcp', $handled->getPathInfo());
        self::assertSame('1', $handled->query->get('trace'));
        self::assertSame($body, $handled->getContent());
        self::assertSame('Bearer token-1', $handled->headers->get('Authorization'));
        self::assertSame('application/json', $handled->headers->get('Content-Type'));
        self::assertSame(['application/json', 'text/event-stream'], $handled->headers->all('Accept'));
        self::assertSame('session-0', $handled->headers->get('Mcp-Session-Id'));
        self::assertSame([], $handled->request->all(), 'A JSON body is not read as form fields.');
        self::assertSame([], $handled->cookies->all(), 'The client keeps no cookies.');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('session-1', $response->getHeaderLine('Mcp-Session-Id'));
        self::assertSame('{"jsonrpc":"2.0","id":1,"result":[]}', (string) $response->getBody());
    }

    public function testOnlyTheHeadersTheClientSentReachTheKernel(): void
    {
        $kernel = new RecordingKernel(static fn (): Response => new Response('', 202));
        $client = new KernelHttpClient(static fn () => $kernel, $this->factory, $this->factory);

        $response = $client->sendRequest($this->factory->createRequest('DELETE', 'http://localhost:8080/mcp')->withHeader('Mcp-Session-Id', 'session-1'));

        $handled = $kernel->handled[0];
        self::assertSame('DELETE', $handled->getMethod());
        self::assertSame('http://localhost:8080/mcp', $handled->getUri());
        self::assertSame(8080, $handled->getPort());
        self::assertFalse($handled->headers->has('Authorization'));
        self::assertFalse($handled->headers->has('Content-Type'), 'No content type is made up for a request that named none.');
        self::assertFalse($handled->headers->has('User-Agent'));
        self::assertSame('', $handled->getContent());
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testTheAnswersHeadersComeBackWithEveryValue(): void
    {
        $kernel = new RecordingKernel(static function (): Response {
            $response = new Response('Not allowed.', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
            $response->headers->set('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="alert.publish"');
            $response->headers->set('Link', ['</a>; rel="first"', '</b>; rel="second"']);
            $response->headers->set('X-Nothing', null);
            $response->headers->setCookie(Cookie::create('session', 'abc'));

            return $response;
        });
        $client = new KernelHttpClient(static fn () => $kernel, $this->factory, $this->factory);

        $response = $client->sendRequest($this->get('https://app.test/mcp'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Not allowed.', (string) $response->getBody());
        self::assertSame('text/plain; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('Bearer error="insufficient_scope", scope="alert.publish"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(['</a>; rel="first"', '</b>; rel="second"'], $response->getHeader('Link'));
        self::assertFalse($response->hasHeader('X-Nothing'), 'A header without a value is no header.');
        self::assertCount(1, $response->getHeader('Set-Cookie'));
        self::assertStringStartsWith('session=abc', $response->getHeader('Set-Cookie')[0]);
    }

    public function testAStreamedAnswerComesBackWholeAndNoneOfItReachesTheTestsOutput(): void
    {
        $this->expectOutputString('');
        // What the SDK's server does for each server-sent event: print it, then flush every buffer.
        $kernel = new RecordingKernel(static fn (): Response => new StreamedResponse(static function (): void {
            foreach (['one', 'two', 'three'] as $event) {
                echo "event: message\ndata: ".$event."\n\n";
                @ob_flush();
                flush();
            }
            echo ': done';
        }, 200, ['Content-Type' => 'text/event-stream']));
        $client = new KernelHttpClient(static fn () => $kernel, $this->factory, $this->factory);
        $level = ob_get_level();

        $response = $client->sendRequest($this->get('https://app.test/mcp'));

        self::assertSame("event: message\ndata: one\n\nevent: message\ndata: two\n\nevent: message\ndata: three\n\n: done", (string) $response->getBody(), 'Each flushed event once, in order, then what was never flushed.');
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame($level, ob_get_level(), 'The client closed the one buffer it opened.');
    }

    public function testAnAnswerThatBuffersItsOwnOutputIsStillCaughtOnce(): void
    {
        $this->expectOutputString('');
        $kernel = new RecordingKernel(static fn (): Response => new StreamedResponse(static function (): void {
            echo 'before,';
            ob_start();
            echo 'inside,';
            ob_end_flush();
            ob_start();
            echo 'discarded,';
            ob_end_clean();
            echo 'after';
        }));
        $client = new KernelHttpClient(static fn () => $kernel, $this->factory, $this->factory);

        self::assertSame('before,inside,after', (string) $client->sendRequest($this->get('https://app.test/stream'))->getBody());
    }

    public function testAnAnswerThatLeavesABufferOpenDoesNotLeakItIntoTheNextRequest(): void
    {
        $this->expectOutputString('');
        $kernel = new RecordingKernel(static fn (): Response => new StreamedResponse(static function (): void {
            echo 'before,';
            ob_start();
            echo 'inside';
        }));
        $client = new KernelHttpClient(static fn () => $kernel, $this->factory, $this->factory);
        $level = ob_get_level();

        self::assertSame('before,inside', (string) $client->sendRequest($this->get('https://app.test/stream'))->getBody());
        self::assertSame($level, ob_get_level());
    }

    public function testAnAnswerThatFailsWhileItIsSentLeavesNoBufferOpen(): void
    {
        $this->expectOutputString('');
        $kernel = new RecordingKernel(static fn (): Response => new StreamedResponse(static function (): void {
            echo 'partial';
            @ob_flush();
            ob_start();
            echo 'unflushed';
            throw new \DomainException('The stream broke.');
        }));
        $client = new KernelHttpClient(static fn () => $kernel, $this->factory, $this->factory);
        $level = ob_get_level();

        $thrown = null;
        try {
            $client->sendRequest($this->get('https://app.test/stream'));
        } catch (\DomainException $thrown) {
        }

        self::assertSame('The stream broke.', $thrown?->getMessage(), 'The failure is the test\'s to see.');
        self::assertSame($level, ob_get_level());
    }

    public function testAKernelIsShutDownWhenHandlingTheRequestFails(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects(self::once())->method('handle')->willThrowException(new \DomainException('The request broke.'));
        $kernel->expects(self::once())->method('shutdown');
        $client = new KernelHttpClient(static fn () => $kernel, $this->factory, $this->factory);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('The request broke.');
        $client->sendRequest($this->get('https://app.test/mcp'));
    }

    public function testAKernelIsShutDownWhenSendingTheResponseFails(): void
    {
        $kernel = $this->createMockForIntersectionOfInterfaces([KernelInterface::class, TerminableInterface::class]);
        $kernel->expects(self::once())->method('handle')->willReturn(new StreamedResponse(static function (): void {
            throw new \DomainException('The stream broke.');
        }));
        $kernel->expects(self::never())->method('terminate');
        $kernel->expects(self::once())->method('shutdown');
        $client = new KernelHttpClient(static fn () => $kernel, $this->factory, $this->factory);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('The stream broke.');
        $client->sendRequest($this->get('https://app.test/mcp'));
    }

    public function testAKernelIsShutDownWhenTerminationFails(): void
    {
        $kernel = $this->createMockForIntersectionOfInterfaces([KernelInterface::class, TerminableInterface::class]);
        $kernel->expects(self::once())->method('handle')->willReturn(new Response('done'));
        $kernel->expects(self::once())->method('terminate')->willThrowException(new \DomainException('Termination broke.'));
        $kernel->expects(self::once())->method('shutdown');
        $client = new KernelHttpClient(static fn () => $kernel, $this->factory, $this->factory);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Termination broke.');
        $client->sendRequest($this->get('https://app.test/mcp'));
    }

    public function testEachRequestGetsAKernelOfItsOwn(): void
    {
        $kernels = [];
        $client = new KernelHttpClient(static function () use (&$kernels): RecordingKernel {
            return $kernels[] = new RecordingKernel(static fn (Request $request): Response => new Response($request->getPathInfo()));
        }, $this->factory, $this->factory);

        self::assertSame([], $kernels, 'No kernel is made before a request needs one.');
        self::assertSame('/first', (string) $client->sendRequest($this->get('https://app.test/first'))->getBody());
        self::assertSame('/second', (string) $client->sendRequest($this->get('https://app.test/second'))->getBody());

        self::assertCount(2, $kernels);
        self::assertNotSame($kernels[0], $kernels[1]);
        self::assertSame(['handle', 'terminate'], $kernels[0]->log);
        self::assertSame(['handle', 'terminate'], $kernels[1]->log);
    }

    public function testTheKernelIsTerminatedAndShutDownOnceTheAnswerWasSent(): void
    {
        $log = [];
        $kernel = $this->createMockForIntersectionOfInterfaces([KernelInterface::class, TerminableInterface::class]);
        $kernel->expects(self::once())->method('handle')->willReturnCallback(static function () use (&$log): Response {
            $log[] = 'handle';

            return new StreamedResponse(static function () use (&$log): void {
                $log[] = 'content';
                echo 'sent';
            });
        });
        $kernel->expects(self::once())->method('terminate')->willReturnCallback(static function (Request $request, Response $response) use (&$log): void {
            $log[] = 'terminate '.$request->getPathInfo().' '.$response->getStatusCode();
        });
        $kernel->expects(self::once())->method('shutdown')->willReturnCallback(static function () use (&$log): void {
            $log[] = 'shutdown';
        });
        $client = new KernelHttpClient(static fn () => $kernel, $this->factory, $this->factory);

        $response = $client->sendRequest($this->get('https://app.test/mcp'));

        self::assertSame('sent', (string) $response->getBody());
        self::assertSame(['handle', 'content', 'terminate /mcp 200', 'shutdown'], $log, 'As a web server does it: the answer goes out, then the kernel finishes its work and is let go.');
    }

    public function testAKernelThatOnlyHandlesRequestsIsEnough(): void
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response('handled '.$request->getMethod());
            }
        };
        $client = new KernelHttpClient(static fn () => $kernel, $this->factory, $this->factory);

        self::assertSame('handled GET', (string) $client->sendRequest($this->get('https://app.test/'))->getBody());
    }

    private function get(string $address): RequestInterface
    {
        return $this->factory->createRequest('GET', $address);
    }
}
