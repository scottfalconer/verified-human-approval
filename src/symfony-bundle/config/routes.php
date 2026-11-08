<?php

declare(strict_types=1);

use VerifiedHumanApproval\Bundle\SecurityHeadersSubscriber;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * The review pages. The browser script and stylesheet are not routes: they are in the bundle's
 * public/ directory, which assets:install publishes under bundles/verifiedhumanapproval/.
 */
return static function (RoutingConfigurator $routes): void {
    $controller = 'verified_human_approval.approval_controller::';
    $headers = [SecurityHeadersSubscriber::ROUTE_DEFAULT => true];
    $request = ['id' => '[A-Za-z0-9_-]{1,128}'];

    $routes->add('verified_human_approval_handoff', '/approval/handoff/{token}')->controller($controller.'handoff')->methods(['GET'])->defaults($headers);
    $routes->add('verified_human_approval_handoff_continue', '/approval/handoff/{token}')->controller($controller.'handoffContinue')->methods(['POST'])->defaults($headers);
    $routes->add('verified_human_approval_review', '/approval/{id}')->controller($controller.'review')->methods(['GET'])->defaults($headers)->requirements($request);
    $routes->add('verified_human_approval_status', '/approval/{id}/status')->controller($controller.'status')->methods(['GET'])->defaults($headers)->requirements($request);
    $routes->add('verified_human_approval_options', '/approval/{id}/options')->controller($controller.'options')->methods(['POST'])->defaults($headers)->requirements($request);
    $routes->add('verified_human_approval_complete', '/approval/{id}/complete')->controller($controller.'complete')->methods(['POST'])->defaults($headers)->requirements($request);
    $routes->add('verified_human_approval_cancel', '/approval/{id}/cancel')->controller($controller.'cancel')->methods(['POST'])->defaults($headers)->requirements($request);
};
