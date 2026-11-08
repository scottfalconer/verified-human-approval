<?php

declare(strict_types=1);

namespace ExactApproval\Core\Evidence;

use ExactApproval\Core\{ApprovalException, ApprovalState};

/** Export only after execution commits; a signer failure cannot call an action again. */
final readonly class EvidenceExporter
{
    public function __construct(private EvidenceSigner $signer)
    {
    }

    /** The host must obtain this state through ApprovalService::get() to enforce ownership. */
    public function export(ApprovalState $state): array
    {
        if ($state->status !== 'completed' || $state->execution === null) {
            throw new ApprovalException('not_completed', 'Only a committed action has execution evidence.');
        }
        // A receipt signed inside the completing transaction is returned as it was signed. Records
        // completed without a configured signer are signed now, and say so.
        $receipt = $state->receipt ?? Receipt::sign($this->signer, $state->request->digest, $state->proof, $state->execution);

        return [
            'format' => 'exact-approval/evidence/v1', 'proposal' => $state->request->canonical,
            'proof' => $state->proof === null ? null : (object) $state->proof, 'execution' => (object) $state->execution,
            'receipt' => (object) ($receipt + ['signed' => $state->receipt === null ? 'at_export' : 'at_commit']),
        ];
    }
}
