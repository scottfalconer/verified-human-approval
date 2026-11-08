<?php

declare(strict_types=1);

namespace ExactApproval\Symfony;

use ExactApproval\Core\ActionAdapter;
use ExactApproval\Core\ActionRegistry;
use ExactApproval\Core\ApprovalService;
use ExactApproval\Core\ExecutionGuard;
use ExactApproval\Symfony\Controller\ApprovalController;
use ExactApproval\Mcp\ApprovalFlow;
use ExactApproval\Mcp\ResultPresenter;
use ExactApproval\Mcp\UrlPrompt;
use ExactApproval\Symfony\Mcp\ApprovalCardApp;
use ExactApproval\Symfony\Mcp\ApprovalStatusTool;
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

final class ExactApprovalBundle extends AbstractBundle
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
            ->scalarNode('clock')->defaultValue('exact_approval.clock')->end()
            ->integerNode('attempts_per_minute')->min(2)->max(1000)->defaultValue(30)->end()
            ->scalarNode('rate_limiter')->defaultValue('exact_approval.limiter')->end()
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
        $builder->registerForAutoconfiguration(ActionAdapter::class)->addTag('exact_approval.action');
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set('exact_approval.clock', NativeClock::class);
        $services->alias(ActorProvider::class, $config['actor_provider']);
        $services->set(ActionRegistry::class)->arg('$actions', tagged_iterator('exact_approval.action'));
        $services->set(ExecutionGuard::class);
        $services->set(ApprovalService::class)->args([
            service(ActionRegistry::class), service($config['policy_provider']),
            service($config['authorizer']), service($config['store']),
            service($config['verifier']), service($config['clock']), service(ExecutionGuard::class),
        ]);
        $services->set(ApprovalPresenter::class)->arg('$publicOrigin', $config['public_origin']);
        $services->alias(ResultPresenter::class, ApprovalPresenter::class);
        $services->set('exact_approval.limiter.storage', CacheStorage::class)->args([service('cache.app')]);
        $services->set('exact_approval.limiter.lock_store', FlockStore::class)->args(['%kernel.cache_dir%/exact-approval-locks']);
        $services->set('exact_approval.limiter.lock_factory', LockFactory::class)->args([service('exact_approval.limiter.lock_store')]);
        $services->set('exact_approval.limiter', RateLimiterFactory::class)->args([
            ['id' => 'exact-action-approval', 'policy' => 'fixed_window', 'limit' => $config['attempts_per_minute'], 'interval' => '1 minute'],
            service('exact_approval.limiter.storage'), service('exact_approval.limiter.lock_factory'),
        ]);
        $services->set(ApprovalController::class)->arg('$limiter', service($config['rate_limiter']))->public()->tag('controller.service_arguments');
        $services->set(ApprovalStatusTool::class);
        $services->set(ApprovalCardApp::class);
        $services->set(UrlPrompt::class)->args([$config['url_elicitation']['enabled'], $config['url_elicitation']['clients']]);
        $services->set(ApprovalFlow::class);
        $services->set(SecurityHeadersSubscriber::class);
    }
}
