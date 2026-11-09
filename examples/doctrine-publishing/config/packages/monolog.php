<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // To var/log/<environment>.log; the MCP bundle logs to its own "mcp" channel there too.
    $container->extension('monolog', ['handlers' => [
        'main' => ['type' => 'stream', 'path' => '%kernel.logs_dir%/%kernel.environment%.log', 'level' => 'info', 'channels' => ['!event']],
    ]]);
};
