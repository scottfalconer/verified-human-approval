<?php

declare(strict_types=1);

use App\Approval\CurrentAuthority;
use App\Approval\EditorialPolicy;
use App\Security\ApprovalActors;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

return static function (ContainerConfigurator $container): void {
    $container->extension('verified_human_approval', [
        'public_origin' => '%env(APP_ORIGIN)%', 'actor_provider' => ApprovalActors::class,
        'policy_provider' => EditorialPolicy::class, 'authorizer' => CurrentAuthority::class,
        'verifier' => WebAuthnVerifier::class, 'receipt_signer' => SodiumEvidenceSigner::class,
        'receipt_keys' => ['valid_from' => '%env(APPROVAL_RECEIPT_KEY_VALID_FROM)%'],
        'persistence' => ['driver' => 'doctrine', 'connection' => 'default'], 'public_verification' => true,
    ]);
};
