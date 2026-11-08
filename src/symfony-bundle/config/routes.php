<?php

declare(strict_types=1);

use VerifiedHumanApproval\Bundle\Controller\ApprovalController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('verified_human_approval_script', '/approval/assets/approval.js')->controller([ApprovalController::class, 'script'])->methods(['GET']);
    $routes->add('verified_human_approval_styles', '/approval/assets/approval.css')->controller([ApprovalController::class, 'styles'])->methods(['GET']);
    $routes->add('verified_human_approval_handoff', '/approval/handoff/{token}')->controller([ApprovalController::class, 'handoff'])->methods(['GET']);
    $routes->add('verified_human_approval_handoff_continue', '/approval/handoff/{token}')->controller([ApprovalController::class, 'handoffContinue'])->methods(['POST']);
    $routes->add('verified_human_approval_review', '/approval/{id}')->controller([ApprovalController::class, 'review'])->methods(['GET']);
    $routes->add('verified_human_approval_status', '/approval/{id}/status')->controller([ApprovalController::class, 'status'])->methods(['GET']);
    $routes->add('verified_human_approval_options', '/approval/{id}/options')->controller([ApprovalController::class, 'options'])->methods(['POST']);
    $routes->add('verified_human_approval_complete', '/approval/{id}/complete')->controller([ApprovalController::class, 'complete'])->methods(['POST']);
    $routes->add('verified_human_approval_cancel', '/approval/{id}/cancel')->controller([ApprovalController::class, 'cancel'])->methods(['POST']);
};
