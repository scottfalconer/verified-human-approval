<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Evidence;

use VerifiedHumanApproval\Core\ChallengeBinding;

final readonly class SodiumEvidenceSigner implements EvidenceSigner
{
    public function __construct(private string $id, #[\SensitiveParameter] private string $secretKey)
    {
        if ('' === $id || \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== \strlen($secretKey)) {
            throw new \InvalidArgumentException('An Ed25519 signer needs a key identifier and a 64-byte secret key.');
        }
    }

    public function keyId(): string
    {
        return $this->id;
    }

    /** Keep binary secret bytes out of container configuration and parameter interpolation. */
    public static function fromEncoded(string $id, #[\SensitiveParameter] string $encodedSecret): self
    {
        return new self($id, ChallengeBinding::decode($encodedSecret));
    }

    public function sign(string $manifest): string
    {
        return ChallengeBinding::encode(sodium_crypto_sign_detached(Receipt::message($manifest), $this->secretKey));
    }

    public function publicKey(): string
    {
        return ChallengeBinding::encode(sodium_crypto_sign_publickey_from_secretkey($this->secretKey));
    }
}
