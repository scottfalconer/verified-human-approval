<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Security;

use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Mcp\Server\Authorization\AccessToken;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\OAuth\OAuthConnections;

/**
 * The stateless /mcp firewall. An agent presents a personal token from setup, or an access token from the OAuth
 * server (league/oauth2-server, which validates it here). Either names a person and an agent connection, whose
 * id becomes the approval's delegation; this class only maps the credential to them. It also hands the SDK the
 * `AccessToken` request attribute, so tools read scopes with `RequestContext::getAccessToken()`. On a 401,
 * omouren/mcp-oauth-bundle adds the WWW-Authenticate challenge that points clients at the OAuth metadata.
 */
final class AgentTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly DemoDatabase $database,
        private readonly ResourceServer $resourceServer,
        private readonly OAuthConnections $connections,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization') ?? '';
        if (!preg_match('/^Bearer ([A-Za-z0-9_.-]{32,4096})$/D', $header, $matches)) {
            throw new CustomUserMessageAuthenticationException('Invalid token.');
        }
        [$grant, $scopes] = str_contains($matches[1], '.') ? $this->oauth($request) : $this->personal($matches[1]);
        $row = $this->database->user((string) $grant['user_id']);
        if (null === $row || !(bool) $row['enabled'] || $grant['tenant_id'] !== $row['tenant_id']) {
            throw new CustomUserMessageAuthenticationException('Invalid token.');
        }
        // The SDK's HTTP transports read this attribute and expose it to tools as RequestContext::getAccessToken().
        $request->attributes->set(AccessToken::class, new AccessToken($scopes, array_filter(['sub' => $row['id'], 'client_id' => $grant['client_id'] ?? null, 'grant_id' => $grant['id']])));

        return new SelfValidatingPassport(new UserBadge($row['id'], static fn () => DemoUser::fromRow($row, (string) $grant['id'])));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->start($request, $exception);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'authentication_required'], 401);
    }

    /** @return array{array<string, mixed>, list<string>} the connection, and the token's scopes it still allows */
    private function oauth(Request $request): array
    {
        $factory = new Psr17Factory();
        try {
            $validated = $this->resourceServer->validateAuthenticatedRequest((new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest($request));
        } catch (OAuthServerException) {
            throw new CustomUserMessageAuthenticationException('Invalid token.');
        }
        $userId = $validated->getAttribute('oauth_user_id');
        $clientId = $validated->getAttribute('oauth_client_id');
        $tokenId = $validated->getAttribute('oauth_access_token_id');
        $grant = \is_string($clientId) && \is_string($tokenId) ? $this->connections->forAccessToken($tokenId, \is_string($userId) ? $userId : null, $clientId) : null;
        if (null === $grant) {
            throw new CustomUserMessageAuthenticationException('This connection was revoked or has ended. Connect again.');
        }
        $tokenScopes = $validated->getAttribute('oauth_scopes', []);
        $allowed = json_decode((string) $grant['scopes'], true, 8, \JSON_THROW_ON_ERROR);

        return [$grant, array_values(array_intersect(\is_array($tokenScopes) ? $tokenScopes : [], \is_array($allowed) ? $allowed : []))];
    }

    /** @return array{array<string, mixed>, list<string>} */
    private function personal(string $token): array
    {
        $grant = $this->database->tokenGrant($token);
        $expired = ($grant['expires_at'] ?? null) !== null && (int) $grant['expires_at'] <= time();
        if (null === $grant || !(bool) $grant['active'] || $expired) {
            throw new CustomUserMessageAuthenticationException('Invalid token.');
        }
        $scopes = json_decode((string) $grant['scopes'], true, 8, \JSON_THROW_ON_ERROR);

        return [$grant, \is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : []];
    }
}
