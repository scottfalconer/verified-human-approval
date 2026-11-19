<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

final readonly class ApprovalState
{
    private const STATUSES = ['pending', 'completed', 'invalidated', 'expired', 'cancelled', 'denied'];

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
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown approval status.');
        }
    }

    /**
     * Reads a record back from the parts a store keeps: the proposal's bytes and digest, the status and reason, the
     * result, execution record and proof as JSON objects, and the receipt. A part that does not read back as it was
     * written (a proposal that no longer matches its digest, JSON that does not parse or is not an object, a status
     * this class does not know) leaves nothing in the record to rely on, not even whose it is, so the whole record is
     * refused. A store's get() and lookups pass that on; its lists put an UnreadableApproval in the record's place.
     *
     * @param array{manifest: string, keyId: string, signature: string}|null $receipt
     *
     * @throws ApprovalException with the reason record_integrity_error, and what failed as its previous exception
     */
    public static function fromStorage(string $id, string $proposal, string $digest, string $status, ?string $reason, ?string $result, ?string $execution, ?string $proof, ?array $receipt): self
    {
        try {
            $request = new ApprovalRequest($id, $proposal, $digest);
        } catch (\Throwable $error) {
            throw self::unreadable('The stored approval proposal fails its integrity check.', $error);
        }
        $objects = [
            'result' => self::storedObject('approval result', $result),
            'execution' => self::storedObject('execution record', $execution),
            'proof' => self::storedObject('approval proof', $proof),
        ];
        try {
            return new self($request, $status, $reason, ...$objects, receipt: $receipt);
        } catch (\InvalidArgumentException $error) {
            throw self::unreadable('The stored approval status is not a known status.', $error);
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
     * Construction stays permissive so that such a view can still load and show a damaged row. A row that cannot
     * be read at all never becomes a state: see fromStorage().
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

    /** @return array<string, mixed>|null */
    private static function storedObject(string $part, ?string $json): ?array
    {
        try {
            return null === $json ? null : CanonicalJson::map(CanonicalJson::decode($json));
        } catch (\Throwable $error) {
            throw self::unreadable(\sprintf('The stored %s is not a JSON object.', $part), $error);
        }
    }

    private static function unreadable(string $problem, \Throwable $error): ApprovalException
    {
        return new ApprovalException('record_integrity_error', $problem.' Its outcome cannot be trusted.', $error);
    }
}
