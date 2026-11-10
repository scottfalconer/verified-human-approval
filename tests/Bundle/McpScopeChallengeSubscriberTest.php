<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Mcp\Server\Transport\StreamableHttpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\Controller\McpController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use VerifiedHumanApproval\Bundle\Security\McpScopeChallengeSubscriber;

/** The subscriber on its own. ScopeAuthorizationTest runs it in the demo, behind a firewall and in front of the SDK. */
final class McpScopeChallengeSubscriberTest extends TestCase
{
    private const TOOLS = ['publish_article' => ['articles.publish', 'approval.read'], 'approval_status' => ['approval.read']];
    private const LACKS_SCOPE = 'The access token lacks a required scope.';

    public function testItRunsOnceTheControllerIsKnown(): void
    {
        self::assertSame([KernelEvents::CONTROLLER => 'onController'], McpScopeChallengeSubscriber::getSubscribedEvents());
    }

    public function testATokenWithoutEveryScopeOfTheToolIsChallengedForAllOfThem(): void
    {
        $request = self::post(self::call('publish_article'), new AccessToken(['articles.publish']));
        $response = $this->answer(new ScopePolicy(tools: self::TOOLS), $request);

        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Bearer scope="articles.publish approval.read", error="insufficient_scope", error_description="'.self::LACKS_SCOPE.'"', $response->headers->get('WWW-Authenticate'));
        self::assertSame(
            ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => self::LACKS_SCOPE.' This operation needs the scope "articles.publish approval.read".']],
            self::json($response),
            'A JSON-RPC error, as the transport\'s own refusals are: its message is what a client that cannot ask for more shows.',
        );
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testTheChallengeNamesTheResourceMetadataTheApplicationPublishes(): void
    {
        $metadata = new ProtectedResourceMetadata('https://newsroom.example/mcp', ['https://id.newsroom.example']);
        $response = $this->answer(new ScopePolicy(tools: self::TOOLS), self::post(self::call('approval_status'), new AccessToken()), $metadata);

        self::assertNotNull($response);
        self::assertSame(
            'Bearer resource_metadata="https://newsroom.example/.well-known/oauth-protected-resource/mcp", scope="approval.read", error="insufficient_scope", error_description="'.self::LACKS_SCOPE.'"',
            $response->headers->get('WWW-Authenticate'),
            'The same parameters in the same order as the SDK\'s AuthorizationMiddleware.',
        );
    }

    public function testAQuoteInAValueCannotEndItsParameter(): void
    {
        $metadata = new ProtectedResourceMetadata('https://newsroom.example/a"b\c', ['https://id.newsroom.example']);
        $response = $this->answer(new ScopePolicy(tools: self::TOOLS), self::post(self::call('approval_status'), new AccessToken()), $metadata);

        self::assertNotNull($response);
        self::assertStringStartsWith('Bearer resource_metadata="https://newsroom.example/.well-known/oauth-protected-resource/a\"b\\\\c", scope="approval.read", ', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function testATokenWithEnoughScopePassesAndGainsTheScopesThePolicySaysItImplies(): void
    {
        $policy = new ScopePolicy(tools: self::TOOLS, implies: ['articles.admin' => ['articles.publish']]);
        $request = self::post(self::call('publish_article'), new AccessToken(['articles.admin', 'approval.read'], ['sub' => 'ada', 'grant_id' => 'grant-7']));

        self::assertNull($this->answer($policy, $request), 'The MCP controller is left in place.');
        $token = $request->attributes->get(AccessToken::class);
        self::assertInstanceOf(AccessToken::class, $token);
        self::assertTrue($token->hasScope('articles.publish'), 'A tool that asks hasScope() agrees with the policy that let the call through.');
        self::assertEqualsCanonicalizing(['articles.admin', 'articles.publish', 'approval.read'], $token->getScopes());
        self::assertSame(['sub' => 'ada', 'grant_id' => 'grant-7'], $token->getClaims(), 'The claims the application reads are kept.');
    }

    public function testARequestWithoutATokenIsChallengedOnlyWhereTheOperationNeedsAScope(): void
    {
        $policy = new ScopePolicy(tools: self::TOOLS);
        self::assertNull($this->answer($policy, self::post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])), 'An application may serve some operations without credentials.');

        $response = $this->answer($policy, self::post(self::call('publish_article')));
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer scope="articles.publish approval.read"', $response->headers->get('WWW-Authenticate'), 'No credentials were presented, so no error code is given (RFC 6750 §3.1).');
        self::assertSame('This operation needs an access token with the scope "articles.publish approval.read".', self::json($response)['error']['message']);

        $request = self::post(self::call('publish_article'));
        $request->attributes->set(AccessToken::class, ['scopes' => ['articles.publish', 'approval.read']]);
        self::assertSame(401, $this->answer($policy, $request)?->getStatusCode(), 'Only a validated AccessToken counts as one.');
    }

    public function testABatchNeedsEveryScopeOfEveryCallInIt(): void
    {
        $request = self::post([self::call('approval_status'), self::call('publish_article')], new AccessToken(['approval.read']));
        $response = $this->answer(new ScopePolicy(tools: self::TOOLS), $request);

        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('approval.read articles.publish', self::scope($response));
    }

    public function testTheBodyDecidesWhichToolIsCalledNotAHeader(): void
    {
        $request = self::post(self::call('publish_article'), new AccessToken(['approval.read']));
        $request->headers->set('Mcp-Name', 'approval_status');

        self::assertSame(403, $this->answer(new ScopePolicy(tools: self::TOOLS), $request)?->getStatusCode());
    }

    public function testEveryRequestNeedsTheDefaultScopesAndAnUnnamedToolNeedsNoMore(): void
    {
        $policy = new ScopePolicy(default: ['mcp.connect'], tools: self::TOOLS);
        $connected = new AccessToken(['mcp.connect']);
        self::assertNull($this->answer($policy, self::post(self::call('search_articles'), $connected)), 'A tool the policy does not name.');
        self::assertNull($this->answer($policy, self::post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $connected)));
        self::assertNull($this->answer($policy, self::request('GET', '', $connected)));

        foreach (['GET', 'DELETE', 'POST'] as $method) {
            $response = $this->answer($policy, self::request($method, 'POST' === $method ? '{"jsonrpc":"2.0","id":1,"method":"ping"}' : '', new AccessToken(['approval.read'])));
            self::assertSame(403, $response?->getStatusCode(), $method);
            self::assertSame('mcp.connect', self::scope($response));
        }
        self::assertSame('mcp.connect articles.publish approval.read', self::scope($this->answer($policy, self::post(self::call('publish_article'), $connected))));
    }

    public function testABodyTooLargeToInspectNeverReachesTheServer(): void
    {
        $policy = new ScopePolicy(tools: self::TOOLS);
        $everything = new AccessToken(['articles.publish', 'approval.read']);
        $call = json_encode(self::call('publish_article'), \JSON_THROW_ON_ERROR);
        // Leading whitespace keeps the body valid JSON at any length.
        $atTheLimit = str_repeat(' ', StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES - \strlen($call)).$call;

        self::assertNull($this->answer($policy, self::request('POST', $atTheLimit, $everything)), 'A body the transport accepts is inspected like any other.');
        self::assertSame(403, $this->answer($policy, self::request('POST', $atTheLimit, new AccessToken(['approval.read'])))?->getStatusCode());

        $response = $this->answer($policy, self::request('POST', ' '.$atTheLimit, $everything));
        self::assertNotNull($response);
        self::assertSame(413, $response->getStatusCode(), 'The status the transport gives a body over its limit.');
        self::assertFalse($response->headers->has('WWW-Authenticate'), 'More scope would not help.');
        self::assertSame('Request body exceeds the maximum allowed size of 4194304 bytes.', self::json($response)['error']['message'], 'The transport\'s own sentence.');

        self::assertNull($this->answer(new ScopePolicy(default: ['mcp.connect']), self::request('POST', ' '.$atTheLimit, new AccessToken(['mcp.connect']))), 'A policy that does not read the body leaves its size to the transport.');
    }

    public function testTheServerStillReadsTheWholeBodyAfterwards(): void
    {
        $policy = new ScopePolicy(tools: self::TOOLS);
        $everything = new AccessToken(['articles.publish', 'approval.read']);
        $body = json_encode(self::call('publish_article'), \JSON_THROW_ON_ERROR);

        $request = self::request('POST', $body, $everything);
        self::assertNull($this->answer($policy, $request));
        self::assertSame($body, stream_get_contents($request->getContent(true)), 'The bridge to the SDK reads the body as a stream.');
        self::assertSame($body, $request->getContent());

        // A body that arrives as a stream, as a real request's does.
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $body);
        $streamed = Request::create('https://newsroom.example/mcp', 'POST', content: $stream);
        $streamed->attributes->set(AccessToken::class, new AccessToken(['approval.read']));
        self::assertSame(403, $this->answer($policy, $streamed)?->getStatusCode(), 'It is inspected from its start, wherever the stream stood.');
        $streamed->attributes->set(AccessToken::class, $everything);
        self::assertNull($this->answer($policy, $streamed));
        self::assertSame($body, stream_get_contents($streamed->getContent(true)));
    }

    public function testABodyThatIsNotAJsonRpcMessageIsLeftForTheServerToReject(): void
    {
        $policy = new ScopePolicy(tools: self::TOOLS);
        foreach (['', 'publish_article', '"publish_article"', '{"method":"tools/call","params":"publish_article"}', '{"method":"tools/call","params":{"name":["publish_article"]}}'] as $body) {
            self::assertNull($this->answer($policy, self::request('POST', $body, new AccessToken())), $body);
        }
    }

    public function testOtherControllersAndPreflightsAreLeftAlone(): void
    {
        $policy = new ScopePolicy(default: ['mcp.connect'], tools: self::TOOLS);
        $closure = static fn (): Response => new Response('An application route that happens to receive JSON-RPC.');
        self::assertNull($this->answer($policy, self::post(self::call('publish_article')), controller: $closure));
        self::assertNull($this->answer($policy, self::post(self::call('publish_article')), controller: [new \ArrayObject(), 'count']));
        self::assertNull($this->answer($policy, self::request('OPTIONS', '')), 'A CORS preflight carries no credentials.');
    }

    /** The response the subscriber answers with, or null when it leaves the controller in place. */
    private function answer(ScopePolicy $policy, Request $request, ?ProtectedResourceMetadata $metadata = null, ?callable $controller = null): ?Response
    {
        // The MCP bundle's controller is final and is never called here: only its identity matters.
        $controller ??= [(new \ReflectionClass(McpController::class))->newInstanceWithoutConstructor(), 'handle'];
        $event = new ControllerEvent($this->createStub(HttpKernelInterface::class), $controller, $request, HttpKernelInterface::MAIN_REQUEST);
        (new McpScopeChallengeSubscriber($policy, $metadata))->onController($event);

        return $controller === $event->getController() ? null : ($event->getController())();
    }

    /** @param array<mixed> $payload */
    private static function post(array $payload, ?AccessToken $token = null): Request
    {
        return self::request('POST', json_encode($payload, \JSON_THROW_ON_ERROR), $token);
    }

    private static function request(string $method, string $body, ?AccessToken $token = null): Request
    {
        $request = Request::create('https://newsroom.example/mcp', $method, content: $body);
        if (null !== $token) {
            $request->attributes->set(AccessToken::class, $token);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private static function call(string $tool): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => new \stdClass()]];
    }

    /** @return array{jsonrpc: string, error: array{code: int, message: string}} */
    private static function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 8, \JSON_THROW_ON_ERROR);
    }

    /** The scopes a challenge names. */
    private static function scope(?Response $response): ?string
    {
        return 1 === preg_match('/(?:^Bearer |, )scope="([^"]*)"/', (string) $response?->headers->get('WWW-Authenticate'), $match) ? $match[1] : null;
    }
}
