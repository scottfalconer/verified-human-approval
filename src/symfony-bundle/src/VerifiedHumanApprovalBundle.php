<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use VerifiedHumanApproval\Bundle\Controller\ApprovalController;
use VerifiedHumanApproval\Bundle\Handoff\ApprovalHandoff;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalCardApp;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool;
use VerifiedHumanApproval\Bundle\Security\ReviewRequestMatcher;
use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Mcp\ApprovalFlow;
use VerifiedHumanApproval\Mcp\ResultPresenter;
use VerifiedHumanApproval\Mcp\UrlPrompt;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Every service is defined explicitly under a `verified_human_approval.` id, as Symfony's bundle
 * best practices ask; the classes an application injects are aliased to those ids, so autowiring
 * them in your own services keeps working.
 */
final class VerifiedHumanApprovalBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('public_origin')->isRequired()->cannotBeEmpty()
                ->info('The origin people reach the application at, such as https://app.example.com. Review links are built from it, never from request headers.')->end()
            ->scalarNode('actor_provider')->isRequired()->cannotBeEmpty()->info('Service id of your VerifiedHumanApproval\\Bundle\\ActorProvider.')->end()
            ->scalarNode('policy_provider')->isRequired()->cannotBeEmpty()->info('Service id of your VerifiedHumanApproval\\Core\\PolicyProvider: the administrator\'s rule.')->end()
            ->scalarNode('authorizer')->isRequired()->cannotBeEmpty()->info('Service id of your VerifiedHumanApproval\\Core\\Authorizer, which rechecks current permissions as the action executes.')->end()
            ->scalarNode('store')->isRequired()->cannotBeEmpty()->info('Service id of the VerifiedHumanApproval\\Core\\ApprovalStore, such as VerifiedHumanApproval\\Core\\Storage\\PdoApprovalStore.')->end()
            ->scalarNode('verifier')->isRequired()->cannotBeEmpty()->info('Service id of the VerifiedHumanApproval\\Core\\ApprovalVerifier, such as VerifiedHumanApproval\\WebAuthn\\WebAuthnVerifier.')->end()
            ->scalarNode('requester_label')->defaultNull()
                ->info('Service id of a VerifiedHumanApproval\\Bundle\\RequesterLabel that names who asked on the review page. Unset, a service aliased to that interface is used if there is one.')->end()
            ->scalarNode('clock')->defaultValue('clock')->info('Service id of a PSR-20 clock; the framework\'s "clock" by default.')->end()
            ->integerNode('attempts_per_minute')->min(2)->max(1000)->defaultValue(30)->info('Browser approval attempts per person per minute, across all requests.')->end()
            ->scalarNode('rate_limiter')->defaultValue('verified_human_approval.limiter')->info('Service id of a Symfony\\Component\\RateLimiter\\RateLimiterFactoryInterface, to replace the built-in budget with your own.')->end()
            ->scalarNode('receipt_signer')
                ->info('Service id of a VerifiedHumanApproval\\Core\\Evidence\\EvidenceSigner. When set, each execution\'s receipt is signed inside the completing transaction, so later edits to the record show.')
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

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Your own action adapters join the registry when they are autoconfigured, or when you tag them.
        $builder->registerForAutoconfiguration(ActionAdapter::class)->addTag('verified_human_approval.action');

        $services = $container->services();

        $services->alias('verified_human_approval.clock', $config['clock']);
        $services->alias('verified_human_approval.actor_provider', $config['actor_provider']);
        $services->alias(ActorProvider::class, 'verified_human_approval.actor_provider');

        $services->set('verified_human_approval.action_registry', ActionRegistry::class)
            ->args([tagged_iterator('verified_human_approval.action')]);
        $services->set('verified_human_approval.execution_guard', ExecutionGuard::class);
        $services->alias(ExecutionGuard::class, 'verified_human_approval.execution_guard');
        $services->set('verified_human_approval.approval_service', ApprovalService::class)->args([
            service('verified_human_approval.action_registry'),
            service($config['policy_provider']),
            service($config['authorizer']),
            service($config['store']),
            service($config['verifier']),
            service('verified_human_approval.clock'),
            service('verified_human_approval.execution_guard'),
            null === $config['receipt_signer'] ? null : service($config['receipt_signer']),
        ]);
        $services->alias(ApprovalService::class, 'verified_human_approval.approval_service');

        $services->set('verified_human_approval.presenter', ApprovalPresenter::class)
            ->args([service('router'), $config['public_origin']]);
        $services->alias(ApprovalPresenter::class, 'verified_human_approval.presenter');
        $services->alias(ResultPresenter::class, 'verified_human_approval.presenter');

        // The built-in approval budget. Replace it with "rate_limiter" for storage shared across hosts.
        $services->set('verified_human_approval.limiter.storage', CacheStorage::class)->args([service('cache.app')]);
        $services->set('verified_human_approval.limiter.lock_store', FlockStore::class)->args(['%kernel.cache_dir%/verified-human-approval-locks']);
        $services->set('verified_human_approval.limiter.lock_factory', LockFactory::class)->args([service('verified_human_approval.limiter.lock_store')]);
        $services->set('verified_human_approval.limiter', RateLimiterFactory::class)->args([
            ['id' => 'verified-human-approval', 'policy' => 'fixed_window', 'limit' => $config['attempts_per_minute'], 'interval' => '1 minute'],
            service('verified_human_approval.limiter.storage'),
            service('verified_human_approval.limiter.lock_factory'),
        ]);

        $services->set('verified_human_approval.handoff', ApprovalHandoff::class)->args([
            service('cache.app'), service('router'), $config['public_origin'], service('verified_human_approval.limiter.lock_factory'),
        ]);

        $services->set('verified_human_approval.url_prompt', UrlPrompt::class)
            ->args([$config['url_elicitation']['enabled'], $config['url_elicitation']['clients']]);
        $services->set('verified_human_approval.flow', ApprovalFlow::class)->args([
            service('verified_human_approval.approval_service'), service('verified_human_approval.presenter'), service('verified_human_approval.url_prompt'),
        ]);
        $services->alias(ApprovalFlow::class, 'verified_human_approval.flow');

        // The review pages. Public, because routes name it by service id.
        $services->set('verified_human_approval.approval_controller', ApprovalController::class)->public()->args([
            service('verified_human_approval.approval_service'),
            service('verified_human_approval.actor_provider'),
            service('verified_human_approval.presenter'),
            service('security.csrf.token_manager'),
            service('twig'),
            service($config['rate_limiter']),
            service('verified_human_approval.handoff'),
            service('router'),
            null === $config['requester_label'] ? service(RequesterLabel::class)->nullOnInvalid() : service($config['requester_label']),
        ]);

        // MCP elements, tagged here because the MCP bundle's attribute autoconfiguration does not reach a
        // bundle's explicit services. List the classes under your server's "registry" to expose them.
        $services->set('verified_human_approval.status_tool', ApprovalStatusTool::class)
            ->args([service('verified_human_approval.approval_service'), service('verified_human_approval.actor_provider'), service('verified_human_approval.presenter')])
            ->tag('mcp.tool', ['method' => 'status']);
        $services->set('verified_human_approval.handoff_tool', ApprovalHandoffTool::class)
            ->args([service('verified_human_approval.approval_service'), service('verified_human_approval.actor_provider'), service('verified_human_approval.handoff'), service($config['rate_limiter'])])
            ->tag('mcp.tool', ['method' => 'handoff']);
        $services->set('verified_human_approval.card_app', ApprovalCardApp::class)->tag('mcp.app');

        $services->set('verified_human_approval.security_headers', SecurityHeadersSubscriber::class)->tag('kernel.event_subscriber');
        // For access_control: the review pages open without a sign-in; their controller checks who is reviewing.
        $services->set('verified_human_approval.review_request_matcher', ReviewRequestMatcher::class);
    }
}
