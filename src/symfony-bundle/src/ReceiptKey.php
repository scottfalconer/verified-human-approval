<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;

/**
 * What anyone needs to check this site's receipts without trusting its records: the public key that signs
 * them, and the passkey relying party and origin that a receipt's passkey signature was made for. The bundle
 * publishes it at a fixed address, as the site-key file vendor/bin/verify-evidence.php takes. Someone who
 * saves it once can tell later if the site's key, or a receipt signed with it, has changed.
 */
final readonly class ReceiptKey
{
    public const FORMAT = 'verified-human-approval/receipt-key/v1';

    public function __construct(private EvidenceSigner $signer, private string $publicOrigin, private ?string $relyingPartyId = null)
    {
    }

    /** @return array{format: string, keyId: string, publicKey: string, rpId: string, origin: string} */
    public function document(): array
    {
        $origin = rtrim($this->publicOrigin, '/');

        return [
            'format' => self::FORMAT,
            'keyId' => $this->signer->keyId(),
            'publicKey' => $this->signer->publicKey(),
            // Passkeys are usually bound to the site's own host; a site using a parent domain configures it.
            'rpId' => $this->relyingPartyId ?? (string) parse_url($origin, \PHP_URL_HOST),
            'origin' => $origin,
        ];
    }
}
