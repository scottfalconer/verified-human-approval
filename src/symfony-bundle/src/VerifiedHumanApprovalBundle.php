<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use VerifiedHumanApproval\Bundle\Command\AuditHeadCommand;
use VerifiedHumanApproval\Bundle\Command\RecordKeyRotationCommand;
use VerifiedHumanApproval\Bundle\Controller\ApprovalController;
use VerifiedHumanApproval\Bundle\Controller\VerificationController;
use VerifiedHumanApproval\Bundle\Handoff\ApprovalHandoff;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalCardApp;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool;
use VerifiedHumanApproval\Bundle\Security\ReviewRequestMatcher;
use VerifiedHumanApproval\Bundle\Twig\ContentSecurityPolicyExtension;
use VerifiedHumanApproval\Bundle\Twig\VerificationExtension;
use VerifiedHumanApproval\Bundle\Verification\PublicVerification;
use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalObserver;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
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
        $root = $definition->rootNode();
        $root->children()
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
            ->integerNode('attempts_per_minute')->min(2)->max(1000)->defaultValue(30)
                ->info('Browser approval attempts per person per minute, across all requests. It sets the default of the framework rate limiter "verified_human_approval", which your own framework.rate_limiter config can override.')->end()
            ->scalarNode('rate_limiter')->defaultValue('limiter.verified_human_approval')->info('Service id of a Symfony\\Component\\RateLimiter\\RateLimiterFactoryInterface, to replace the framework limiter with your own.')->end()
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
            ->stringNode('agent_name')->defaultValue('Claude')->cannotBeEmpty()
                ->info('What the review pages call the agent\'s app when they send the person back to it, as in "return to Claude". Only requests an agent made say it.')->end()
        ->end()
        ->validate()
            ->ifTrue(static fn (array $config): bool => null === $config['receipt_signer'] && (null !== $config['receipt_keys']['valid_from'] || [] !== $config['receipt_keys']['retired']))
            ->thenInvalid('receipt_keys describes the receipt_signer\'s keys: configure receipt_signer too.')
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
            if (!\is_array($key) || [] !== array_diff(array_keys($key), TrustedKeysFactory::RETIRED_FIELDS)) {
                return false;
            }
        }

        return true;
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
        $services->set('verified_human_approval.approval_service', ApprovalService::class)->args([
            service('verified_human_approval.action_registry'),
            service($config['policy_provider']),
            service($config['authorizer']),
            service($config['store']),
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

        // With a receipt signer, the person can download their receipt, and the keys that check it are published.
        // The download serves the original receipts only, so the exporter gets no access to the private key.
        if (null !== $config['receipt_signer']) {
            // The signer's key and every key it replaced, each with its window: what receipts and the audit log are checked against.
            $services->set('verified_human_approval.trusted_keys', TrustedKeys::class)
                ->factory([TrustedKeysFactory::class, 'fromConfiguration'])
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
        $services->set('verified_human_approval.audit_head_command', AuditHeadCommand::class)->args([$audit])
            ->tag('console.command', ['command' => AuditHeadCommand::NAME, 'description' => 'Print the signed audit log\'s newest entry (sequence and hash) to keep outside the application, or check a kept one.']);
        $services->set('verified_human_approval.record_key_rotation_command', RecordKeyRotationCommand::class)->args([$audit])
            ->tag('console.command', ['command' => RecordKeyRotationCommand::NAME, 'description' => 'Record in the signed audit log that the signing key moved from an old key to the current receipt signer.']);
        // "Approved with a passkey" on your own pages: a Twig badge, and the public page it links to when enabled.
        $services->set('verified_human_approval.public_verification', PublicVerification::class)->args([
            service($config['store']),
            null === $config['receipt_signer'] ? null : service('verified_human_approval.trusted_keys'),
        ]);
        $services->alias(PublicVerification::class, 'verified_human_approval.public_verification');
        $services->set('verified_human_approval.twig.verification', VerificationExtension::class)
            ->args([service('verified_human_approval.public_verification'), service('router'), $config['public_verification']])
            ->tag('twig.extension');
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
            service('verified_human_approval.handoff'),
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
        $services->set('verified_human_approval.handoff_tool', ApprovalHandoffTool::class)
            ->args([service('verified_human_approval.approval_service'), service('verified_human_approval.actor_provider'), service('verified_human_approval.handoff'), service($config['rate_limiter'])])
            ->tag('mcp.tool', ['method' => 'handoff']);
        $services->set('verified_human_approval.card_app', ApprovalCardApp::class)->tag('mcp.app');

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
}
