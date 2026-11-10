<?php

declare(strict_types=1);

use App\Security\Account;
use App\Security\AccountProvider;
use App\Security\AgentTokenAuthenticator;
use App\Security\BrowserEntryPoint;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('security', [
        'password_hashers' => [Account::class => 'test' === $container->env() ? ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10] : 'auto'],
        'providers' => ['accounts' => ['id' => AccountProvider::class]],
        'firewalls' => [
            'mcp' => ['pattern' => '^/mcp(?:$|/)', 'stateless' => true, 'provider' => 'accounts',
                'custom_authenticators' => [AgentTokenAuthenticator::class], 'entry_point' => AgentTokenAuthenticator::class],
            'main' => ['provider' => 'accounts', 'entry_point' => BrowserEntryPoint::class,
                'form_login' => ['login_path' => 'login', 'check_path' => 'login', 'enable_csrf' => true, 'default_target_path' => 'desk'],
                'login_throttling' => ['max_attempts' => 5, 'interval' => '1 minute'],
                'logout' => ['path' => 'logout', 'target' => 'home', 'enable_csrf' => true]],
        ],
        'access_control' => [
            ['path' => '^/(?:$|login$|pages/|assets/|bundles/)', 'roles' => 'PUBLIC_ACCESS'],
            ['request_matcher' => 'verified_human_approval.review_request_matcher', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/', 'roles' => 'ROLE_USER'],
        ],
    ]);
};
