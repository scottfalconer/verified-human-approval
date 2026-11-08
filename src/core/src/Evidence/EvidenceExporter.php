<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Evidence;

use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalState;

/** Export only after execution commits; a signer failure cannot call an action again. */
final readonly class EvidenceExporter
{
    public function __construct(private EvidenceSigner $signer)
    {
    }

    /**
     * The host must obtain this state through ApprovalService::get() to enforce ownership.
     *
     * @return array<string, mixed>
     */
    public function export(ApprovalState $state): array
    {
        if ('completed' !== $state->status || null === $state->execution) {
            throw new ApprovalException('not_completed', 'Only a committed action has execution evidence.');
        }
        // A receipt signed inside the completing transaction is returned as it was signed. Records
        // completed without a configured signer are signed now, and say so.
        $receipt = $state->receipt ?? Receipt::sign($this->signer, $state->request->digest, $state->proof, $state->execution);

        return [
            'format' => 'verified-human-approval/evidence/v1', 'proposal' => $state->request->canonical,
            'proof' => null === $state->proof ? null : (object) $state->proof, 'execution' => (object) $state->execution,
            'receipt' => (object) ($receipt + ['signed' => null === $state->receipt ? 'at_export' : 'at_commit']),
        ];
    }
}
