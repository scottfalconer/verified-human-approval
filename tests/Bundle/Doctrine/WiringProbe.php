<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle\Doctrine;

use VerifiedHumanApproval\Core\ApprovalHistory;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Audit\AuditStore;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Doctrine\Orm\ProtectedEntityListener;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\CredentialRepository;
use VerifiedHumanApproval\WebAuthn\RevocableCredentialRepository;

/** What an application's own services receive when they autowire the classes the bundle aliases. */
final readonly class WiringProbe
{
    public function __construct(
        public DbalTransaction $transaction,
        public ApprovalHistory $history,
        public AuditStore $auditStore,
        public AuditLog $auditLog,
        public RevocableCredentialRepository $rawCredentials,
        public CredentialRepository $credentials,
        public AnchoredCredentialRepository $anchored,
        public ProtectedEntityListener $protection,
    ) {
    }

    /** A synthetic receipt signer from a public seed. */
    public static function signer(): SodiumEvidenceSigner
    {
        return new SodiumEvidenceSigner('kernel-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('k', 32))));
    }
}
