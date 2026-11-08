<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

final readonly class ApprovalState
{
    /**
     * @param array<string, mixed>|null $result    what the action recorded
     * @param array<string, mixed>|null $execution the execution record
     * @param array<string, mixed>|null $proof     the verified approval's evidence
     * @param array<string, mixed>|null $receipt   the receipt signed at commit
     */
    public function __construct(
        public ApprovalRequest $request,
        public string $status = 'pending',
        public ?string $reason = null,
        public ?array $result = null,
        public ?array $execution = null,
        public ?array $proof = null,
        public ?array $receipt = null,
    ) {
        if (!\in_array($status, ['pending', 'completed', 'invalidated', 'expired', 'cancelled', 'denied'], true)) {
            throw new \InvalidArgumentException('Unknown approval status.');
        }
    }

    /** @return array{requestId: string, status: string, reason: ?string, result: ?array<string, mixed>} */
    public function toArray(): array
    {
        return ['requestId' => $this->request->id, 'status' => $this->status, 'reason' => $this->reason, 'result' => $this->result];
    }

    public function stopped(string $status, string $reason): self
    {
        return new self($this->request, $status, $reason);
    }
}
