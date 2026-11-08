<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Evidence;

use VerifiedHumanApproval\Core\{ApprovalException, ApprovalRequest, CanonicalJson, ChallengeBinding};

/** Verifies the application's signed report. A WebAuthn verifier separately verifies human-credential approval. */
final class ReceiptVerifier
{
    /**
     * Trust keys must be supplied out-of-band. No public key contained in evidence is accepted.
     * @return array{request: ApprovalRequest, proof: ?array, execution: array, applicationSignatureValid: true}
     */
    public function verify(array $bundle, string $trustedKeyId, string $trustedPublicKey): array
    {
        try {
            if (($bundle['format'] ?? null) !== 'verified-human-approval/evidence/v1' || !is_string($bundle['proposal'] ?? null)) {
                throw new \UnexpectedValueException('Unknown evidence format.');
            }
            $proposal = CanonicalJson::map(CanonicalJson::decode($bundle['proposal']));
            $digest = hash('sha256', $bundle['proposal']);
            $request = new ApprovalRequest($proposal['id'], $bundle['proposal'], $digest);
            $receipt = CanonicalJson::map($bundle['receipt']);
            $proof = $bundle['proof'] === null ? null : CanonicalJson::map($bundle['proof']);
            $execution = CanonicalJson::map($bundle['execution']);
            $expected = CanonicalJson::encode([
                'format' => 'verified-human-approval/receipt/v1', 'keyId' => $trustedKeyId, 'proposalDigest' => $digest,
                'proofDigest' => hash('sha256', CanonicalJson::encode($bundle['proof'])),
                'executionDigest' => hash('sha256', CanonicalJson::encode($bundle['execution'])),
            ]);
            if ($receipt['keyId'] !== $trustedKeyId || !hash_equals($expected, $receipt['manifest'])
                || $execution['format'] !== 'verified-human-approval/execution/v1'
                || $execution['requestId'] !== $request->id || !hash_equals($digest, $execution['proposalDigest'])
                || CanonicalJson::encode($execution['actor']) !== CanonicalJson::encode((object) $request->actor()->toArray())
                || $execution['actionId'] !== $request->prepared()->actionId || $execution['actionVersion'] !== $request->prepared()->version
                || CanonicalJson::encode($execution['target']) !== CanonicalJson::encode((object) $request->prepared()->target)
            ) {
                throw new \UnexpectedValueException('Evidence components do not describe the same action.');
            }
            if ($proof === null) {
                if ($proposal['policy']->required !== false || $execution['approval'] !== 'not_required') {
                    throw new \UnexpectedValueException('Required approval proof is missing.');
                }
            } else {
                if ($proof['format'] !== 'verified-human-approval/proof/v1' || $proof['proposalDigest'] !== $digest
                    || $execution['approval'] !== 'user_verified'
                    || !hash_equals(ChallengeBinding::encode(ChallengeBinding::challenge($digest, ChallengeBinding::decode($proof['nonce']))), $proof['challenge'])
                ) {
                    throw new \UnexpectedValueException('The proof is not bound to the proposal.');
                }
            }
            if (!\is_string($receipt['signature'] ?? null) || !Receipt::signatureValid($trustedPublicKey, $receipt['manifest'], $receipt['signature'])) {
                throw new \UnexpectedValueException('The trusted application signature is invalid.');
            }
            return ['request' => $request, 'proof' => $proof, 'execution' => $execution, 'applicationSignatureValid' => true];
        } catch (\Throwable $error) {
            throw new ApprovalException('invalid_evidence', 'The evidence does not verify with the supplied trusted application key.', $error);
        }
    }
}
