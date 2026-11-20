<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\DependencyInjection;

use Symfony\AI\McpBundle\Controller\McpController;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use VerifiedHumanApproval\Bundle\Security\McpScopeChallengeSubscriber;

/**
 * Refuses a container in which McpScopeChallengeSubscriber would challenge no request.
 *
 * The subscriber knows the MCP endpoint by symfony/mcp-bundle's controller class. Where no service is one, it would
 * let every request through to the tools without a word: in an application that serves MCP over no HTTP transport,
 * and under a release of the MCP bundle that serves its endpoint with another controller.
 *
 * @internal the bundle registers it
 */
final class CheckScopeChallengePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $classes = array_map(static fn (Definition $definition): ?string => $definition->getClass(), $container->getDefinitions());
        if (\in_array(McpScopeChallengeSubscriber::class, $classes, true) && !\in_array(McpController::class, $classes, true)) {
            throw new LogicException(\sprintf('%s knows the MCP endpoint by symfony/mcp-bundle\'s %s, and no service is one, so it would challenge no request. Serve an MCP server over HTTP, or remove the subscriber.', McpScopeChallengeSubscriber::class, McpController::class));
        }
    }
}
