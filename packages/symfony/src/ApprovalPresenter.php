<?php

declare(strict_types=1);

namespace ExactApproval\Symfony;

use ExactApproval\Core\ApprovalState;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** One result shape for MCP and browser status, with no assertion material. */
final readonly class ApprovalPresenter
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

    public function data(ApprovalState $state): array
    {
        $data = $state->toArray();
        if ($state->status === 'pending') {
            $data['review_url'] = rtrim($this->publicOrigin, '/').$this->urls->generate('exact_approval_review', ['id' => $state->request->id]);
            $data['message'] = 'Approval required. Open the review URL in your browser. The application executes only its stored proposal after valid passkey approval. Then call approval_status.';
        }

        return $data;
    }

    public function toolResult(ApprovalState $state): CallToolResult
    {
        $data = $this->data($state);

        return new CallToolResult(
            [new TextContent(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))],
            structuredContent: $data,
        );
    }
}
