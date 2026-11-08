<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\WebAuthn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\WebAuthn\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testSecureOriginAndLocalhostDevelopmentAreExplicit(): void
    {
        self::assertSame('approval.test', (new Configuration('approval.test', 'https://approval.test'))->host);
        self::assertSame('app.approval.test', (new Configuration('approval.test', 'https://app.approval.test'))->host);
        self::assertSame('localhost', (new Configuration('localhost', 'http://localhost:8080'))->host);
    }

    #[DataProvider('invalidOrigins')]
    public function testUnsafeOrAmbiguousOriginConfigurationIsRejected(string $rpId, string $origin): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Configuration($rpId, $origin);
    }

    public static function invalidOrigins(): iterable
    {
        yield 'http deployment' => ['approval.test', 'http://approval.test'];
        yield 'wrong RP' => ['other.test', 'https://approval.test'];
        yield 'suffix collision' => ['approval.test', 'https://evilapproval.test'];
        yield 'path' => ['approval.test', 'https://approval.test/review'];
        yield 'query' => ['approval.test', 'https://approval.test?x=1'];
        yield 'credentials' => ['approval.test', 'https://user:password@approval.test'];
        yield 'username only' => ['approval.test', 'https://user@approval.test'];
        yield 'fragment' => ['approval.test', 'https://approval.test#review'];
        yield 'wildcard' => ['*.approval.test', 'https://approval.test'];
    }
}
