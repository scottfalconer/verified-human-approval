<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // In DoctrineBundle 2, opt into native lazy objects on PHP 8.4+ and explicit route entity mapping.
    // DoctrineBundle 3 uses those defaults and deprecates the former opt-in settings.
    $legacyOrm = version_compare(InstalledVersions::getVersion('doctrine/doctrine-bundle') ?? '0', '3.0', '<')
        ? ['enable_native_lazy_objects' => \PHP_VERSION_ID >= 80400, 'controller_resolver' => ['auto_mapping' => false]]
        : [];
    $container->extension('doctrine', [
        // SQLite by default. For PostgreSQL or MySQL, set DATABASE_URL with its charset, as Symfony's recipe does:
        // postgresql://app:secret@127.0.0.1:5432/newsroom?charset=utf8 or mysql://app:secret@127.0.0.1:3306/newsroom?charset=utf8mb4
        'dbal' => ['url' => '%env(resolve:DATABASE_URL)%'],
        'orm' => [
            ...$legacyOrm,
            'mappings' => [
                'Publishing' => ['type' => 'attribute', 'dir' => '%kernel.project_dir%/src/Entity', 'prefix' => 'VerifiedHumanApproval\Example\Publishing\Entity'],
            ],
        ],
    ]);
};
