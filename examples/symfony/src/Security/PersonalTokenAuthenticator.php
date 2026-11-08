<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Security;

use ExactApproval\Demo\DemoDatabase;
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

/** Local development credential. This is not an OAuth authorization server. */
final class PersonalTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly DemoDatabase $database, private readonly string $origin = 'http://localhost:8080') {}

    public function supports(Request $request): bool { return $request->headers->has('Authorization'); }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization', '');
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]{32,256})$/D', $header, $matches)) throw new CustomUserMessageAuthenticationException('Invalid personal token.');
        $grant = $this->database->tokenGrant($matches[1]);
        $row = $grant === null ? null : $this->database->user($grant['user_id']);
        $expired = ($grant['expires_at'] ?? null) !== null && (int) $grant['expires_at'] < time();
        if ($grant === null || !(bool) $grant['active'] || $expired || $row === null || !(bool) $row['enabled'] || $grant['tenant_id'] !== $row['tenant_id']) {
            throw new CustomUserMessageAuthenticationException('Invalid personal token.');
        }

        return new SelfValidatingPassport(new UserBadge($row['id'], static fn () => DemoUser::fromRow($row, $grant['id'])));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response { return null; }
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response { return $this->start($request, $exception); }
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        // RFC 9728: point MCP clients at the protected resource metadata, so they can find the authorization server.
        return new JsonResponse(['error' => 'authentication_required'], 401, ['WWW-Authenticate' => 'Bearer resource_metadata="'.$this->origin.'/.well-known/oauth-protected-resource"']);
    }
}
