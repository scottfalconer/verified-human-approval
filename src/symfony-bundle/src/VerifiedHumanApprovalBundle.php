<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use VerifiedHumanApproval\Bundle\Command\AuditHeadCommand;
use VerifiedHumanApproval\Bundle\Command\InstallStorageCommand;
use VerifiedHumanApproval\Bundle\Command\RecordKeyRotationCommand;
use VerifiedHumanApproval\Bundle\Controller\ApprovalController;
use VerifiedHumanApproval\Bundle\Controller\VerificationController;
use VerifiedHumanApproval\Bundle\DependencyInjection\CheckPhoneHandoffPass;
use VerifiedHumanApproval\Bundle\DependencyInjection\CheckScopeChallengePass;
use VerifiedHumanApproval\Bundle\Handoff\ApprovalHandoff;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalCardApp;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool;
use VerifiedHumanApproval\Bundle\Mcp\RateLimiterProposalBudget;
use VerifiedHumanApproval\Bundle\Security\ReviewRequestMatcher;
use VerifiedHumanApproval\Bundle\Twig\ContentSecurityPolicyExtension;
use VerifiedHumanApproval\Bundle\Twig\VerificationExtension;
use VerifiedHumanApproval\Bundle\Twig\VerificationRuntime;
use VerifiedHumanApproval\Bundle\Verification\PublicVerification;
use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalHistory;
use VerifiedHumanApproval\Core\ApprovalObserver;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Audit\AuditStore;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Doctrine\DbalApprovalStore;
use VerifiedHumanApproval\Doctrine\DbalAuditStore;
use VerifiedHumanApproval\Doctrine\DbalCredentialRepository;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Doctrine\Orm\ApprovalSchemaListener;
use VerifiedHumanApproval\Doctrine\Orm\ProtectedEntityListener;
use VerifiedHumanApproval\Mcp\ApprovalFlow;
use VerifiedHumanApproval\Mcp\ProposalBudget;
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
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new CheckScopeChallengePass());
        // Late: after the MCP bundle's passes have told each server what to serve, and named arguments are resolved.
        $container->addCompilerPass(new CheckPhoneHandoffPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        $root->children()
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
            ->integerNode('agent_requests_per_minute')->min(1)->max(1000)->defaultValue(30)
                ->info('Calls per person per minute, across their agent connections, to protected tools that may open a proposal, as the MCP specification asks a server to rate limit tool calls. Reading an outcome is free. It sets the default of the framework rate limiter "verified_human_approval_agent_requests", which your own framework.rate_limiter config can override.')->end()
            ->scalarNode('rate_limiter')->defaultValue('limiter.verified_human_approval')->info('Service id of a Symfony\\Component\\RateLimiter\\RateLimiterFactoryInterface, to replace the framework limiter with your own.')->end()
            ->booleanNode('phone_handoff')->defaultTrue()
                ->info('"Approve on your phone": the approval card offers a one-time code that opens one pending request\'s review on the person\'s phone. While it is on, each MCP server that serves the card lists VerifiedHumanApproval\\Bundle\\Mcp\\ApprovalHandoffTool under its registry tools, or the container does not build. Off, the card offers no code, the tool is not defined, and a code link answers 410.')->end()
            ->scalarNode('lock_factory')->defaultValue('verified_human_approval.lock_factory')
                ->info('Service id of a Symfony\\Component\\Lock\\LockFactory that makes each phone code single-use. The default locks files on this host; use "lock.factory" with a shared store when several hosts serve the application.')->end()
            ->scalarNode('receipt_signer')
                ->info('Service id of a VerifiedHumanApproval\\Core\\Evidence\\EvidenceSigner. When set, each execution\'s receipt is signed inside the completing transaction, so later edits to the record show; the person can download their receipt, and the public keys that check it are published.')
                ->defaultNull()
            ->end()
            ->arrayNode('receipt_keys')
                ->info('The receipt_signer\'s key and the keys it replaced, which check receipts and the signed audit log, each only within the window it signed in. The bundle publishes them at /approval/receipt-keys.json, and serves them as the service VerifiedHumanApproval\\Core\\Evidence\\TrustedKeys for your AuditLog. See the key rotation procedure in docs/evidence.md.')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('valid_from')->defaultNull()
                        ->info('When the receipt_signer began signing: an RFC 3339 time with its offset, such as 2026-11-09T12:00:00Z. A record it signed that claims an earlier time does not verify. Unset or empty: no earlier limit, as for a key whose start was never recorded.')->end()
                    ->variableNode('retired')->defaultValue([])
                        ->info('Keys that signed before the receipt_signer, by public key only: a list of maps with key_id, public_key, valid_until and, optionally, valid_from. What each signed keeps verifying while its claimed time falls inside its window. An environment variable read with the json: processor works too.')
                        ->validate()
                            ->ifTrue(static fn (mixed $keys): bool => !\is_string($keys) && !self::retiredShape($keys))
                            ->thenInvalid('receipt_keys.retired must be a list of keys, each naming only key_id, public_key, valid_from and valid_until: %s')
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->scalarNode('audit_log')->defaultNull()
                ->info('Service id of the VerifiedHumanApproval\\Core\\Audit\\AuditLog that the console commands verified-human-approval:audit-head and verified-human-approval:record-key-rotation read and append to. Unset, a service named after that class is used if there is one.')->end()
            ->booleanNode('public_verification')->defaultFalse()
                ->info('Serve /approval/{id}/verified, the public page a "verified" badge links to: what was approved, when, and the signed receipt, with no account, tenant, connection or passkey ids. Anyone with the link can open it. Needs receipt_signer.')->end()
            ->scalarNode('relying_party_id')->defaultNull()
                ->info('The passkey relying party id published with the receipt keys, so a receipt\'s passkey signature can be checked offline. Defaults to the public origin\'s host.')->end()
            ->arrayNode('url_elicitation')
                ->info('Native URL-mode elicitation for pending approvals, on MCP 2026-07-28 or later. Off by default: the card and link always work, and some clients declare URL mode and then decline or drop it. Listing clients turns it on; enabled: false keeps the list and turns it off.')
                ->canBeEnabled()
                ->children()
                    ->arrayNode('clients')
                        ->info('clientInfo names to prompt, such as "claude-code", or "*" for any client declaring URL mode.')
                        ->scalarPrototype()->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->validate()
                    ->ifTrue(static fn (array $prompt): bool => $prompt['enabled'] && [] === $prompt['clients'])
                    ->thenInvalid('url_elicitation is enabled for no client: list the clientInfo names to prompt under "clients", or "*" for every client that declares URL mode.')
                ->end()
            ->end()
            ->stringNode('agent_name')->defaultValue('your AI assistant')->cannotBeEmpty()
                ->info('What the review pages call the agent\'s app when they send the person back to it, as in "You can close this tab and return to your AI assistant". Set the name your people know it by, such as "Claude". Only requests an agent made say it.')->end()
        ->end()
        ->validate()
            ->ifTrue(static fn (array $config): bool => null === $config['receipt_signer'] && (null !== $config['receipt_keys']['valid_from'] || [] !== $config['receipt_keys']['retired']))
            ->thenInvalid('receipt_keys describes the receipt_signer\'s keys: configure receipt_signer too.')
        ->end()
        ->validate()
            // Without a signer there is no receipt to show, so every public page would answer 404.
            ->ifTrue(static fn (array $config): bool => $config['public_verification'] && null === $config['receipt_signer'])
            ->thenInvalid('public_verification shows the receipt signed as the change committed: configure receipt_signer too.')
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

        // Checked here, as the container is built, so a policy that would let injected script run never ships.
        $sources = $root->children()->arrayNode('content_security_policy')
            ->info('Sources the review pages\' Content-Security-Policy adds for your own layout: \'self\', https:// origins, and data: for images and fonts. Anything else, such as \'unsafe-inline\', \'unsafe-eval\', * or http:, is refused. An inline script or style takes the nonce instead: verified_human_approval_csp_nonce() in Twig.')
            ->addDefaultsIfNotSet()
            ->children();
        foreach (ContentSecurityPolicy::DIRECTIVES as $key => $directive) {
            $sources->arrayNode($key)
                ->info(\sprintf('Added to %s, such as https://cdn.example.com.', $directive))
                ->beforeNormalization()->ifString()->then(static fn (string $source): array => [$source])->end()
                ->scalarPrototype()
                    ->validate()
                        ->ifTrue(static fn (mixed $source): bool => null !== ContentSecurityPolicy::refusal($directive, $source))
                        ->then(static fn (mixed $source): never => throw new \InvalidArgumentException((string) ContentSecurityPolicy::refusal($directive, $source)))
                    ->end()
                ->end()
            ->end();
        }
    }

    /** Whether $keys has the shape of receipt_keys.retired; the values are checked as the container builds the keys. */
    private static function retiredShape(mixed $keys): bool
    {
        if (!\is_array($keys) || !array_is_list($keys)) {
            return false;
        }
        foreach ($keys as $key) {
            if (!\is_array($key) || [] !== array_diff(array_keys($key), TrustedKeys::RETIRED_FIELDS)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The approval budget and the agents' request budget are ordinary framework rate limiters, named
     * "verified_human_approval" and "verified_human_approval_agent_requests": they use the framework's rate-limiter
     * cache and lock, and your own framework.rate_limiter config overrides any of it, per environment too. The phone
     * codes are kept in an ordinary cache pool, "verified_human_approval.handoff_cache", on the application's cache
     * unless your framework.cache.pools config gives it another adapter.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $attempts = $requests = 30;
        foreach ($builder->getExtensionConfig($this->extensionAlias) as $config) {
            $attempts = $config['attempts_per_minute'] ?? $attempts;
            $requests = $config['agent_requests_per_minute'] ?? $requests;
        }
        $builder->prependExtensionConfig('framework', [
            'rate_limiter' => [
                $this->extensionAlias => ['policy' => 'fixed_window', 'limit' => $attempts, 'interval' => '1 minute'],
                $this->extensionAlias.'_agent_requests' => ['policy' => 'fixed_window', 'limit' => $requests, 'interval' => '1 minute'],
            ],
            'cache' => ['pools' => [$this->extensionAlias.'.handoff_cache' => ['adapter' => 'cache.app']]],
        ]);
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
            // A stored outcome is read back only while its receipt verifies against these: the signer's key and,
            // from receipt_keys.retired, every key it replaced.
            null === $config['receipt_signer'] ? null : service('verified_human_approval.trusted_keys'),
        ]);
        $services->alias(ApprovalService::class, 'verified_human_approval.approval_service');
        // After commit, each executed action is also a Symfony event: ApprovalCompleted.
        $services->set('verified_human_approval.event_observer', EventDispatchingObserver::class)
            ->args([service('event_dispatcher')])
            ->tag('verified_human_approval.observer');

        $services->set('verified_human_approval.presenter', ApprovalPresenter::class)
            ->args([service('router'), $config['public_origin'], $config['phone_handoff']]);
        $services->alias(ApprovalPresenter::class, 'verified_human_approval.presenter');
        $services->alias(ResultPresenter::class, 'verified_human_approval.presenter');

        if ($config['phone_handoff']) {
            // A file lock on this host, so a phone code is redeemed once even when two devices open it together.
            $services->set('verified_human_approval.lock_store', FlockStore::class)->args(['%kernel.cache_dir%/verified-human-approval-locks']);
            $services->set('verified_human_approval.lock_factory', LockFactory::class)->args([service('verified_human_approval.lock_store')]);
            $services->set('verified_human_approval.handoff', ApprovalHandoff::class)->args([
                service('verified_human_approval.handoff_cache'), service('router'), $config['public_origin'], service('verified_human_approval.clock'), service($config['lock_factory']),
            ]);
        }

        $services->set('verified_human_approval.url_prompt', UrlPrompt::class)
            ->args([$config['url_elicitation']['enabled'], $config['url_elicitation']['clients']]);
        $services->set('verified_human_approval.proposal_budget', RateLimiterProposalBudget::class)
            ->args([service('limiter.verified_human_approval_agent_requests')]);
        $services->alias(ProposalBudget::class, 'verified_human_approval.proposal_budget');
        $services->set('verified_human_approval.flow', ApprovalFlow::class)->args([
            service('verified_human_approval.approval_service'), service('verified_human_approval.presenter'), service('verified_human_approval.url_prompt'),
            // By its interface, so a budget the application defines or decorates under either name is the one spent from.
            service(ProposalBudget::class), service('verified_human_approval.clock'),
        ]);
        $services->alias(ApprovalFlow::class, 'verified_human_approval.flow');

        // With a receipt signer, the person can download their receipt, and the keys that check it are published.
        // The download serves the original receipts only, so the exporter gets no access to the private key.
        if (null !== $config['receipt_signer']) {
            // The signer's key and every key it replaced, each with its window: what receipts and the audit log are checked against.
            $services->set('verified_human_approval.trusted_keys', TrustedKeys::class)
                ->factory([TrustedKeys::class, 'forSigner'])
                ->args([service($config['receipt_signer']), $config['receipt_keys']['valid_from'], $config['receipt_keys']['retired']]);
            $services->alias(TrustedKeys::class, 'verified_human_approval.trusted_keys');
            $services->set('verified_human_approval.evidence_exporter', EvidenceExporter::class);
            $services->alias(EvidenceExporter::class, 'verified_human_approval.evidence_exporter');
            $services->set('verified_human_approval.receipt_key', ReceiptKey::class)
                ->args([service($config['receipt_signer']), $config['public_origin'], $config['relying_party_id'], service('verified_human_approval.trusted_keys')]);
            $services->alias(ReceiptKey::class, 'verified_human_approval.receipt_key');
        }

        // The signed audit log's console commands: print its head to keep as a checkpoint, and record a key rotation.
        $audit = null === $config['audit_log'] ? service(AuditLog::class)->nullOnInvalid() : service($config['audit_log']);
        $services->set('verified_human_approval.audit_head_command', AuditHeadCommand::class)->args([$audit])->tag('console.command');
        $services->set('verified_human_approval.record_key_rotation_command', RecordKeyRotationCommand::class)->args([$audit])->tag('console.command');
        // "Approved with a passkey" on your own pages: a Twig badge, and the public page it links to when enabled.
        $services->set('verified_human_approval.public_verification', PublicVerification::class)->args([
            service($store),
            null === $config['receipt_signer'] ? null : service('verified_human_approval.trusted_keys'),
            service('logger')->nullOnInvalid(),
        ]);
        $services->alias(PublicVerification::class, 'verified_human_approval.public_verification');
        $services->set('verified_human_approval.twig.verification', VerificationExtension::class)->tag('twig.extension');
        // A runtime, so only a template that asks for the badge builds the approval store it reads.
        $services->set('verified_human_approval.twig.verification_runtime', VerificationRuntime::class)
            ->args([service('verified_human_approval.public_verification'), service('router'), $config['public_verification']])
            ->tag('twig.runtime');
        $services->set('verified_human_approval.verification_controller', VerificationController::class)->public()->args([
            service('verified_human_approval.public_verification'), service('twig'), $config['public_verification'],
        ]);

        // The review pages. Public, because routes name it by service id.
        $services->set('verified_human_approval.approval_controller', ApprovalController::class)->public()->args([
            service('verified_human_approval.approval_service'),
            service('verified_human_approval.actor_provider'),
            service('verified_human_approval.presenter'),
            service('security.csrf.token_manager'),
            service('twig'),
            service($config['rate_limiter']),
            // Without the phone code, no code link opens a review and no session is scoped to one.
            $config['phone_handoff'] ? service('verified_human_approval.handoff') : null,
            service('router'),
            null === $config['requester_label'] ? service(RequesterLabel::class)->nullOnInvalid() : service($config['requester_label']),
            null === $config['receipt_signer'] ? null : service('verified_human_approval.evidence_exporter'),
            null === $config['receipt_signer'] ? null : service('verified_human_approval.receipt_key'),
            $config['agent_name'],
        ]);

        // MCP elements, tagged here because the MCP bundle's attribute autoconfiguration does not reach a
        // bundle's explicit services. List the classes under your server's "registry" to expose them.
        $services->set('verified_human_approval.status_tool', ApprovalStatusTool::class)
            ->args([service('verified_human_approval.approval_service'), service('verified_human_approval.actor_provider'), service('verified_human_approval.presenter')])
            ->tag('mcp.tool', ['method' => 'status']);
        if ($config['phone_handoff']) {
            $services->set('verified_human_approval.handoff_tool', ApprovalHandoffTool::class)
                ->args([service('verified_human_approval.approval_service'), service('verified_human_approval.actor_provider'), service('verified_human_approval.handoff'), service($config['rate_limiter'])])
                ->tag('mcp.tool', ['method' => 'handoff']);
        }
        $services->set('verified_human_approval.card_app', ApprovalCardApp::class)
            ->args([service('verified_human_approval.presenter')])
            ->tag('mcp.app');

        // The review pages' headers, and their policy's nonce for your layout's templates.
        $services->set('verified_human_approval.content_security_policy', ContentSecurityPolicy::class)
            ->args([$config['content_security_policy']]);
        $services->alias(ContentSecurityPolicy::class, 'verified_human_approval.content_security_policy');
        $services->set('verified_human_approval.security_headers', SecurityHeadersSubscriber::class)
            ->args([service('verified_human_approval.content_security_policy')])
            ->tag('kernel.event_subscriber');
        $services->set('verified_human_approval.twig.content_security_policy', ContentSecurityPolicyExtension::class)
            ->args([service('verified_human_approval.content_security_policy'), service('request_stack')])
            ->tag('twig.extension');
        // For access_control: the review pages open without a sign-in; their controller checks who is reviewing.
        $services->set('verified_human_approval.review_request_matcher', ReviewRequestMatcher::class);
    }

    /**
     * The Doctrine storage on one DBAL connection: the approval transaction, the three stores, the signed audit log,
     * the anchored passkey repository, the install command and, with the ORM, the schema and protection listeners.
     *
     * @param array{driver: string, connection: string, protected_entities: array<array-key, list<string>|null>} $persistence
     *
     * @return string the approval store's service id
     */
    private function loadDoctrine(array $persistence, string $receiptSigner, ContainerConfigurator $container): string
    {
        if (!class_exists(DbalTransaction::class)) {
            throw new \LogicException('persistence.driver "doctrine" needs the Doctrine storage package: composer require verified-human-approval/doctrine.');
        }
        $protected = self::normalizeProtectedEntities($persistence['protected_entities']);
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

        // Signed with the receipt signer and checked against the trusted keys: its key and the ones it replaced.
        $services->set('verified_human_approval.audit_log', AuditLog::class)
            ->args([service('verified_human_approval.doctrine.audit_store'), service($receiptSigner), service('verified_human_approval.trusted_keys'), service('verified_human_approval.clock')]);
        $services->alias(AuditLog::class, 'verified_human_approval.audit_log');
        // Verification reads passkeys through the anchored wrapper, and the wrapper reads the raw rows.
        $services->set('verified_human_approval.anchored_credentials', AnchoredCredentialRepository::class)
            ->args([service('verified_human_approval.doctrine.credentials'), service('verified_human_approval.audit_log')]);
        $services->alias(AnchoredCredentialRepository::class, 'verified_human_approval.anchored_credentials');
        $services->alias(CredentialRepository::class, 'verified_human_approval.anchored_credentials');

        $services->set('verified_human_approval.doctrine.install_command', InstallStorageCommand::class)
            ->args([service($connection)])
            ->tag('console.command');

        if (class_exists(GenerateSchemaEventArgs::class)) {
            // doctrine:migrations:diff and doctrine:schema:update create the tables instead of proposing to drop them.
            $services->set('verified_human_approval.doctrine.schema_listener', ApprovalSchemaListener::class)
                ->args([service($connection)])
                ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema', 'connection' => $persistence['connection']]);
            // Last, so it sees what every other listener changed in the flush.
            $services->set('verified_human_approval.doctrine.protected_entities', ProtectedEntityListener::class)
                ->args([service('verified_human_approval.execution_guard'), $protected])
                ->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => $persistence['connection'], 'priority' => -1024])
                ->tag('doctrine.event_listener', ['event' => 'preUpdate', 'connection' => $persistence['connection'], 'priority' => -1024]);
            $services->alias(ProtectedEntityListener::class, 'verified_human_approval.doctrine.protected_entities');
        } elseif ([] !== $protected) {
            throw new InvalidConfigurationException('persistence.protected_entities requires Doctrine ORM.');
        }

        return 'verified_human_approval.doctrine.approval_store';
    }

    /**
     * @param array<array-key, list<string>|null> $entries
     *
     * @return array<class-string, list<string>|null>
     */
    private static function normalizeProtectedEntities(array $entries): array
    {
        $protected = [];
        foreach ($entries as $name => $fields) {
            $class = ltrim((string) $name, '\\');
            if (!class_exists($class) || $class !== (new \ReflectionClass($class))->getName()) {
                throw new InvalidConfigurationException(\sprintf('persistence.protected_entities names "%s". Use the exact fully qualified class name, including its capitalization.', $name));
            }
            if (\array_key_exists($class, $protected)) {
                throw new InvalidConfigurationException(\sprintf('persistence.protected_entities lists "%s" more than once.', $class));
            }
            $protected[$class] = $fields;
        }

        return $protected;
    }
}
