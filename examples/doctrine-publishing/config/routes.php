<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    // The public page, the newsroom and the account pages.
    $routes->import('../src/Controller/', 'attribute');
    // The bundle's review pages, receipts, receipt keys and public verification page, under /approval.
    $routes->import('@VerifiedHumanApprovalBundle/config/routes.php');
    // The MCP endpoint, /mcp.
    $routes->import('.', 'mcp');
};
