<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Mcp\Server\Authorization\AccessToken;
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

/** Personal development tokens; the host can replace this with its OAuth authenticator. */
final class AgentTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly Connection $connection, private readonly AccountProvider $accounts)
    {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        if (1 !== preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/D', (string) $request->headers->get('Authorization'), $matches)) {
            throw new CustomUserMessageAuthenticationException('Invalid personal agent token.');
        }
        $grant = $this->connection->fetchAssociative('SELECT * FROM app_connections WHERE token_hash = ?', [hash('sha256', $matches[1])]);
        if (false === $grant || null !== $grant['revoked_at'] || (int) $grant['expires_at'] <= time()) {
            throw new CustomUserMessageAuthenticationException('This agent connection is unavailable.');
        }
        $account = $this->accounts->loadById($grant['user_id']);
        $scopes = array_values(array_filter(explode(' ', $grant['scopes'])));
        $request->attributes->set(AccessToken::class, new AccessToken($scopes, [
            'sub' => $account->id(), 'grant_id' => $grant['id'], 'exp' => (int) $grant['expires_at'],
        ]));

        return new SelfValidatingPassport(new UserBadge($account->getUserIdentifier(), static fn (): Account => $account));
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
        return new JsonResponse(['error' => 'authentication_required', 'message' => 'Send a current personal agent token.'], 401, ['WWW-Authenticate' => 'Bearer realm="editorial"']);
    }
}
