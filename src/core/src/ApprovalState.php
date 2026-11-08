<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

final readonly class ApprovalState
{
    public function __construct(
        public ApprovalRequest $request,
        public string $status = 'pending',
        public ?string $reason = null,
        public ?array $result = null,
        public ?array $execution = null,
        public ?array $proof = null,
        public ?array $receipt = null,
    ) {
        if (!in_array($status, ['pending', 'completed', 'invalidated', 'expired', 'cancelled', 'denied'], true)) {
            throw new \InvalidArgumentException('Unknown approval status.');
        }
    }

    public function toArray(): array
    {
        return ['requestId' => $this->request->id, 'status' => $this->status, 'reason' => $this->reason, 'result' => $this->result];
    }

    public function stopped(string $status, string $reason): self
    {
        return new self($this->request, $status, $reason);
    }
}
