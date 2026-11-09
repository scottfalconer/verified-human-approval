<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Example\Publishing\Approval\EditorialPermissions;
use VerifiedHumanApproval\Example\Publishing\Approval\EditorialPolicy;
use VerifiedHumanApproval\Example\Publishing\Security\ApprovalActors;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

return static function (ContainerConfigurator $container): void {
    $container->extension('verified_human_approval', [
        // Review links are built from it, never from a request's Host header. Passkeys belong to its host.
        'public_origin' => '%env(APP_ORIGIN)%',
        'actor_provider' => ApprovalActors::class,
        'policy_provider' => EditorialPolicy::class,
        'authorizer' => EditorialPermissions::class,
        'verifier' => WebAuthnVerifier::class,
        // Each receipt is signed as its change commits, in the same transaction, and so is the audit log that
        // vouches for every passkey. valid_from is when this key began signing.
        'receipt_signer' => SodiumEvidenceSigner::class,
        'receipt_keys' => ['valid_from' => '%env(APPROVAL_RECEIPT_KEY_VALID_FROM)%'],
        // The approval store, the signed audit log and the passkeys on the entity manager's own connection, so an
        // approved change, its proof and its receipt commit in one transaction. The bundle also registers the
        // tripwire that guards Article's #[RequiresApproval] fields on that connection's flushes.
        'persistence' => ['driver' => 'doctrine', 'connection' => 'default'],
        // The badge on the public page links to what was approved, at /approval/{id}/verified.
        'public_verification' => true,
    ]);
};
