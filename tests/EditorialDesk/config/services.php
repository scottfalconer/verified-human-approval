<?php

declare(strict_types=1);
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    $services->load('App\\', '../src/')->exclude(['../src/Entity/', '../src/Kernel.php', '../src/Security/Account.php']);
    $services->set(SodiumEvidenceSigner::class)->factory([SodiumEvidenceSigner::class, 'fromEncoded'])
        ->args(['%env(APPROVAL_RECEIPT_KEY_ID)%', '%env(APPROVAL_RECEIPT_SECRET_KEY)%']);
    $services->set(Configuration::class)->args(['%env(key:host:url:APP_ORIGIN)%', '%env(APP_ORIGIN)%']);
    $services->set(WebAuthnVerifier::class);
    $services->set(App\Setup\Installer::class)->public()->arg('$origin', '%env(APP_ORIGIN)%');
};
