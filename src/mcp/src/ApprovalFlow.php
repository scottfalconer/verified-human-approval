<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Mcp;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\RequestContext;

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
     */
    public const OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'requestId' => ['type' => 'string', 'description' => 'The approval request. Read its outcome with the status tool.'],
            'status' => [
                'type' => 'string',
                'enum' => ['pending', 'completed', 'cancelled', 'expired', 'invalidated', 'denied', 'rejected'],
                'description' => 'What the application recorded. Only "completed" means the action ran; "pending" means a person still has to approve it on the site.',
            ],
            'reason' => ['type' => ['string', 'null'], 'description' => 'Why the request stopped, when it did.'],
            'result' => ['type' => ['object', 'array', 'null'], 'description' => 'What the action recorded when it ran.'],
            'review_url' => ['type' => 'string', 'description' => 'Where the person reviews and approves, while the request is pending.'],
            'message' => ['type' => 'string', 'description' => 'What to tell the person, and what to do next.'],
            'approval' => [
                'type' => 'object',
                'description' => 'The approval card’s view, under fixed names.',
                'properties' => [
                    'requestId' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'reason' => ['type' => ['string', 'null']],
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

    public function __construct(private ApprovalService $approvals, private ResultPresenter $presenter, private UrlPrompt $prompt) {}

    /**
     * @param array<string, mixed> $arguments the tool's arguments as the client sent them
     * @param callable(): ApprovalState $request requests or reuses the proposal
     */
    public function respond(RequestContext $context, Actor $actor, string $tool, array $arguments, callable $request): CallToolResult|InputRequiredResult
    {
        $binding = UrlPrompt::binding($actor, $tool, $arguments);
        $resumed = UrlPrompt::resumed($context->getInputContext(), $binding);
        if ($resumed !== null) {
            $state = $this->approvals->get($actor, $resumed['requestId']);

            return $this->presenter->toolResult($state, UrlPrompt::note($resumed['answer'], $state, $this->presenter->statusTool()));
        }

        $state = $request();
        if ($state->status === 'pending' && $this->prompt->allowsContext($context)) {
            return $this->prompt->ask($context, $state, $this->presenter->reviewUrl($state), $binding);
        }

        return $this->presenter->toolResult($state);
    }
}
