<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\RequestContext;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;

/**
 * How a protected tool presents its approval.
 *
 * Every client gets the pending result at once, with the review link and the card's view. A
 * client the configuration names also gets a URL-mode elicitation. Its retry is bound to the same
 * caller, tool and arguments, and only reads the stored state: the client's answer never approves
 * anything, and the application has already executed the action if the passkey check passed.
 */
final readonly class ApprovalFlow
{
    /**
     * JSON Schema of the structured result a protected tool and the status tool return: the
     * presenter's data. Declare it as the tool's `outputSchema`, so clients know what to expect and
     * can validate it. A refusal is an error result carrying `status: rejected`, `reason` and `message`.
     * A stored record that fails its integrity check carries `status: integrity_error` instead, which
     * does not establish whether the action ran. {@see self::errorResult()} builds both.
     */
    public const OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'requestId' => ['type' => 'string', 'description' => 'The approval request. Read its outcome with the status tool.'],
            'status' => [
                'type' => 'string',
                'enum' => ['pending', 'completed', 'cancelled', 'expired', 'invalidated', 'denied', 'rejected', 'integrity_error'],
                'description' => 'What the application recorded. Only "completed" means the action ran; "pending" means a person still has to approve it on the site. "integrity_error" means the stored record failed its integrity check, so it does not say whether the action ran: do not retry, and have the site’s administrator look at it.',
            ],
            'reason' => ['type' => ['string', 'null'], 'description' => 'Why the request stopped, when it did.'],
            'result' => ['type' => ['object', 'array', 'null'], 'description' => 'What the action recorded when it ran.'],
            'review_url' => ['type' => 'string', 'description' => 'Where the person reviews and approves, while the request is pending.'],
            'message' => ['type' => 'string', 'description' => 'What to tell the person, and what to do next.'],
            'receipt' => [
                'type' => 'object',
                'description' => 'Once the action ran: the site’s receipt, signed as it ran. Keep it unchanged; it checks against the keys at receipt_keys_url, even after the site changes its key, and a later change to the site’s record would no longer match it.',
                'properties' => [
                    'manifest' => ['type' => 'string', 'description' => 'The exact signed text: digests of the proposal, the passkey proof and the execution record, and when it was signed.'],
                    'keyId' => ['type' => 'string'],
                    'signature' => ['type' => 'string', 'description' => 'Base64url Ed25519 signature over the manifest.'],
                ],
                'required' => ['manifest', 'keyId', 'signature'],
            ],
            'receipt_keys_url' => ['type' => 'string', 'description' => 'Where the site publishes every key it signs receipts with, and when each signed: what checks this receipt, before and after a key rotation.'],
            'receipt_key_url' => ['type' => 'string', 'description' => 'Where the site publishes the key it signs with now. A receipt signed before a key rotation checks against receipt_keys_url instead.'],
            'approval' => [
                'type' => 'object',
                'description' => 'The approval card’s view, under fixed names.',
                'properties' => [
                    'requestId' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'reason' => ['type' => ['string', 'null']],
                    'approvedWithPasskey' => ['type' => 'boolean', 'description' => 'Whether a person approved it with their passkey. A completed request without one ran because the application’s rule did not require approval.'],
                    'reviewUrl' => ['type' => 'string'],
                    'statusTool' => ['type' => 'string', 'description' => 'The read-only tool that reports this request’s outcome.'],
                    'statement' => ['type' => 'string', 'description' => 'The exact statement the person approves.'],
                    'handoffTool' => ['type' => ['string', 'null'], 'description' => 'The tool that gives the card a one-time phone code, when a phone could reach the site.'],
                ],
                'required' => ['requestId', 'status', 'reviewUrl', 'statusTool', 'statement'],
            ],
        ],
        'required' => ['status'],
        'additionalProperties' => true,
    ];

    /**
     * The description for a protected tool's optional request key argument:
     * `#[Schema(description: ApprovalFlow::REQUEST_KEY_DESCRIPTION)] ?string $request_key = null`.
     *
     * A request key is for a client that generates a unique id for each new request. A language
     * model asked for "your own id for this request" builds one from the arguments and the date, and
     * often builds the same one in a later conversation. The application then answers with the earlier
     * request and its outcome, and does not ask the person again. With this wording a model leaves the
     * argument out, which is safe: a repeated call without a key returns the same pending request,
     * and a new one once that request has finished.
     */
    public const REQUEST_KEY_DESCRIPTION = 'For clients that generate a unique random id for each new request and resend it to retry. Otherwise leave it out: a key used before returns that earlier request and its outcome, even after it finished, so never make one up from the arguments or the date.';

    public function __construct(private ApprovalService $approvals, private ResultPresenter $presenter, private UrlPrompt $prompt)
    {
    }

    /**
     * The error result for what the application would not do or could not vouch for, in the shape
     * {@see self::OUTPUT_SCHEMA} declares. Return it from a tool's `catch (ApprovalException $error)`.
     *
     * A stored record that fails its integrity check is not a refusal: nothing in it says whether the
     * action ran. It is reported as `integrity_error`, never as `rejected`, so no agent reads it as
     * "nothing happened" and tries again.
     *
     * @param string|null $requestId the request the caller named, when it named one
     */
    public static function errorResult(ApprovalException $error, ?string $requestId = null): CallToolResult
    {
        $data = [
            'status' => 'record_integrity_error' === $error->reason ? 'integrity_error' : 'rejected',
            'reason' => $error->reason,
            'message' => $error->getMessage(),
        ];
        if (null !== $requestId) {
            $data = ['requestId' => $requestId] + $data;
        }

        return new CallToolResult([new TextContent(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))], isError: true, structuredContent: $data);
    }

    /**
     * @param array<string, mixed>      $arguments the tool's arguments as the client sent them
     * @param callable(): ApprovalState $request   requests or reuses the proposal
     */
    public function respond(RequestContext $context, Actor $actor, string $tool, array $arguments, callable $request): CallToolResult|InputRequiredResult
    {
        $binding = UrlPrompt::binding($actor, $tool, $arguments);
        $resumed = UrlPrompt::resumed($context->getInputContext(), $binding);
        if (null !== $resumed) {
            $state = $this->approvals->get($actor, $resumed['requestId']);

            return $this->presenter->toolResult($state, UrlPrompt::note($resumed['answer'], $state, $this->presenter->statusTool()));
        }

        $state = $request();
        if ('pending' === $state->status && $this->prompt->allowsContext($context)) {
            return $this->prompt->ask($context, $state, $this->presenter->reviewUrl($state), $binding);
        }

        return $this->presenter->toolResult($state);
    }
}
