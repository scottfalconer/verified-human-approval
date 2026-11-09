<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Security;

use Doctrine\ORM\EntityManagerInterface;
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
use VerifiedHumanApproval\Example\Publishing\Entity\AgentConnection;
use VerifiedHumanApproval\Example\Publishing\Entity\User;

/**
 * EXAMPLE-GRADE: the stateless /mcp firewall. A personal bearer token names one person and one agent connection,
 * checked against the stored connection on every request. It hands the validated token to the MCP SDK as the
 * AccessToken request attribute, so tools read its scopes, and the actor provider reads the connection's id from it.
 *
 * It signs the agent in as its person, for asking only: the review pages need the person's own browser session,
 * and only their passkey approves. Replace it with your application's OAuth or token service.
 */
final class AgentTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly EntityManagerInterface $entities)
    {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        if (1 !== preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/D', (string) $request->headers->get('Authorization'), $match)) {
            throw new CustomUserMessageAuthenticationException('Invalid agent token.');
        }
        $connection = $this->entities->getRepository(AgentConnection::class)->findOneBy(['tokenHash' => hash('sha256', $match[1])]);
        $user = null === $connection ? null : $this->entities->find(User::class, $connection->userId());
        if (null === $connection || !$connection->isActive() || null === $user) {
            throw new CustomUserMessageAuthenticationException('Invalid agent token.');
        }
        $request->attributes->set(AccessToken::class, new AccessToken($connection->scopes(), [
            'sub' => $user->getUserIdentifier(), 'grant_id' => $connection->id(),
        ]));

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): User => $user));
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
        return new JsonResponse(['error' => 'authentication_required', 'message' => 'Send your agent token: Authorization: Bearer <token>.'], 401, ['WWW-Authenticate' => 'Bearer realm="newsroom"']);
    }
}
