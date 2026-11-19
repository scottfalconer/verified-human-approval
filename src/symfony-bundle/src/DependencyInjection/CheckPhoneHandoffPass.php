<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool;
use VerifiedHumanApproval\Mcp\ApprovalCard;

/**
 * Refuses a container in which an MCP server serves the approval card but not the phone tool the card calls.
 *
 * While phone_handoff is on, the card offers "Approve on your phone" and calls ApprovalHandoffTool for the code, and
 * a server without the tool would answer every such call "Tool not found". The SDK gives a tool no way to learn which
 * server is serving it, so this is checked once, as the container is built: in what symfony/mcp-bundle tells each
 * server's SDK builder to serve, however the server's registry lists it.
 *
 * @internal the bundle registers it
 */
final class CheckPhoneHandoffPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Defined only while phone_handoff is on, which is when the card is told to call it.
        if (!$container->hasDefinition('verified_human_approval.handoff_tool')) {
            return;
        }
        $without = [];
        foreach ($container->findTaggedServiceIds('mcp.server.builder') as $id => $tags) {
            // Each element as the SDK's Builder takes it: addTool(handler, name, …) and addResource(handler, uri, …).
            $serves = array_map(static fn (array $call): array => [$call[0], $call[1][1] ?? null], $container->getDefinition($id)->getMethodCalls());
            if (\in_array(['addResource', ApprovalCard::URI], $serves, true) && !\in_array(['addTool', ApprovalHandoffTool::NAME], $serves, true)) {
                $without[] = (string) ($tags[0]['server'] ?? $id);
            }
        }
        if ([] === $without) {
            return;
        }

        $servers = 1 === \count($without) ? 'MCP server "'.$without[0].'" serves' : 'MCP servers "'.implode('", "', $without).'" serve';
        $registries = implode(', ', array_map(static fn (string $server): string => 'mcp.servers.'.$server.'.registry.tools', $without));

        throw new LogicException(\sprintf('The %s the approval card without %s, which the card calls for "Approve on your phone". List the tool under %s, or set verified_human_approval.phone_handoff to false.', $servers, ApprovalHandoffTool::class, $registries));
    }
}
