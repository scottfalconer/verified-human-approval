<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use Psr\Clock\ClockInterface;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Audit\AuditStore;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;

/**
 * The signed audit log the bundle wires with persistence: entries signed with the receipt signer and checked
 * against a separately configured public key, or the signer's own when none is configured.
 *
 * @internal
 */
final class AuditLogFactory
{
    public static function create(AuditStore $store, EvidenceSigner $signer, ClockInterface $clock, ?string $trustedPublicKey = null): AuditLog
    {
        return new AuditLog($store, $signer, $trustedPublicKey ?? $signer->publicKey(), $clock);
    }
}
