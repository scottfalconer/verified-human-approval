<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\ResultPresenter;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** One result shape for MCP and browser status, with no assertion material. */
final readonly class ApprovalPresenter implements ResultPresenter
{
    public function __construct(private UrlGeneratorInterface $urls, private string $publicOrigin)
    {
        $parts = parse_url($publicOrigin);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && !\in_array($parts['path'], ['', '/'], true))
            || !($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && $parts['host'] === 'localhost'))) {
            throw new \InvalidArgumentException('public_origin must be an HTTPS origin, or HTTP localhost for development.');
        }
    }

    public function statusTool(): string
    {
        return ApprovalStatusTool::NAME;
    }

    public function reviewUrl(ApprovalState $state): string
    {
        return rtrim($this->publicOrigin, '/').$this->urls->generate('verified_human_approval_review', ['id' => $state->request->id]);
    }

    public function data(ApprovalState $state, ?string $message = null): array
    {
        $data = $state->toArray();
        if ($state->status === 'pending') {
            $data['review_url'] = $this->reviewUrl($state);
            $data['message'] = 'Approval required. Open the review URL in your browser. The application executes only its stored proposal after valid passkey approval. Then call approval_status.';
        }
        if ($message !== null) {
            $data['message'] = $message;
        }
        // The MCP App card reads these fixed names; it shows only what the application recorded.
        // It offers the phone only when a phone could reach the site: a public HTTPS address.
        $phone = str_starts_with($this->publicOrigin, 'https://') ? ApprovalHandoffTool::NAME : null;
        $data['approval'] = ApprovalCard::view($state, $this->reviewUrl($state), $this->statusTool(), $phone);

        return $data;
    }

    public function toolResult(ApprovalState $state, ?string $message = null): CallToolResult
    {
        $data = $this->data($state, $message);

        return new CallToolResult(
            [new TextContent(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))],
            structuredContent: $data,
        );
    }
}
