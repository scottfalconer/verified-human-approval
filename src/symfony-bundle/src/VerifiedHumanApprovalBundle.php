<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use VerifiedHumanApproval\Bundle\Command\InstallStorageCommand;
use VerifiedHumanApproval\Bundle\Controller\ApprovalController;
use VerifiedHumanApproval\Bundle\Handoff\ApprovalHandoff;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalCardApp;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool;
use VerifiedHumanApproval\Bundle\Security\ReviewRequestMatcher;
use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalHistory;
use VerifiedHumanApproval\Core\ApprovalObserver;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Audit\AuditStore;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Doctrine\DbalApprovalStore;
use VerifiedHumanApproval\Doctrine\DbalAuditStore;
use VerifiedHumanApproval\Doctrine\DbalCredentialRepository;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Doctrine\Orm\ApprovalSchemaListener;
use VerifiedHumanApproval\Doctrine\Orm\ProtectedEntityListener;
use VerifiedHumanApproval\Mcp\ApprovalFlow;
use VerifiedHumanApproval\Mcp\ResultPresenter;
use VerifiedHumanApproval\Mcp\UrlPrompt;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\CredentialRepository;
use VerifiedHumanApproval\WebAuthn\RevocableCredentialRepository;

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
            ->scalarNode('store')->defaultNull()->info('Service id of the VerifiedHumanApproval\\Core\\ApprovalStore, such as VerifiedHumanApproval\\Core\\Storage\\PdoApprovalStore. Leave it out when persistence.driver is "doctrine", which wires the store itself.')->end()
            ->arrayNode('persistence')
                ->info('Storage the bundle wires for you. With driver "doctrine" (composer require verified-human-approval/doctrine), the approval store, the signed audit log and the passkey repository use one DBAL connection of DoctrineBundle, in one transaction with your entities and queries.')
                ->children()
                    ->enumNode('driver')->values(['doctrine'])->isRequired()->end()
                    ->scalarNode('connection')->defaultValue('default')->cannotBeEmpty()
                        ->info('The DoctrineBundle connection your protected writes, permissions and entity manager use.')->end()
                    ->scalarNode('audit_public_key')->defaultNull()
                        ->info('The separately trusted public key (base64url) the audit log checks its entries against. Defaults to the receipt signer\'s own key.')->end()
                    ->arrayNode('protected_entities')
                        ->info('Entity classes that change only through an approved action, each with its protected fields, or ~ for the whole entity. The #[RequiresApproval] attribute does the same on the entity.')
                        ->useAttributeAsKey('class')
                        ->variablePrototype()
                            ->validate()
                                ->ifTrue(static fn (mixed $fields): bool => null !== $fields && (!\is_array($fields) || !array_is_list($fields) || [] !== array_filter($fields, static fn (mixed $field): bool => !\is_string($field) || '' === $field)))
                                ->thenInvalid('List the protected fields of an entity class, or give ~ to protect the whole entity.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->scalarNode('verifier')->isRequired()->cannotBeEmpty()->info('Service id of the VerifiedHumanApproval\\Core\\ApprovalVerifier, such as VerifiedHumanApproval\\WebAuthn\\WebAuthnVerifier.')->end()
            ->scalarNode('requester_label')->defaultNull()
                ->info('Service id of a VerifiedHumanApproval\\Bundle\\RequesterLabel that names who asked on the review page. Unset, a service aliased to that interface is used if there is one.')->end()
            ->scalarNode('clock')->defaultValue('clock')->info('Service id of a PSR-20 clock; the framework\'s "clock" by default.')->end()
            ->integerNode('attempts_per_minute')->min(2)->max(1000)->defaultValue(30)
                ->info('Browser approval attempts per person per minute, across all requests. It sets the default of the framework rate limiter "verified_human_approval", which your own framework.rate_limiter config can override.')->end()
            ->scalarNode('rate_limiter')->defaultValue('limiter.verified_human_approval')->info('Service id of a Symfony\\Component\\RateLimiter\\RateLimiterFactoryInterface, to replace the framework limiter with your own.')->end()
            ->scalarNode('lock_factory')->defaultValue('verified_human_approval.lock_factory')
                ->info('Service id of a Symfony\\Component\\Lock\\LockFactory that makes each phone code single-use. The default locks files on this host; use "lock.factory" with a shared store when several hosts serve the application.')->end()
            ->scalarNode('receipt_signer')
                ->info('Service id of a VerifiedHumanApproval\\Core\\Evidence\\EvidenceSigner. When set, each execution\'s receipt is signed inside the completing transaction, so later edits to the record show; the person can download their receipt, and the signer\'s public key is published to check it.')
                ->defaultNull()
            ->end()
            ->scalarNode('relying_party_id')->defaultNull()
                ->info('The passkey relying party id published with the receipt key, so a receipt\'s passkey signature can be checked offline. Defaults to the public origin\'s host.')->end()
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
        ->end()
        ->validate()
            ->ifTrue(static fn (array $config): bool => null === $config['store'] && !isset($config['persistence']))
            ->thenInvalid('Name the approval store with "store", or let the bundle wire it with "persistence: { driver: doctrine }".')
        ->end()
        ->validate()
            ->ifTrue(static fn (array $config): bool => null !== $config['store'] && isset($config['persistence']))
            ->thenInvalid('Use "store" or "persistence", not both: persistence wires the approval store itself.')
        ->end()
        ->validate()
            ->ifTrue(static fn (array $config): bool => isset($config['persistence']) && null === $config['receipt_signer'])
            ->thenInvalid('"persistence" needs a "receipt_signer": it signs the audit log that vouches for every passkey.')
        ->end();
    }

    /**
     * The approval budget is an ordinary framework rate limiter named "verified_human_approval": it uses the
     * framework's rate-limiter cache and lock, and your own framework.rate_limiter config overrides any of it,
     * per environment too.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $attempts = 30;
        foreach ($builder->getExtensionConfig($this->extensionAlias) as $config) {
            $attempts = $config['attempts_per_minute'] ?? $attempts;
        }
        $builder->prependExtensionConfig('framework', ['rate_limiter' => [$this->extensionAlias => [
            'policy' => 'fixed_window', 'limit' => $attempts, 'interval' => '1 minute',
        ]]]);
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Your own action adapters join the registry when they are autoconfigured, or when you tag them.
        $builder->registerForAutoconfiguration(ActionAdapter::class)->addTag('verified_human_approval.action');
        // So do observers, told about each executed action after it commits.
        $builder->registerForAutoconfiguration(ApprovalObserver::class)->addTag('verified_human_approval.observer');

        $services = $container->services();

        $services->alias('verified_human_approval.clock', $config['clock']);
        $services->alias('verified_human_approval.actor_provider', $config['actor_provider']);
        $services->alias(ActorProvider::class, 'verified_human_approval.actor_provider');

        $services->set('verified_human_approval.action_registry', ActionRegistry::class)
            ->args([tagged_iterator('verified_human_approval.action')]);
        $services->set('verified_human_approval.execution_guard', ExecutionGuard::class);
        $services->alias(ExecutionGuard::class, 'verified_human_approval.execution_guard');
        $store = $config['store'];
        if (isset($config['persistence'])) {
            $store = $this->loadDoctrine($config['persistence'], $config['receipt_signer'], $container);
        }
        $services->set('verified_human_approval.approval_service', ApprovalService::class)->args([
            service('verified_human_approval.action_registry'),
            service($config['policy_provider']),
            service($config['authorizer']),
            service($store),
            service($config['verifier']),
            service('verified_human_approval.clock'),
            service('verified_human_approval.execution_guard'),
            null === $config['receipt_signer'] ? null : service($config['receipt_signer']),
            tagged_iterator('verified_human_approval.observer'),
            service('logger')->nullOnInvalid(),
        ]);
        $services->alias(ApprovalService::class, 'verified_human_approval.approval_service');
        // After commit, each executed action is also a Symfony event: ApprovalCompleted.
        $services->set('verified_human_approval.event_observer', EventDispatchingObserver::class)
            ->args([service('event_dispatcher')])
            ->tag('verified_human_approval.observer');

        $services->set('verified_human_approval.presenter', ApprovalPresenter::class)
            ->args([service('router'), $config['public_origin']]);
        $services->alias(ApprovalPresenter::class, 'verified_human_approval.presenter');
        $services->alias(ResultPresenter::class, 'verified_human_approval.presenter');

        // A file lock on this host, so a phone code is redeemed once even when two devices open it together.
        $services->set('verified_human_approval.lock_store', FlockStore::class)->args(['%kernel.cache_dir%/verified-human-approval-locks']);
        $services->set('verified_human_approval.lock_factory', LockFactory::class)->args([service('verified_human_approval.lock_store')]);

        $services->set('verified_human_approval.handoff', ApprovalHandoff::class)->args([
            service('cache.app'), service('router'), $config['public_origin'], service($config['lock_factory']),
        ]);

        $services->set('verified_human_approval.url_prompt', UrlPrompt::class)
            ->args([$config['url_elicitation']['enabled'], $config['url_elicitation']['clients']]);
        $services->set('verified_human_approval.flow', ApprovalFlow::class)->args([
            service('verified_human_approval.approval_service'), service('verified_human_approval.presenter'), service('verified_human_approval.url_prompt'),
        ]);
        $services->alias(ApprovalFlow::class, 'verified_human_approval.flow');

        // With a receipt signer, the person can download their receipt, and the key that checks it is published.
        // The download serves the original receipts only, so the exporter gets no access to the private key.
        if (null !== $config['receipt_signer']) {
            $services->set('verified_human_approval.evidence_exporter', EvidenceExporter::class);
            $services->alias(EvidenceExporter::class, 'verified_human_approval.evidence_exporter');
            $services->set('verified_human_approval.receipt_key', ReceiptKey::class)
                ->args([service($config['receipt_signer']), $config['public_origin'], $config['relying_party_id']]);
            $services->alias(ReceiptKey::class, 'verified_human_approval.receipt_key');
        }

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
            null === $config['receipt_signer'] ? null : service('verified_human_approval.evidence_exporter'),
            null === $config['receipt_signer'] ? null : service('verified_human_approval.receipt_key'),
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

    /**
     * The Doctrine storage on one DBAL connection: the approval transaction, the three stores, the signed audit log,
     * the anchored passkey repository, the install command and, with the ORM, the schema and protection listeners.
     *
     * @param array{driver: string, connection: string, audit_public_key: ?string, protected_entities: array<class-string, list<string>|null>} $persistence
     *
     * @return string the approval store's service id
     */
    private function loadDoctrine(array $persistence, string $receiptSigner, ContainerConfigurator $container): string
    {
        if (!class_exists(DbalTransaction::class)) {
            throw new \LogicException('persistence.driver "doctrine" needs the Doctrine storage package: composer require verified-human-approval/doctrine.');
        }
        $connection = \sprintf('doctrine.dbal.%s_connection', $persistence['connection']);
        $services = $container->services();

        $services->set('verified_human_approval.doctrine.transaction', DbalTransaction::class)->args([service($connection)]);
        $services->alias(DbalTransaction::class, 'verified_human_approval.doctrine.transaction');
        $services->set('verified_human_approval.doctrine.approval_store', DbalApprovalStore::class)->args([service('verified_human_approval.doctrine.transaction')]);
        $services->alias(DbalApprovalStore::class, 'verified_human_approval.doctrine.approval_store');
        $services->alias(ApprovalHistory::class, 'verified_human_approval.doctrine.approval_store');
        $services->set('verified_human_approval.doctrine.audit_store', DbalAuditStore::class)->args([service('verified_human_approval.doctrine.transaction')]);
        $services->alias(DbalAuditStore::class, 'verified_human_approval.doctrine.audit_store');
        $services->alias(AuditStore::class, 'verified_human_approval.doctrine.audit_store');
        $services->set('verified_human_approval.doctrine.credentials', DbalCredentialRepository::class)->args([service('verified_human_approval.doctrine.transaction')]);
        $services->alias(DbalCredentialRepository::class, 'verified_human_approval.doctrine.credentials');
        $services->alias(RevocableCredentialRepository::class, 'verified_human_approval.doctrine.credentials');

        $services->set('verified_human_approval.audit_log', AuditLog::class)
            ->factory([AuditLogFactory::class, 'create'])
            ->args([service('verified_human_approval.doctrine.audit_store'), service($receiptSigner), service('verified_human_approval.clock'), $persistence['audit_public_key']]);
        $services->alias(AuditLog::class, 'verified_human_approval.audit_log');
        // Verification reads passkeys through the anchored wrapper, and the wrapper reads the raw rows.
        $services->set('verified_human_approval.anchored_credentials', AnchoredCredentialRepository::class)
            ->args([service('verified_human_approval.doctrine.credentials'), service('verified_human_approval.audit_log')]);
        $services->alias(AnchoredCredentialRepository::class, 'verified_human_approval.anchored_credentials');
        $services->alias(CredentialRepository::class, 'verified_human_approval.anchored_credentials');

        $services->set('verified_human_approval.doctrine.install_command', InstallStorageCommand::class)
            ->args([service($connection)])
            ->tag('console.command', ['command' => 'verified-human-approval:install']);

        if (class_exists(GenerateSchemaEventArgs::class)) {
            // doctrine:migrations:diff and doctrine:schema:update create the tables instead of proposing to drop them.
            $services->set('verified_human_approval.doctrine.schema_listener', ApprovalSchemaListener::class)
                ->args([service($connection)])
                ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema', 'connection' => $persistence['connection']]);
            // Last, so it sees what every other listener changed in the flush.
            $services->set('verified_human_approval.doctrine.protected_entities', ProtectedEntityListener::class)
                ->args([service('verified_human_approval.execution_guard'), $persistence['protected_entities']])
                ->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => $persistence['connection'], 'priority' => -1024])
                ->tag('doctrine.event_listener', ['event' => 'preUpdate', 'connection' => $persistence['connection'], 'priority' => -1024]);
            $services->alias(ProtectedEntityListener::class, 'verified_human_approval.doctrine.protected_entities');
        }

        return 'verified_human_approval.doctrine.approval_store';
    }
}
