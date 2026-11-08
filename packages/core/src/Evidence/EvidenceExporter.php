<?php

declare(strict_types=1);

namespace ExactApproval\Core\Evidence;

use ExactApproval\Core\{ApprovalException, ApprovalState, CanonicalJson};

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
        $proof = $state->proof === null ? null : (object) $state->proof;
        $execution = (object) $state->execution;
        $manifest = CanonicalJson::encode([
            'format' => 'exact-approval/receipt/v1', 'keyId' => $this->signer->keyId(),
            'proposalDigest' => $state->request->digest,
            'proofDigest' => hash('sha256', CanonicalJson::encode($proof)),
            'executionDigest' => hash('sha256', CanonicalJson::encode($execution)),
        ]);
        return [
            'format' => 'exact-approval/evidence/v1', 'proposal' => $state->request->canonical,
            'proof' => $proof, 'execution' => $execution,
            'receipt' => (object) ['manifest' => $manifest, 'keyId' => $this->signer->keyId(), 'signature' => $this->signer->sign($manifest)],
        ];
    }
}
