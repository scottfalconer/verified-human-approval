<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Controller;

use ExactApproval\Demo\DemoDatabase;
use ExactApproval\Demo\OAuth\ClientRegistry;
use ExactApproval\Demo\OAuth\OAuthError;
use ExactApproval\Demo\OAuth\OAuthServer;
use ExactApproval\Symfony\ActorProvider;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * MCP authorization (OAuth 2.1) for remote clients such as claude.ai. Signing in here lets a client
 * act for you; it never approves anything. Each alert still follows your role's rule, and county
 * staff approve each exact alert with their own passkey on this site.
 */
final readonly class OAuthController
{
    public function __construct(
        private OAuthServer $server,
        private ClientRegistry $clients,
        private ActorProvider $actors,
        private DemoDatabase $database,
        private CsrfTokenManagerInterface $csrf,
        private Environment $twig,
        private string $origin,
    ) {}

    /** RFC 9728, built with the PHP MCP SDK's ProtectedResourceMetadata. */
    public function protectedResource(): JsonResponse
    {
        return new JsonResponse(new ProtectedResourceMetadata($this->origin.'/mcp', [$this->origin], OAuthServer::SCOPES, 'Riverside Council public alerts'));
    }

    /** RFC 8414 authorization server metadata. */
    public function authorizationServer(): JsonResponse
    {
        return new JsonResponse([
            'issuer' => $this->origin,
            'authorization_endpoint' => $this->origin.'/oauth/authorize',
            'token_endpoint' => $this->origin.'/oauth/token',
            'registration_endpoint' => $this->origin.'/oauth/register',
            'scopes_supported' => OAuthServer::SCOPES,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    /** RFC 7591 dynamic client registration for public clients. */
    public function register(Request $request): JsonResponse
    {
        try {
            $metadata = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            if (!\is_array($metadata)) throw new OAuthError('invalid_client_metadata', 'Send a JSON object.');

            return new JsonResponse($this->clients->register($metadata), 201, ['Cache-Control' => 'no-store']);
        } catch (\JsonException) {
            return self::error(new OAuthError('invalid_client_metadata', 'Send a JSON object.'));
        } catch (OAuthError $error) {
            return self::error($error);
        }
    }

    public function authorize(Request $request): Response
    {
        $input = $request->isMethod('POST') ? $request->request->all() : $request->query->all();
        $client = \is_string($input['client_id'] ?? null) ? $this->clients->find($input['client_id']) : null;
        $redirect = \is_string($input['redirect_uri'] ?? null) ? $input['redirect_uri'] : null;
        // Until the client and its redirect URI check out, errors stay on this site rather than redirecting.
        if ($client === null || $redirect === null || !\in_array($redirect, $client['redirect_uris'], true)) {
            return $this->page('oauth/error.html.twig', ['message' => 'This application is unknown, or asked to return to an address it did not register.'], 400);
        }
        $state = \is_string($input['state'] ?? null) ? $input['state'] : null;
        try {
            if (($input['response_type'] ?? null) !== 'code') throw new OAuthError('unsupported_response_type', 'Only the authorization code flow is supported.');
            if (($input['code_challenge_method'] ?? null) !== 'S256' || !\is_string($input['code_challenge'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $input['code_challenge'])) {
                throw new OAuthError('invalid_request', 'PKCE with S256 is required.');
            }
            $resource = $input['resource'] ?? null;
            if ($resource !== null && !\in_array(rtrim((string) $resource, '/'), [$this->origin.'/mcp', $this->origin], true)) {
                throw new OAuthError('invalid_target', 'This server only issues tokens for its own MCP endpoint.');
            }
            $scopes = OAuthServer::scopes(\is_string($input['scope'] ?? null) ? $input['scope'] : null);
            $actor = $this->actors->interactive();
            if ($request->isMethod('GET')) {
                $user = $this->database->user($actor->userId);
                $rule = $user === null ? null : $this->database->roleMode('announcement.publish', (string) $user['role']);

                return $this->page('oauth/consent.html.twig', [
                    'client' => $client, 'redirect_host' => parse_url($redirect, PHP_URL_HOST), 'user' => $user, 'rule' => $rule,
                    'fields' => array_intersect_key($input, array_flip(['client_id', 'redirect_uri', 'state', 'scope', 'resource', 'response_type', 'code_challenge', 'code_challenge_method'])),
                    'csrf' => $this->csrf->getToken('oauth_consent')->getValue(),
                ]);
            }
            if (!$this->csrf->isTokenValid(new CsrfToken('oauth_consent', (string) ($input['_token'] ?? '')))) {
                return $this->page('oauth/error.html.twig', ['message' => 'This consent form expired. Start connecting again from your application.'], 400);
            }
            if (($input['decision'] ?? null) !== 'allow') throw new OAuthError('access_denied', 'You did not allow the connection.');
            $code = $this->server->issueCode($client['client_id'], $redirect, $actor->userId, $actor->tenantId, $scopes, $input['code_challenge'], $client['client_name']);

            return new RedirectResponse(self::withQuery($redirect, ['code' => $code, 'state' => $state, 'iss' => $this->origin]));
        } catch (OAuthError $error) {
            return new RedirectResponse(self::withQuery($redirect, ['error' => $error->error, 'error_description' => $error->getMessage(), 'state' => $state, 'iss' => $this->origin]));
        }
    }

    public function token(Request $request): JsonResponse
    {
        $input = $request->request->all();
        $clientId = \is_string($input['client_id'] ?? null) ? $input['client_id'] : '';
        try {
            if ($clientId === '' || $this->clients->find($clientId) === null) throw new OAuthError('invalid_client', 'Unknown client.');
            $tokens = match ($input['grant_type'] ?? null) {
                'authorization_code' => $this->server->exchangeCode((string) ($input['code'] ?? ''), $clientId, (string) ($input['redirect_uri'] ?? ''), (string) ($input['code_verifier'] ?? '')),
                'refresh_token' => $this->server->refresh((string) ($input['refresh_token'] ?? ''), $clientId),
                default => throw new OAuthError('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
            };

            return new JsonResponse($tokens, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        } catch (OAuthError $error) {
            return self::error($error, $error->error === 'invalid_client' ? 401 : 400);
        }
    }

    private function page(string $template, array $context, int $status = 200): Response
    {
        // Consent must never be framed by another site.
        return new Response($this->twig->render($template, $context), $status, ['Content-Security-Policy' => "frame-ancestors 'none'", 'X-Frame-Options' => 'DENY', 'Cache-Control' => 'no-store']);
    }

    private static function error(OAuthError $error, int $status = 400): JsonResponse
    {
        return new JsonResponse(['error' => $error->error, 'error_description' => $error->getMessage()], $status, ['Cache-Control' => 'no-store']);
    }

    private static function withQuery(string $uri, array $query): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query(array_filter($query, static fn ($value) => $value !== null));
    }
}
