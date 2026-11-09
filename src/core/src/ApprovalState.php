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
        $this->assertConsistent();

        return ['requestId' => $this->request->id, 'status' => $this->status, 'reason' => $this->reason, 'result' => $this->result];
    }

    /**
     * Checks the stored projections before they can be presented or used again.
     * Construction stays permissive so an audit can inspect a damaged row. This
     * checks consistency, not a signature; receipt verification needs a trust key.
     */
    public function assertConsistent(): void
    {
        if ('completed' !== $this->status) {
            if (null !== $this->result || null !== $this->execution || null !== $this->proof || null !== $this->receipt) {
                throw new ApprovalException('record_integrity_error', 'The stored approval outcome contradicts its execution evidence. Its outcome cannot be trusted.');
            }

            return;
        }
        try {
            $executionResult = $this->execution['result'] ?? null;
            if (null === $this->result || null === $this->execution || null !== $this->reason
                || ($this->execution['requestId'] ?? null) !== $this->request->id
                || ($this->execution['proposalDigest'] ?? null) !== $this->request->digest
                || (!\is_array($executionResult) && !$executionResult instanceof \stdClass)
                || CanonicalJson::encode((object) $this->result) !== CanonicalJson::encode((object) $executionResult)) {
                throw new \UnexpectedValueException('The stored result does not match its execution record.');
            }
        } catch (\Throwable $error) {
            throw new ApprovalException('record_integrity_error', 'The stored approval result does not match its execution evidence. Its outcome cannot be trusted.', $error);
        }
    }

    public function stopped(string $status, string $reason): self
    {
        return new self($this->request, $status, $reason);
    }
}
