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

    /**
     * The outcome as it is reported. An inconsistent record is never reported: see assertConsistent().
     *
     * @return array{requestId: string, status: string, reason: ?string, result: ?array<string, mixed>}
     */
    public function toArray(): array
    {
        $this->assertConsistent();

        return ['requestId' => $this->request->id, 'status' => $this->status, 'reason' => $this->reason, 'result' => $this->result];
    }

    /**
     * Checks that the stored parts of this record agree, before its outcome is presented or used again. The
     * status and the result are stored beside the execution record, so a status that is not "completed" must
     * have no execution, proof or receipt behind it, and a completed one must carry the result its execution
     * record holds, for this request and this proposal.
     *
     * ApprovalService runs this on every record it reads, and toArray() and EvidenceExporter run it too. Call
     * it yourself before presenting a state you read from a store directly, as a history or audit view does.
     * Construction stays permissive so that such a view can still load and show a damaged row.
     *
     * This is a consistency check, not a signature check: someone who can edit every stored part can keep
     * them consistent. Only the receipt signed at commit, verified with a trusted key, catches that.
     *
     * @throws ApprovalException with the reason record_integrity_error
     */
    public function assertConsistent(): void
    {
        if ('completed' !== $this->status) {
            if (null !== $this->result || null !== $this->execution || null !== $this->proof || null !== $this->receipt) {
                throw new ApprovalException('record_integrity_error', 'The stored approval status contradicts the execution evidence stored with it. Its outcome cannot be trusted.');
            }

            return;
        }
        try {
            $recorded = $this->execution['result'] ?? null;
            if (null === $this->result || null === $this->execution || null !== $this->reason
                || ($this->execution['requestId'] ?? null) !== $this->request->id
                || ($this->execution['proposalDigest'] ?? null) !== $this->request->digest
                // A stored record is read back as an object; the one just written still holds what it was built from.
                || (!\is_array($recorded) && !$recorded instanceof \stdClass)
                || CanonicalJson::encode((object) $this->result) !== CanonicalJson::encode((object) $recorded)
            ) {
                throw new \UnexpectedValueException('The stored result does not match its execution record.');
            }
        } catch (\Throwable $error) {
            throw new ApprovalException('record_integrity_error', 'The stored approval result does not match its execution record. Its outcome cannot be trusted.', $error);
        }
    }

    public function stopped(string $status, string $reason): self
    {
        return new self($this->request, $status, $reason);
    }
}
