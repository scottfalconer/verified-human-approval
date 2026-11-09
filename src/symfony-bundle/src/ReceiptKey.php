<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;

/**
 * What anyone needs to check this site's receipts without trusting its records: the public keys that sign
 * them, and the passkey relying party and origin that a receipt's passkey signature was made for. The bundle
 * publishes, at fixed addresses, both files vendor/bin/verify-evidence.php takes: the key set, every key the
 * site has signed with and the window each signed in, and the earlier one-key file, which names the current
 * key only. Someone who saves the key set can tell later if the site's keys, or a receipt signed with one of
 * them, changed.
 */
final readonly class ReceiptKey
{
    /** The one-key file: the current signer's key, without a window. */
    public const FORMAT = TrustedKeys::SINGLE_KEY_FORMAT;

    public function __construct(
        private EvidenceSigner $signer,
        private string $publicOrigin,
        private ?string $relyingPartyId = null,
        // Every trusted key. Without it, the current signer's key alone, without a window.
        private ?TrustedKeys $keys = null,
    ) {
    }

    /** @return array{format: string, keyId: string, publicKey: string, rpId: string, origin: string} */
    public function document(): array
    {
        return ['format' => self::FORMAT, 'keyId' => $this->signer->keyId(), 'publicKey' => $this->signer->publicKey()] + $this->site();
    }

    /**
     * The key set: the key that signs now, and every key it replaced, each with the window it signed in.
     *
     * @return array{format: string, currentKeyId: string, keys: non-empty-list<array{keyId: string, publicKey: string, validFrom: ?string, validUntil: ?string}>, rpId: string, origin: string}
     */
    public function keySet(): array
    {
        $keys = $this->keys ?? TrustedKeys::single($this->signer->keyId(), $this->signer->publicKey());

        return ['format' => TrustedKeys::FORMAT, 'currentKeyId' => $this->signer->keyId(), 'keys' => $keys->toArray()] + $this->site();
    }

    /** @return array{rpId: string, origin: string} */
    private function site(): array
    {
        $origin = rtrim($this->publicOrigin, '/');

        return [
            // Passkeys are usually bound to the site's own host; a site using a parent domain configures it.
            'rpId' => $this->relyingPartyId ?? (string) parse_url($origin, \PHP_URL_HOST),
            'origin' => $origin,
        ];
    }
}
