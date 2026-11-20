<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Mcp;

use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\RequestContext;
use Mcp\Server\Stateless\InputContext;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Stateless\RequestStateCodec;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalState;

/**
 * An optional URL-mode elicitation (MCP 2026-07-28 multi round-trip) for a pending approval.
 *
 * The card and the link in the result are the default. A client declaring URL mode is not enough
 * to send this prompt, because some clients declare it and then decline, drop or fail on it. The
 * application turns it on for the clients it has tested, by their clientInfo name.
 *
 * The client's answer is never an approval. "accept" only means the person agreed to open the
 * page. The application executes the stored proposal after its own passkey check, and reports
 * only the outcome it recorded.
 */
final readonly class UrlPrompt
{
    public const KEY = 'verified_human_approval';
    private const FORMAT = 'verified-human-approval/continuation/v1';

    /** @param array<mixed> $clients clientInfo names to prompt, or ['*'] for any client that declares URL mode; checked here because configuration can hold anything */
    public function __construct(private bool $enabled = false, private array $clients = [])
    {
        foreach ($clients as $client) {
            if (!\is_string($client) || '' === $client || \strlen($client) > 128) {
                throw new \InvalidArgumentException('Each URL elicitation client must be a clientInfo name of 1–128 bytes.');
            }
        }
    }

    /**
     * Prompt only when enabled, only under a revision where the server never holds the call open
     * waiting for a person, only for a client declaring URL mode, and only for a configured client.
     *
     * The revision check is load-bearing. The SDK's InputRequiredShim is on by default: on a
     * handshake-era (2025-11-25) connection it would turn a returned InputRequiredResult into a real
     * elicitation/create and hold the HTTP request open for up to ten minutes per round. Refusing
     * non-modern revisions here is what keeps Claude Desktop and claude.ai on the plain pending result.
     */
    public function allows(ProtocolVersion $version, bool $declaresUrlMode, ?string $clientName): bool
    {
        if (!$this->enabled || !$version->isModern() || !$declaresUrlMode) {
            return false;
        }

        return \in_array('*', $this->clients, true) || (null !== $clientName && \in_array($clientName, $this->clients, true));
    }

    public function allowsContext(RequestContext $context): bool
    {
        // The SDK has no public accessor for clientInfo; RequestContext keeps the request's metadata in the
        // session under RequestMeta::class, which its own getClientCapabilities() reads. If that changes, no
        // name is found and no prompt is sent: the card and the link still work, and the Claude Code
        // integration tests fail loudly.
        $meta = $context->getSession()->get(RequestMeta::class);

        return $this->allows(
            $context->getProtocolVersion(),
            true === $context->getClientCapabilities()?->elicitationUrl,
            $meta instanceof RequestMeta ? $meta->clientInfo?->name : null,
        );
    }

    /**
     * Whether this server can carry a prompt's state to its retry. The SDK signs that state with the key the server
     * was built with (Builder::setRequestState(), or request_state.key in the MCP bundle), and mintRequestState()
     * finds it in the session, as this does.
     */
    public static function canContinue(RequestContext $context): bool
    {
        return $context->getSession()->get(RequestStateCodec::class) instanceof RequestStateCodec;
    }

    /**
     * Binds a continuation to who called which tool with which arguments.
     *
     * The binding is not evidence, so it takes whatever JSON the client sent, decimals included: every object's
     * keys are put in order, so the order a client sent them in does not matter, and 1 and 1.0 bind alike.
     *
     * @param array<string, mixed> $arguments
     */
    public static function binding(Actor $actor, string $tool, array $arguments): string
    {
        return hash('sha256', json_encode([self::FORMAT, $actor->toArray(), $tool, self::sorted($arguments)], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return array_map(self::sorted(...), $value);
    }

    /**
     * Ends this round with a URL-mode elicitation. The signed request state carries only a
     * reference and the binding; the SDK signs it but does not encrypt it.
     */
    public function ask(RequestContext $context, ApprovalState $state, string $reviewUrl, string $binding): InputRequiredResult
    {
        return new InputRequiredResult(
            inputRequests: [self::KEY => ElicitRequest::forUrl(self::message($state, $reviewUrl), $reviewUrl)],
            requestState: $context->mintRequestState(['format' => self::FORMAT, 'requestId' => $state->request->id, 'binding' => $binding]),
        );
    }

    /**
     * Reads a retry of an asked round. Null when this call is not such a retry.
     *
     * @return array{requestId: string, answer: 'accept'|'decline'|'cancel'|'none'}|null
     */
    public static function resumed(?InputContext $input, string $binding): ?array
    {
        if (null === $input) {
            return null;
        }
        $state = $input->requestState();
        $answered = $input->has(self::KEY);
        // A verified state of ours names the request even when no answer came back with it.
        if (!$answered && ($state['format'] ?? null) !== self::FORMAT) {
            return null;
        }
        if (($state['format'] ?? null) !== self::FORMAT || !\is_string($state['requestId'] ?? null) || !\is_string($state['binding'] ?? null)) {
            throw new ApprovalException('continuation_invalid', 'This continuation does not belong to an approval request. Call the tool again.');
        }
        if (!hash_equals($state['binding'], $binding)) {
            throw new ApprovalException('continuation_mismatch', 'The retried call changed its caller or arguments, so it cannot continue this approval. Call the tool again.');
        }

        return ['requestId' => $state['requestId'], 'answer' => $answered ? self::answer($input->elicitResult(self::KEY, ElicitationMode::Url)) : 'none'];
    }

    /** @return 'accept'|'decline'|'cancel'|'none' */
    public static function answer(?ElicitResult $result): string
    {
        return match (true) {
            null === $result => 'none',
            $result->isAccepted() => 'accept',
            $result->isDeclined() => 'decline',
            default => 'cancel',
        };
    }

    /** The prompt the client shows the person. */
    public static function message(ApprovalState $state, string $reviewUrl): string
    {
        $host = parse_url($reviewUrl, \PHP_URL_HOST);
        $port = parse_url($reviewUrl, \PHP_URL_PORT);
        $site = \is_string($host) ? $host.(\is_int($port) ? ':'.$port : '') : 'the site';

        return 'Approval needed on '.$site.': "'.$state->request->statement().'" Open the page to review the exact change and approve it with your passkey. Nothing changes until you do. Request '.$state->request->id.'.';
    }

    /** What the model is told after the client answered the prompt. */
    public static function note(string $answer, ApprovalState $state, string $statusTool): string
    {
        return match (true) {
            'completed' === $state->status && null !== $state->proof => 'Approved on the site with the person\'s passkey. The stored action ran once; this is its recorded result.',
            'completed' === $state->status => 'The stored action ran once without a passkey: the application\'s rule did not require one. This is its recorded result.',
            'pending' !== $state->status => 'This request is '.$state->status.'. It did not change anything. Request the action again if it is still wanted.',
            'accept' === $answer => 'The person opened the approval page. That is not an approval: the site acts only after its own passkey check. Call '.$statusTool.' with this requestId to read the outcome.',
            'none' === $answer => 'The client sent no answer to the approval prompt, so nothing was approved or changed. The request is still open: the person can use the review URL to approve or cancel it on the site.',
            default => 'The approval prompt was dismissed, so nothing was approved or changed. The request is still open: the person can use the review URL to approve or cancel it on the site.',
        };
    }
}
