<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('doctrine', [
        'dbal' => ['url' => '%env(resolve:DATABASE_URL)%'],
        'orm' => ['enable_native_lazy_objects' => \PHP_VERSION_ID >= 80400,
            'controller_resolver' => ['auto_mapping' => false],
            'mappings' => ['App' => ['type' => 'attribute', 'dir' => '%kernel.project_dir%/src/Entity', 'prefix' => 'App\\Entity']],
        ],
    ]);
};
