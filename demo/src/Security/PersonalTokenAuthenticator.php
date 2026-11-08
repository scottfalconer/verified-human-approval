<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Security;

use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
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
use VerifiedHumanApproval\Demo\OAuth\OAuthServer;

/**
 * The stateless /mcp firewall: a personal token or an OAuth access token names a person and a
 * grant. Besides signing the person in, it hands the validated token to the PHP MCP SDK as the
 * `AccessToken` request attribute, so tools read scopes with `RequestContext::getAccessToken()`.
 */
final class PersonalTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly DemoDatabase $database, private readonly ProtectedResourceMetadata $metadata)
    {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization', '');
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]{32,256})$/D', $header, $matches)) {
            throw new CustomUserMessageAuthenticationException('Invalid personal token.');
        }
        $grant = $this->database->tokenGrant($matches[1]);
        $row = null === $grant ? null : $this->database->user($grant['user_id']);
        $expired = ($grant['expires_at'] ?? null) !== null && (int) $grant['expires_at'] < time();
        if (null === $grant || !(bool) $grant['active'] || $expired || null === $row || !(bool) $row['enabled'] || $grant['tenant_id'] !== $row['tenant_id']) {
            throw new CustomUserMessageAuthenticationException('Invalid personal token.');
        }
        $scopes = json_decode((string) $grant['scopes'], true, 8, \JSON_THROW_ON_ERROR);
        // The SDK's HTTP transports read this attribute and expose it to tools as RequestContext::getAccessToken().
        $request->attributes->set(AccessToken::class, new AccessToken(
            \is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            array_filter(['sub' => $row['id'], 'client_id' => $grant['client_id'] ?? null, 'grant_id' => $grant['id']]),
        ));

        return new SelfValidatingPassport(new UserBadge($row['id'], static fn () => DemoUser::fromRow($row, $grant['id'])));
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
        // RFC 9728: point MCP clients at the protected resource metadata, so they can find the authorization
        // server, and name the scopes an agent needs (the MCP authorization spec's SHOULD).
        $challenge = \sprintf('Bearer resource_metadata="%s", scope="%s"', $this->metadata->getMetadataUrl(), implode(' ', OAuthServer::SCOPES));

        return new JsonResponse(['error' => 'authentication_required'], 401, ['WWW-Authenticate' => $challenge]);
    }
}
