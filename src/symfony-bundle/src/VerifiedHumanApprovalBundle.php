<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Bundle\Controller\ApprovalController;
use VerifiedHumanApproval\Mcp\ApprovalFlow;
use VerifiedHumanApproval\Mcp\ResultPresenter;
use VerifiedHumanApproval\Mcp\UrlPrompt;
use VerifiedHumanApproval\Bundle\Handoff\ApprovalHandoff;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalCardApp;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

final class VerifiedHumanApprovalBundle extends AbstractBundle
{
    public function getPath(): string { return \dirname(__DIR__); }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('public_origin')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('actor_provider')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('policy_provider')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('authorizer')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('store')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('verifier')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('clock')->defaultValue('verified_human_approval.clock')->end()
            ->integerNode('attempts_per_minute')->min(2)->max(1000)->defaultValue(30)->end()
            ->scalarNode('rate_limiter')->defaultValue('verified_human_approval.limiter')->end()
            ->scalarNode('receipt_signer')
                ->info('An VerifiedHumanApproval\\Core\\Evidence\\EvidenceSigner service. When set, each execution\'s receipt is signed inside the completing transaction, so later edits to the record show.')
                ->defaultNull()
            ->end()
            ->arrayNode('url_elicitation')
                ->info('Native URL-mode elicitation for pending approvals, on MCP 2026-07-28 or later. Off by default: the card and link always work, and some clients declare URL mode and then decline or drop it.')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultFalse()->end()
                    ->arrayNode('clients')
                        ->info('clientInfo names to prompt, such as "claude-code", or "*" for any client declaring URL mode.')
                        ->scalarPrototype()->cannotBeEmpty()->end()
                    ->end()
                ->end()
            ->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(ActionAdapter::class)->addTag('verified_human_approval.action');
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set('verified_human_approval.clock', NativeClock::class);
        $services->alias(ActorProvider::class, $config['actor_provider']);
        $services->set(ActionRegistry::class)->arg('$actions', tagged_iterator('verified_human_approval.action'));
        $services->set(ExecutionGuard::class);
        $services->set(ApprovalService::class)->args([
            service(ActionRegistry::class), service($config['policy_provider']),
            service($config['authorizer']), service($config['store']),
            service($config['verifier']), service($config['clock']), service(ExecutionGuard::class),
            $config['receipt_signer'] === null ? null : service($config['receipt_signer']),
        ]);
        $services->set(ApprovalPresenter::class)->arg('$publicOrigin', $config['public_origin']);
        $services->alias(ResultPresenter::class, ApprovalPresenter::class);
        $services->set('verified_human_approval.limiter.storage', CacheStorage::class)->args([service('cache.app')]);
        $services->set('verified_human_approval.limiter.lock_store', FlockStore::class)->args(['%kernel.cache_dir%/verified-human-approval-locks']);
        $services->set('verified_human_approval.limiter.lock_factory', LockFactory::class)->args([service('verified_human_approval.limiter.lock_store')]);
        $services->set('verified_human_approval.limiter', RateLimiterFactory::class)->args([
            ['id' => 'verified-human-approval', 'policy' => 'fixed_window', 'limit' => $config['attempts_per_minute'], 'interval' => '1 minute'],
            service('verified_human_approval.limiter.storage'), service('verified_human_approval.limiter.lock_factory'),
        ]);
        $services->set(ApprovalController::class)->arg('$limiter', service($config['rate_limiter']))->public()->tag('controller.service_arguments');
        $services->set(ApprovalStatusTool::class);
        $services->set(ApprovalHandoff::class)->args([service('cache.app'), service('router'), $config['public_origin'], service('verified_human_approval.limiter.lock_factory')]);
        $services->set(ApprovalHandoffTool::class)->arg('$limiter', service($config['rate_limiter']));
        $services->set(ApprovalCardApp::class);
        $services->set(UrlPrompt::class)->args([$config['url_elicitation']['enabled'], $config['url_elicitation']['clients']]);
        $services->set(ApprovalFlow::class);
        $services->set(SecurityHeadersSubscriber::class);
    }
}
