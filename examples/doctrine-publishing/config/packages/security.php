<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use VerifiedHumanApproval\Example\Publishing\Entity\User;
use VerifiedHumanApproval\Example\Publishing\Security\AgentTokenAuthenticator;

return static function (ContainerConfigurator $container): void {
    $container->extension('security', [
        // Quick hashes in the tests only, as Symfony's own recipe sets them.
        'password_hashers' => [User::class => 'test' === $container->env() ? ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10] : 'auto'],
        'providers' => ['accounts' => ['entity' => ['class' => User::class, 'property' => 'id']]],
        'firewalls' => [
            // An agent: its bearer token on every request, never a session. Example-grade: see AgentTokenAuthenticator.
            'mcp' => [
                'pattern' => '^/mcp(?:$|/)', 'stateless' => true, 'provider' => 'accounts',
                'custom_authenticators' => [AgentTokenAuthenticator::class], 'entry_point' => AgentTokenAuthenticator::class,
            ],
            // The person, in their own browser. The review pages need this session: only there does their passkey approve.
            'main' => [
                'provider' => 'accounts',
                'form_login' => ['login_path' => 'login', 'check_path' => 'login', 'enable_csrf' => true, 'default_target_path' => 'editor'],
                'login_throttling' => ['max_attempts' => 5, 'interval' => '1 minute'],
                'logout' => ['path' => 'logout', 'target' => 'home', 'enable_csrf' => true],
            ],
        ],
        'access_control' => [
            // The public page, and signing in.
            ['path' => '^/(login)?$', 'roles' => 'PUBLIC_ACCESS'],
            // The bundle's review pages open without a sign-in, for its one-time phone link; their controller still
            // requires the signed-in person, or a browser session scoped to that one request.
            ['request_matcher' => 'verified_human_approval.review_request_matcher', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/', 'roles' => 'ROLE_USER'],
        ],
    ]);
};
