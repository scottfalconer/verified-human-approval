<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Testing;

/**
 * What the application answered to one request from a passkey panel: the HTTP status and the JSON object.
 *
 * A refusal is an answer too, so a test can assert it. Cast the answer to a string for an assertion's message:
 * it gives the status and the whole body.
 */
final readonly class PanelAnswer implements \Stringable
{
    /** The body's "status" when it is a string: "completed", "registered", "invalidated", "rejected", "rate_limited"… */
    public ?string $status;

    /** The body's "reason" when it is a string: "stale_resource", "credential_required", "csrf_invalid"… */
    public ?string $reason;

    /** @param array<string, mixed> $data the JSON object the application answered with */
    public function __construct(public int $httpStatus, public array $data)
    {
        $status = $data['status'] ?? null;
        $reason = $data['reason'] ?? null;
        $this->status = \is_string($status) ? $status : null;
        $this->reason = \is_string($reason) ? $reason : null;
    }

    /**
     * Whether the application answered with an HTTP error status, which is where the page's script stops.
     *
     * An answer that was not refused still needs its status read: a request that was stopped while the person
     * approved answers HTTP 200 with the status "invalidated".
     */
    public function refused(): bool
    {
        return $this->httpStatus < 200 || $this->httpStatus > 299;
    }

    public function __toString(): string
    {
        return \sprintf('HTTP %d %s', $this->httpStatus, json_encode((object) $this->data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
