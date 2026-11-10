<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('env(APP_STATE_DIR)', '%kernel.project_dir%/var/state');
    $container->extension('framework', [
        'secret' => '%env(APP_SECRET)%', 'http_method_override' => false,
        'handle_all_throwables' => true, 'php_errors' => ['log' => true],
        'csrf_protection' => true, 'assets' => [], 'translator' => ['fallbacks' => ['en']],
        'session' => ['handler_id' => null, 'save_path' => '%env(resolve:APP_STATE_DIR)%/sessions',
            'cookie_secure' => 'auto', 'cookie_httponly' => true, 'cookie_samesite' => 'lax'],
        'lock' => 'flock', 'cache' => ['directory' => '%env(resolve:APP_STATE_DIR)%/pools'],
        'router' => ['utf8' => true], 'property_info' => ['with_constructor_extractor' => true],
    ]);
    if ('test' === $container->env()) {
        $container->extension('framework', ['test' => true, 'session' => ['storage_factory_id' => 'session.storage.factory.mock_file']]);
    }
};
