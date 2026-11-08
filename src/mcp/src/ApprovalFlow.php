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
