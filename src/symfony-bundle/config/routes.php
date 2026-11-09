<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use VerifiedHumanApproval\Bundle\SecurityHeadersSubscriber;

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
    // Public: the keys that check this site's receipts, as a key set and as the earlier one-key file. A request
    // id never contains a dot, so neither can clash.
    $routes->add('verified_human_approval_receipt_keys', '/approval/receipt-keys.json')->controller($controller.'receiptKeys')->methods(['GET']);
    $routes->add('verified_human_approval_receipt_key', '/approval/receipt-key.json')->controller($controller.'receiptKey')->methods(['GET']);
    $routes->add('verified_human_approval_review', '/approval/{id}')->controller($controller.'review')->methods(['GET'])->defaults($headers)->requirements($request);
    $routes->add('verified_human_approval_status', '/approval/{id}/status')->controller($controller.'status')->methods(['GET'])->defaults($headers)->requirements($request);
    $routes->add('verified_human_approval_options', '/approval/{id}/options')->controller($controller.'options')->methods(['POST'])->defaults($headers)->requirements($request);
    $routes->add('verified_human_approval_complete', '/approval/{id}/complete')->controller($controller.'complete')->methods(['POST'])->defaults($headers)->requirements($request);
    $routes->add('verified_human_approval_cancel', '/approval/{id}/cancel')->controller($controller.'cancel')->methods(['POST'])->defaults($headers)->requirements($request);
    $routes->add('verified_human_approval_evidence', '/approval/{id}/evidence')->controller($controller.'evidence')->methods(['GET'])->defaults($headers)->requirements($request);
};
