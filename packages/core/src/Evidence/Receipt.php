<?php

declare(strict_types=1);

namespace ExactApproval\Core\Evidence;

use ExactApproval\Core\CanonicalJson;
use ExactApproval\Core\ChallengeBinding;

/**
 * The application's signed receipt for one executed proposal: an Ed25519 signature over the digests
 * of the proposal, the person's proof and the execution record. ApprovalService signs it inside the
 * completing transaction, so a later edit to any of the three no longer matches the signature.
 */
final class Receipt
{
    private const PREFIX = "ExactApproval/Receipt/v1\0";

    /** The exact bytes that are signed. */
    public static function manifest(string $keyId, string $proposalDigest, ?array $proof, array $execution): string
    {
        return CanonicalJson::encode([
            'format' => 'exact-approval/receipt/v1', 'keyId' => $keyId,
            'proposalDigest' => $proposalDigest,
            'proofDigest' => hash('sha256', CanonicalJson::encode($proof === null ? null : (object) $proof)),
            'executionDigest' => hash('sha256', CanonicalJson::encode((object) $execution)),
        ]);
    }

    /** @return array{manifest: string, keyId: string, signature: string} */
    public static function sign(EvidenceSigner $signer, string $proposalDigest, ?array $proof, array $execution): array
    {
        $manifest = self::manifest($signer->keyId(), $proposalDigest, $proof, $execution);

        return ['manifest' => $manifest, 'keyId' => $signer->keyId(), 'signature' => $signer->sign($manifest)];
    }

    /** The domain-separated message an evidence signer signs. */
    public static function message(string $manifest): string
    {
        return self::PREFIX.$manifest;
    }

    /** Checks a base64url Ed25519 signature made by an evidence signer, against a trusted base64url public key. */
    public static function signatureValid(string $trustedPublicKey, string $manifest, string $signature): bool
    {
        try {
            $key = ChallengeBinding::decode($trustedPublicKey);
            $bytes = ChallengeBinding::decode($signature);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return \strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && \strlen($bytes) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($bytes, self::message($manifest), $key);
    }
}
