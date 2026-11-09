<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Security;

use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Stateless\StandardHeaderValidator;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Wire\InboundClassifier;
use Omouren\McpOauthBundle\OAuth\IssuerResolver;
use Omouren\McpOauthBundle\OAuth\McpRequestGuard;
use Omouren\McpOauthBundle\OAuth\WwwAuthenticateHeader;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Token scope is an HTTP authorization decision; ownership and action policy stay inside the tools. */
#[AsEventListener(event: KernelEvents::CONTROLLER, method: 'authorize')]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'challenge', priority: -10)]
final readonly class McpScopeGuard
{
    private const REQUIRED_SCOPES = '_verified_human_approval_required_scopes';

    private ScopePolicy $policy;

    public function __construct(
        private McpRequestGuard $resources,
        private IssuerResolver $issuer,
        private WwwAuthenticateHeader $header,
    ) {
        $this->policy = new ScopePolicy(tools: [
            'read_alert' => ['alert.read'],
            'publish_alert' => ['alert.publish', 'approval.read'],
            'approval_status' => ['approval.read'],
            'approval_handoff' => ['approval.read'],
        ]);
    }

    public function authorize(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || !$this->resources->isResourcePath($request)) {
            return;
        }
        // kernel.controller runs after the firewall. Never authenticate again or trust caller-supplied scopes.
        $token = $request->attributes->get(AccessToken::class);
        if (!$token instanceof AccessToken) {
            return;
        }
        $body = $request->getContent();
        if (\strlen($body) > StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES) {
            return; // The SDK owns the oversized-body response; do not decode it here.
        }
        $headers = array_map(static fn (array $values): string => implode(', ', $values), $request->headers->all());
        $era = (new InboundClassifier())->classify('POST', $body, $headers);
        if ($era->isRejected() || ($era->modern && true !== ProtocolVersion::tryFrom($era->claimedVersion ?? '')?->isModern())) {
            return; // Keep the SDK's protocol/version error instead of turning it into a scope challenge.
        }
        try {
            $messages = MessageFactory::make()->create($body);
        } catch (\JsonException) {
            return;
        }
        $calls = [];
        foreach ($messages as $message) {
            if (!$message instanceof CallToolRequest) {
                continue;
            }
            $call = $message->jsonSerialize();
            if ($era->modern && null !== (new StandardHeaderValidator())->validate($call['method'], $call['params'] ?? null, $headers)) {
                return; // A misleading Mcp-Name/Method must reach the SDK's rejection, never select the scope policy.
            }
            $calls[] = $call;
        }
        // Read the parsed body, not Mcp-Name. A handshake-era batch needs the union of all called tools' scopes.
        $required = $this->policy->requiredFor($calls);
        if ([] === array_diff($required, $this->policy->expand($token->getScopes()))) {
            return;
        }
        $request->attributes->set(self::REQUIRED_SCOPES, $required);
        $response = new JsonResponse(['error' => 'insufficient_scope'], Response::HTTP_FORBIDDEN, ['Cache-Control' => 'no-store']);
        $event->setController(static fn (): Response => $response);
    }

    public function challenge(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $required = $request->attributes->get(self::REQUIRED_SCOPES);
        if (!$event->isMainRequest() || Response::HTTP_FORBIDDEN !== $event->getResponse()->getStatusCode() || !\is_array($required)) {
            return;
        }
        // After omouren's generic challenge: name every scope for this operation, not every scope the server offers.
        $event->getResponse()->headers->set('WWW-Authenticate', $this->header->format(
            $this->issuer->protectedResourceMetadataUrl($request),
            array_values(array_filter($required, 'is_string')),
            'insufficient_scope',
        ));
    }
}
