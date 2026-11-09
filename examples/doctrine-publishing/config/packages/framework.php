<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // Sessions and the cache pools that hold rate-limit counters and phone codes: state, not cache, so clearing the
    // cache keeps them.
    $container->parameters()->set('env(APP_STATE_DIR)', '%kernel.project_dir%/var/state');
    $container->extension('framework', [
        'secret' => '%env(APP_SECRET)%',
        'http_method_override' => false,
        'handle_all_throwables' => true,
        'php_errors' => ['log' => true],
        'session' => [
            'handler_id' => null, 'save_path' => '%env(resolve:APP_STATE_DIR)%/sessions',
            'cookie_secure' => 'auto', 'cookie_httponly' => true, 'cookie_samesite' => 'lax',
        ],
        'csrf_protection' => true,
        'cache' => ['directory' => '%env(resolve:APP_STATE_DIR)%/pools'],
        'router' => ['utf8' => true],
    ]);
    if ('test' === $container->env()) {
        $container->extension('framework', ['test' => true, 'session' => ['storage_factory_id' => 'session.storage.factory.mock_file']]);
    }
};
