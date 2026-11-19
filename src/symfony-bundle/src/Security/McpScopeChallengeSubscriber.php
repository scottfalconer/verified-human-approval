<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Security;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\AI\McpBundle\Controller\McpController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers an MCP request whose access token lacks a scope the way the MCP authorization specification
 * asks: `403` with `WWW-Authenticate: Bearer error="insufficient_scope"` naming every scope the operation
 * needs, so a client can ask the person for them and retry. A tool can only refuse inside its result,
 * which reads as a failed call and gives the client nothing to act on.
 *
 * Use it where the MCP endpoint is an OAuth protected resource, so a client has an authorization server to
 * ask. With fixed tokens there is nothing to ask for: refuse inside the tool, where the model reads why.
 *
 * It applies the PHP MCP SDK's own ScopePolicy, as the SDK's AuthorizationMiddleware does. Where you can
 * add middleware to the transport, add that instead. symfony/mcp-bundle 1.0 builds the transport itself and
 * takes none, which is why this exists. To opt in, register the policy and this class, with autowiring and
 * autoconfiguration on as in a default `services.yaml`:
 *
 *     services:
 *         Mcp\Server\Transport\Http\OAuth\ScopePolicy:
 *             arguments:
 *                 $tools:
 *                     publish_article: [articles.publish, approval.read]
 *                     approval_status: [approval.read]
 *                     approval_handoff: [approval.read]
 *
 *         VerifiedHumanApproval\Bundle\Security\McpScopeChallengeSubscriber: ~
 *
 * With a ProtectedResourceMetadata service (RFC 9728), the challenge also carries `resource_metadata`.
 *
 * What it relies on:
 *
 * - Your authenticator sets the validated token as the `AccessToken::class` request attribute, and has run
 *   by the time the controller is chosen: require a role for the MCP path in `access_control`. A request
 *   that needs a scope and carries no such attribute is answered `401`.
 * - A tool the policy does not name needs nothing beyond the policy's default scopes.
 * - It recognises the endpoint by the MCP bundle's controller, wherever the path is, so one policy
 *   covers every HTTP server the application serves. A container in which no service is that controller
 *   does not build, since this would challenge nothing there.
 *
 * Keep the scope checks inside your tools as well: stdio has no HTTP status to answer with, and over HTTP
 * they hold if this is ever left unregistered.
 */
final class McpScopeChallengeSubscriber implements EventSubscriberInterface
{
    private const LACKS_SCOPE = 'The access token lacks a required scope.';

    public function __construct(private readonly ScopePolicy $policy, private readonly ?ProtectedResourceMetadata $metadata = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        $request = $event->getRequest();
        // A CORS preflight carries no credentials, and the transport answers OPTIONS without running anything.
        if (!(\is_array($controller) ? $controller[0] : $controller) instanceof McpController || $request->isMethod('OPTIONS')) {
            return;
        }
        $payload = null;
        if ($request->isMethod('POST') && $this->policy->inspectsBody()) {
            // Read no more than the transport's default limit, as the SDK's middleware does, whatever was sent.
            $body = stream_get_contents($request->getContent(true), StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES + 1);
            if (false === $body || \strlen($body) > StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES) {
                // Never hand on a body this did not inspect, whatever limit the transport applies.
                $this->refuse($event, Response::HTTP_REQUEST_ENTITY_TOO_LARGE, \sprintf('Request body exceeds the maximum allowed size of %d bytes.', StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES));

                return;
            }
            // The SDK parses the body the same way, so both read the same methods and tool names.
            $payload = json_decode($body, true);
        }
        $required = $this->policy->requiredFor($payload);
        // A scope holds no quote (RFC 6749 §3.3), so the list reads as one value, as it does in the header.
        $needs = \sprintf('the scope "%s"', implode(' ', $required));
        $token = $request->attributes->get(AccessToken::class);
        if (!$token instanceof AccessToken) {
            if ([] !== $required) {
                // RFC 6750 §3.1: a request without credentials gets a challenge with no error code.
                $this->refuse($event, Response::HTTP_UNAUTHORIZED, 'This operation needs an access token with '.$needs.'.', $this->challenge($required));
            }

            return;
        }
        $granted = $this->policy->expand($token->getScopes());
        if ([] !== array_diff($required, $granted)) {
            $this->refuse($event, Response::HTTP_FORBIDDEN, self::LACKS_SCOPE.' This operation needs '.$needs.'.', $this->challenge($required, 'insufficient_scope', self::LACKS_SCOPE));

            return;
        }
        // Tools that ask AccessToken::hasScope() see the scopes the policy says the granted ones imply.
        $request->attributes->set(AccessToken::class, new AccessToken($granted, $token->getClaims()));
    }

    private function refuse(ControllerEvent $event, int $status, string $message, ?string $challenge = null): void
    {
        // A client that can ask for more scope acts on the header. Any other only reports the failure, so the body
        // is a JSON-RPC error like the transport's own refusals: the SDK's client shows such a message to its
        // caller, a model included, and shows only the status of anything else.
        $response = new JsonResponse(Error::forInvalidRequest($message), $status, ['Cache-Control' => 'no-store']);
        if (null !== $challenge) {
            $response->headers->set('WWW-Authenticate', $challenge);
        }
        // Nothing of the MCP server runs: the response replaces the controller.
        $event->setController(static fn (): Response => $response);
    }

    /**
     * The parameters, their order and their escaping are the SDK middleware's, so a client reads the same header from both.
     *
     * @param list<string> $scopes every scope the operation needs, not only the missing ones
     */
    private function challenge(array $scopes, ?string $error = null, ?string $description = null): string
    {
        $parts = [];
        if (null !== $this->metadata) {
            $parts[] = 'resource_metadata="'.self::quoted($this->metadata->getMetadataUrl()).'"';
        }
        $parts[] = 'scope="'.self::quoted(implode(' ', $scopes)).'"';
        if (null !== $error) {
            $parts[] = 'error="'.self::quoted($error).'"';
        }
        if (null !== $description) {
            $parts[] = 'error_description="'.self::quoted($description).'"';
        }

        return 'Bearer '.implode(', ', $parts);
    }

    /** A quoted-string's content (RFC 9110 §5.6.4); control characters are dropped so a value cannot add a header. */
    private static function quoted(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');
    }
}
