<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Evidence;

use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;

/**
 * The application's signed receipt for one executed proposal: an Ed25519 signature over the digests
 * of the proposal, the person's proof and the execution record, and over when it was signed.
 * ApprovalService signs it inside the completing transaction ("commit"), so a later edit to any of
 * the three no longer matches. A receipt signed at export ("export") vouches only for the record as
 * it was then, and says so in what it signs.
 */
final class Receipt
{
    public const FORMAT = 'verified-human-approval/receipt/v2';
    public const COMMIT = 'commit';
    public const EXPORT = 'export';
    private const PREFIX = "VerifiedHumanApproval/Receipt/v1\0";

    /**
     * The exact bytes that are signed.
     *
     * @param array<string, mixed>|null $proof
     * @param array<string, mixed>      $execution
     * @param string                    $signedAt  self::COMMIT as the action commits, or self::EXPORT when evidence is exported
     */
    public static function manifest(string $keyId, string $proposalDigest, ?array $proof, array $execution, string $signedAt): string
    {
        if (!\in_array($signedAt, [self::COMMIT, self::EXPORT], true)) {
            throw new \InvalidArgumentException('A receipt is signed at commit or at export.');
        }

        return CanonicalJson::encode([
            'format' => self::FORMAT, 'keyId' => $keyId, 'signedAt' => $signedAt,
            'proposalDigest' => $proposalDigest,
            'proofDigest' => hash('sha256', CanonicalJson::encode(null === $proof ? null : (object) $proof)),
            'executionDigest' => hash('sha256', CanonicalJson::encode((object) $execution)),
        ]);
    }

    /**
     * @param array<string, mixed>|null $proof
     * @param array<string, mixed>      $execution
     * @param string                    $signedAt  self::COMMIT or self::EXPORT
     *
     * @return array{manifest: string, keyId: string, signature: string}
     */
    public static function sign(EvidenceSigner $signer, string $proposalDigest, ?array $proof, array $execution, string $signedAt): array
    {
        $manifest = self::manifest($signer->keyId(), $proposalDigest, $proof, $execution, $signedAt);

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

        return \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === \strlen($key) && \SODIUM_CRYPTO_SIGN_BYTES === \strlen($bytes)
            && sodium_crypto_sign_verify_detached($bytes, self::message($manifest), $key);
    }
}
