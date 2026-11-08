<?php

declare(strict_types=1);

namespace Drupal\exact_approval_mcp;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Registers the approval tools through mcp_server's attribute-discovery seam.
 *
 * Discovered tools receive the SDK request context, which a tool plugin's execute() does not.
 * The request context carries the client's declared capabilities, the protocol revision and the
 * signed request state that the URL-mode elicitation's retry needs.
 */
final class ExactApprovalMcpServiceProvider extends ServiceProviderBase
{
    public function alter(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('mcp_server.server.factory')) {
            $container->getDefinition('mcp_server.server.factory')->addMethodCall('addDiscovery', [\dirname(__DIR__), ['src/Mcp']]);
        }
    }
}
