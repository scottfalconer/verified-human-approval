<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/** Schedules overlaps and storage failures around the real League repositories, without replacing their checks. */
final class OAuthGrantHooks
{
    public static function afterCredentialRead(AuthorizationServer $server, string $grantType, \Closure $hook): void
    {
        $grant = self::grant($server, $grantType);
        if ($grant instanceof AuthCodeGrant) {
            $repository = (new \ReflectionProperty($grant, 'authCodeRepository'))->getValue($grant);
            $grant->setAuthCodeRepository(new class($repository, $hook) implements AuthCodeRepositoryInterface {
                public function __construct(private readonly AuthCodeRepositoryInterface $inner, private readonly \Closure $hook)
                {
                }

                public function getNewAuthCode(): AuthCodeEntityInterface
                {
                    return $this->inner->getNewAuthCode();
                }

                public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
                {
                    $this->inner->persistNewAuthCode($authCodeEntity);
                }

                public function revokeAuthCode(string $codeId): void
                {
                    $this->inner->revokeAuthCode($codeId);
                }

                public function isAuthCodeRevoked(string $codeId): bool
                {
                    $revoked = $this->inner->isAuthCodeRevoked($codeId);
                    ($this->hook)($revoked);

                    return $revoked;
                }
            });

            return;
        }
        self::refreshRepository($grant, $hook, null);
    }

    public static function afterRefreshSave(AuthorizationServer $server, string $grantType, \Closure $hook): void
    {
        self::refreshRepository(self::grant($server, $grantType), null, $hook);
    }

    private static function grant(AuthorizationServer $server, string $grantType): AuthCodeGrant|RefreshTokenGrant
    {
        $grants = (new \ReflectionProperty($server, 'enabledGrantTypes'))->getValue($server);

        return $grants[$grantType];
    }

    private static function refreshRepository(AuthCodeGrant|RefreshTokenGrant $grant, ?\Closure $afterRead, ?\Closure $afterSave): void
    {
        $repository = (new \ReflectionProperty($grant, 'refreshTokenRepository'))->getValue($grant);
        $grant->setRefreshTokenRepository(new class($repository, $afterRead, $afterSave) implements RefreshTokenRepositoryInterface {
            public function __construct(private readonly RefreshTokenRepositoryInterface $inner, private readonly ?\Closure $afterRead, private readonly ?\Closure $afterSave)
            {
            }

            public function getNewRefreshToken(): ?RefreshTokenEntityInterface
            {
                return $this->inner->getNewRefreshToken();
            }

            public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
            {
                $this->inner->persistNewRefreshToken($refreshTokenEntity);
                ($this->afterSave ?? static function (): void {})();
            }

            public function revokeRefreshToken(string $tokenId): void
            {
                $this->inner->revokeRefreshToken($tokenId);
            }

            public function isRefreshTokenRevoked(string $tokenId): bool
            {
                $revoked = $this->inner->isRefreshTokenRevoked($tokenId);
                ($this->afterRead ?? static function (): void {})($revoked);

                return $revoked;
            }
        });
    }
}
