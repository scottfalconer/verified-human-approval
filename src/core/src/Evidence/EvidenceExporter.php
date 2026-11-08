<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Evidence;

use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalState;

/**
 * Export only after execution commits; a signer failure cannot call an action again.
 *
 * By default only an action with its receipt signed at commit has evidence. A missing receipt means
 * the action ran without a receipt signer, or that someone deleted the receipt, perhaps after editing
 * the record; nothing can tell the two apart, so nothing is signed over it. An application that has
 * never signed at commit passes $signAtExport, and the receipts it then gets say "export" in what they
 * sign: they vouch for the record as it was exported, not as it committed.
 */
final readonly class EvidenceExporter
{
    public const FORMAT = 'verified-human-approval/evidence/v2';

    public function __construct(private EvidenceSigner $signer, private bool $signAtExport = false)
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
        if (null === $state->receipt && !$this->signAtExport) {
            throw new ApprovalException('receipt_missing', 'This action has no receipt signed as it committed, so its record cannot be vouched for.');
        }
        $receipt = $state->receipt ?? Receipt::sign($this->signer, $state->request->digest, $state->proof, $state->execution, Receipt::EXPORT);

        return [
            'format' => self::FORMAT, 'proposal' => $state->request->canonical,
            'proof' => null === $state->proof ? null : (object) $state->proof, 'execution' => (object) $state->execution,
            'receipt' => (object) $receipt,
        ];
    }
}
