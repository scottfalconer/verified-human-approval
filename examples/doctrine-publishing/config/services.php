<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    // The application. Autoconfiguration registers the four actions with the approval service (they implement
    // ActionAdapter), the controllers, the MCP tools and the setup command.
    $services->load('VerifiedHumanApproval\Example\Publishing\\', '../src/')
        ->exclude(['../src/Entity/', '../src/PublishingKernel.php']);

    // The key that signs receipts and the audit log, kept outside the database: in var/settings.php here, in your
    // secret store in production.
    $services->set(SodiumEvidenceSigner::class)
        ->factory([SodiumEvidenceSigner::class, 'fromEncoded'])
        ->args(['%env(APPROVAL_RECEIPT_KEY_ID)%', '%env(APPROVAL_RECEIPT_SECRET_KEY)%']);

    // Passkeys belong to the site's own origin. The verifier reads them through the bundle's anchored repository,
    // which accepts only a passkey that the signed audit log vouches for.
    $services->set(Configuration::class)->args(['%env(key:host:url:APP_ORIGIN)%', '%env(APP_ORIGIN)%']);
    $services->set(WebAuthnVerifier::class);
};
