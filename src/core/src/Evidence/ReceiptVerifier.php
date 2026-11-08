<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Evidence;

use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;

/** Verifies the application's signed report. A WebAuthn verifier separately verifies human-credential approval. */
final class ReceiptVerifier
{
    /**
     * Trust keys must be supplied out-of-band. No public key contained in evidence is accepted.
     *
     * @param array<string, mixed> $bundle
     *
     * @return array{request: ApprovalRequest, proof: array<string, mixed>|null, execution: array<string, mixed>, applicationSignatureValid: true, receiptSignedAt: Receipt::COMMIT|Receipt::EXPORT}
     */
    public function verify(array $bundle, string $trustedKeyId, string $trustedPublicKey): array
    {
        try {
            if (($bundle['format'] ?? null) !== EvidenceExporter::FORMAT || !\is_string($bundle['proposal'] ?? null)) {
                throw new \UnexpectedValueException('Unknown evidence format.');
            }
            $proposal = CanonicalJson::map(CanonicalJson::decode($bundle['proposal']));
            $digest = hash('sha256', $bundle['proposal']);
            $request = new ApprovalRequest($proposal['id'], $bundle['proposal'], $digest);
            $receipt = CanonicalJson::map($bundle['receipt']);
            $proof = null === $bundle['proof'] ? null : CanonicalJson::map($bundle['proof']);
            $execution = CanonicalJson::map($bundle['execution']);
            // When it was signed is part of what was signed: it cannot be changed without breaking the signature.
            $signedAt = \is_string($receipt['manifest'] ?? null) ? (CanonicalJson::map(CanonicalJson::decode($receipt['manifest']))['signedAt'] ?? null) : null;
            if (!\in_array($signedAt, [Receipt::COMMIT, Receipt::EXPORT], true)) {
                throw new \UnexpectedValueException('The receipt does not say when it was signed.');
            }
            $expected = CanonicalJson::encode([
                'format' => Receipt::FORMAT, 'keyId' => $trustedKeyId, 'signedAt' => $signedAt, 'proposalDigest' => $digest,
                'proofDigest' => hash('sha256', CanonicalJson::encode($bundle['proof'])),
                'executionDigest' => hash('sha256', CanonicalJson::encode($bundle['execution'])),
            ]);
            if ($receipt['keyId'] !== $trustedKeyId || !hash_equals($expected, $receipt['manifest'])
                || 'verified-human-approval/execution/v1' !== $execution['format']
                || $execution['requestId'] !== $request->id || !hash_equals($digest, $execution['proposalDigest'])
                || CanonicalJson::encode($execution['actor']) !== CanonicalJson::encode((object) $request->actor()->toArray())
                || $execution['actionId'] !== $request->prepared()->actionId || $execution['actionVersion'] !== $request->prepared()->version
                || CanonicalJson::encode($execution['target']) !== CanonicalJson::encode((object) $request->prepared()->target)
            ) {
                throw new \UnexpectedValueException('Evidence components do not describe the same action.');
            }
            if (null === $proof) {
                if (false !== $proposal['policy']->required || 'not_required' !== $execution['approval']) {
                    throw new \UnexpectedValueException('Required approval proof is missing.');
                }
            } else {
                if ('verified-human-approval/proof/v1' !== $proof['format'] || $proof['proposalDigest'] !== $digest
                    || 'user_verified' !== $execution['approval']
                    || !hash_equals(ChallengeBinding::encode(ChallengeBinding::challenge($digest, ChallengeBinding::decode($proof['nonce']))), $proof['challenge'])
                ) {
                    throw new \UnexpectedValueException('The proof is not bound to the proposal.');
                }
            }
            if (!\is_string($receipt['signature'] ?? null) || !Receipt::signatureValid($trustedPublicKey, $receipt['manifest'], $receipt['signature'])) {
                throw new \UnexpectedValueException('The trusted application signature is invalid.');
            }

            return ['request' => $request, 'proof' => $proof, 'execution' => $execution, 'applicationSignatureValid' => true, 'receiptSignedAt' => $signedAt];
        } catch (\Throwable $error) {
            throw new ApprovalException('invalid_evidence', 'The evidence does not verify with the supplied trusted application key.', $error);
        }
    }
}
