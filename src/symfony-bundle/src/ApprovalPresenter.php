<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\ResultPresenter;

/** One result shape for MCP and browser status, with no assertion material. */
final readonly class ApprovalPresenter implements ResultPresenter
{
    public function __construct(private UrlGeneratorInterface $urls, private string $publicOrigin)
    {
        $parts = parse_url($publicOrigin);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && !\in_array($parts['path'], ['', '/'], true))
            || !('https' === $parts['scheme'] || ('http' === $parts['scheme'] && 'localhost' === $parts['host']))) {
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

    /**
     * @param bool $earlier whether a tool call's request key named this earlier request, so nothing ran for that call
     *
     * @return array<string, mixed>
     */
    public function data(ApprovalState $state, ?string $message = null, bool $earlier = false): array
    {
        $data = $state->toArray();
        if ('pending' === $state->status) {
            $data['review_url'] = $this->reviewUrl($state);
            $data['message'] = 'Approval required. Open the review URL in your browser. The application executes only its stored proposal after valid passkey approval. Then call approval_status.';
        }
        if (null !== $message) {
            $data['message'] = $message;
        }
        if (null !== $state->receipt) {
            // The site's signature over what ran, as it ran: a copy the person keeps, checked against the published keys.
            // The key set lists every key the site has signed with, so it still checks this copy after a rotation; the
            // one-key file names the current key only.
            $data['receipt'] = $state->receipt;
            $data['receipt_keys_url'] = rtrim($this->publicOrigin, '/').$this->urls->generate('verified_human_approval_receipt_keys');
            $data['receipt_key_url'] = rtrim($this->publicOrigin, '/').$this->urls->generate('verified_human_approval_receipt_key');
        }
        // The MCP App card reads these fixed names; it shows only what the application recorded.
        // It offers the phone only when a phone could reach the site: a public HTTPS address.
        $phone = str_starts_with($this->publicOrigin, 'https://') ? ApprovalHandoffTool::NAME : null;
        $data['approval'] = ApprovalCard::view($state, $this->reviewUrl($state), $this->statusTool(), $phone, $earlier);

        return $data;
    }

    public function toolResult(ApprovalState $state, ?string $message = null, bool $earlier = false): CallToolResult
    {
        $data = $this->data($state, $message, $earlier);

        return new CallToolResult(
            [new TextContent(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES))],
            structuredContent: $data,
        );
    }
}
