<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Evidence;

use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalState;

/**
 * Export only after execution commits; nothing here can run an action again.
 *
 * By default only an action with its receipt signed at commit has evidence, and the exporter returns that
 * original receipt unchanged, so serving evidence needs no access to the private receipt key: construct it
 * without a signer. A missing receipt means the action ran without a receipt signer, or that someone deleted
 * the receipt, perhaps after editing the record; nothing can tell the two apart, so nothing is signed over
 * it. An application that has never signed at commit passes a signer and $signAtExport, and the receipts it
 * then gets say "export" in what they sign: they vouch for the record as it was exported, not as it committed.
 */
final readonly class EvidenceExporter
{
    public const FORMAT = 'verified-human-approval/evidence/v2';

    public function __construct(private ?EvidenceSigner $signer = null, private bool $signAtExport = false)
    {
        if ($signAtExport && null === $signer) {
            throw new \InvalidArgumentException('A signer is required when signing evidence at export.');
        }
    }

    /**
     * The evidence for a completed action. Get the state through ApprovalService::get(), which enforces
     * ownership by user and tenant, and check your own current permission to read results first: evidence
     * returns the stored outcome. An administrator's access to other people's evidence needs its own policy.
     *
     * @return array<string, mixed>
     */
    public function export(ApprovalState $state): array
    {
        $state->assertConsistent();
        if ('completed' !== $state->status || null === $state->execution) {
            throw new ApprovalException('not_completed', 'Only a committed action has execution evidence.');
        }
        if (null !== $state->receipt) {
            // The original receipt, as it was signed: never re-signed, so the signer is not touched.
            $receipt = $state->receipt;
        } elseif ($this->signAtExport && null !== $this->signer) {
            $receipt = Receipt::sign($this->signer, $state->request->digest, $state->proof, $state->execution, Receipt::EXPORT);
        } else {
            throw new ApprovalException('receipt_missing', 'This action has no receipt signed as it committed, so its record cannot be vouched for.');
        }

        return [
            'format' => self::FORMAT, 'proposal' => $state->request->canonical,
            'proof' => null === $state->proof ? null : (object) $state->proof, 'execution' => (object) $state->execution,
            'receipt' => (object) $receipt,
        ];
    }
}
