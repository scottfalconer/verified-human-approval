<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('doctrine', [
        // SQLite by default. For PostgreSQL or MySQL, set DATABASE_URL with its charset, as Symfony's recipe does:
        // postgresql://app:secret@127.0.0.1:5432/newsroom?charset=utf8 or mysql://app:secret@127.0.0.1:3306/newsroom?charset=utf8mb4
        'dbal' => ['url' => '%env(resolve:DATABASE_URL)%'],
        'orm' => [
            'mappings' => [
                'Publishing' => ['type' => 'attribute', 'dir' => '%kernel.project_dir%/src/Entity', 'prefix' => 'VerifiedHumanApproval\Example\Publishing\Entity'],
            ],
        ],
    ]);
};
