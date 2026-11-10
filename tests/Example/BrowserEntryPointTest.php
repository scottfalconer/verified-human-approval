<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Example;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use VerifiedHumanApproval\Example\Publishing\Security\BrowserEntryPoint;

final class BrowserEntryPointTest extends TestCase
{
    public function testTheHostExplicitlyIdentifiesMissingAuthenticationForJsonRequests(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::never())->method('generate');
        $request = Request::create('/approval/existing/options', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = (new BrowserEntryPoint($urls))->start($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['status' => 'authentication_required'], json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->has('Location'));
    }

    public function testOrdinaryNavigationStillUsesTheHostsLoginRoute(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::once())->method('generate')->with('login')->willReturn('/sign-in');
        $response = (new BrowserEntryPoint($urls))->start(Request::create('/approval/existing', server: ['HTTP_ACCEPT' => 'text/html']));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/sign-in', $response->headers->get('Location'));
    }
}
