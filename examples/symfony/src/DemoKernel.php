<?php

declare(strict_types=1);

namespace ExactApproval\Demo;

use ExactApproval\Core\Audit\AuditLog;
use ExactApproval\Core\Evidence\EvidenceExporter;
use ExactApproval\Core\Evidence\SodiumEvidenceSigner;
use ExactApproval\Core\Storage\PdoApprovalStore;
use ExactApproval\Demo\Action\DemoAuthorizer;
use ExactApproval\Demo\Action\DemoPolicyProvider;
use ExactApproval\Demo\Controller\AccountController;
use ExactApproval\Demo\Controller\AuditController;
use ExactApproval\Demo\Controller\DemoController;
use ExactApproval\Demo\Controller\EvidenceController;
use ExactApproval\Demo\Controller\OAuthController;
use ExactApproval\Demo\Controller\PublicController;
use ExactApproval\Demo\Mcp\DemoTools;
use ExactApproval\Demo\Security\DemoActorProvider;
use ExactApproval\Demo\Security\DemoUser;
use ExactApproval\Demo\Security\DemoUserProvider;
use ExactApproval\Demo\Security\DemoRequesterLabel;
use ExactApproval\Demo\Security\PersonalTokenAuthenticator;
use ExactApproval\Symfony\ExactApprovalBundle;
use ExactApproval\Symfony\Mcp\ApprovalCardApp;
use ExactApproval\Symfony\Mcp\ApprovalStatusTool;
use ExactApproval\Symfony\RequesterLabel;
use ExactApproval\WebAuthn\AnchoredCredentialRepository;
use ExactApproval\WebAuthn\Configuration;
use ExactApproval\WebAuthn\CredentialRepository;
use ExactApproval\WebAuthn\PdoCredentialRepository;
use ExactApproval\WebAuthn\WebAuthnVerifier;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\RateLimiter\RateLimiterFactory;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class DemoKernel extends Kernel
{
    use MicroKernelTrait;

    private readonly array $settings;

    public function __construct(private readonly string $runtimeDirectory, string $environment = 'dev', bool $debug = false)
    {
        if (!is_file($runtimeDirectory.'/settings.json')) throw new \RuntimeException('Initialize the demo with: php examples/symfony/bin/setup.php');
        $this->settings = json_decode(file_get_contents($runtimeDirectory.'/settings.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($this->settings['schema'] ?? 1) < DemoSetup::SCHEMA) {
            throw new \RuntimeException('This demo was set up by an older version. Start a fresh one with: php examples/symfony/bin/setup.php --reset');
        }
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new McpBundle();
        yield new ExactApprovalBundle();
    }

    public function getProjectDir(): string { return \dirname(__DIR__); }
    public function getCacheDir(): string { return $this->runtimeDirectory.'/cache/'.$this->environment; }
    public function getLogDir(): string { return $this->runtimeDirectory.'/log'; }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => $this->settings['app_secret'],
            'test' => $this->environment === 'test',
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'trusted_hosts' => ['^'.preg_quote($this->settings['rp_id'], '/').'$'],
            // The public copy runs behind a local tunnel; only localhost may forward the original scheme and host.
            'trusted_proxies' => '127.0.0.1,::1',
            'trusted_headers' => ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host'],
            'session' => [
                'handler_id' => null, 'save_path' => $this->runtimeDirectory.'/sessions',
                'cookie_secure' => 'auto', 'cookie_httponly' => true, 'cookie_samesite' => 'lax',
                'storage_factory_id' => $this->environment === 'test' ? 'session.storage.factory.mock_file' : 'session.storage.factory.native',
            ],
            'csrf_protection' => true,
            'form' => true,
            'validation' => ['email_validation_mode' => 'html5'],
        ]);
        $container->extension('twig', ['paths' => [$this->getProjectDir().'/templates'], 'strict_variables' => true]);
        $container->extension('security', [
            'password_hashers' => [DemoUser::class => ['algorithm' => 'auto']],
            'providers' => ['demo' => ['id' => DemoUserProvider::class]],
            'firewalls' => [
                'assets' => ['pattern' => '^/approval/assets/', 'security' => false],
                'mcp' => [
                    'pattern' => '^/mcp(?:$|/)', 'stateless' => true, 'provider' => 'demo',
                    'custom_authenticators' => [PersonalTokenAuthenticator::class],
                    'entry_point' => PersonalTokenAuthenticator::class,
                ],
                'main' => [
                    'provider' => 'demo',
                    'form_login' => ['login_path' => 'exact_approval_login', 'check_path' => 'exact_approval_login', 'enable_csrf' => true, 'default_target_path' => '/'],
                    'login_throttling' => ['max_attempts' => 5, 'interval' => '1 minute', 'lock_factory' => 'exact_approval.limiter.lock_factory', 'storage_service' => 'exact_approval.limiter.storage'],
                    'logout' => ['path' => 'exact_approval_logout', 'target' => 'exact_approval_login', 'enable_csrf' => true],
                ],
            ],
            'access_control' => [
                ['path' => '^/login$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/approval/assets/', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/(alerts$|site/)', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/(\.well-known/|oauth/(register|token)$)', 'roles' => 'PUBLIC_ACCESS'],
                // A one-time phone link opens a review without signing in; the controller still requires
                // the signed-in person or a session scoped to that one request.
                ['path' => '^/approval/(handoff/[^/]+|[^/]+(/(status|options|complete|cancel))?)$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/mcp(?:$|/)', 'roles' => 'ROLE_USER'],
                ['path' => '^/', 'roles' => 'ROLE_USER'],
            ],
        ]);
        $container->extension('mcp', ['servers' => ['application' => [
            'name' => 'Verified Human Approval demo', 'version' => '0.1.0',
            'instructions' => 'Riverside Council public alerts. The draft evacuation alert is riverside-evacuation: read it with read_alert, then ask to issue it with publish_alert. If approval is required, show the review URL; only the person’s own passkey on the site can approve. Call approval_status afterwards and never say the alert is live while it is pending.',
            'transports' => ['http' => true, 'stdio' => false],
            'http' => ['path' => '/mcp', 'allowed_hosts' => [$this->settings['rp_id']]],
            'session' => ['store' => 'file', 'directory' => $this->runtimeDirectory.'/mcp'],
            'request_state' => ['key' => $this->settings['app_secret']],
            'registry' => ['tools' => [DemoTools::class, ApprovalStatusTool::class], 'apps' => [ApprovalCardApp::class]],
        ]]]);
        $container->extension('exact_approval', [
            'public_origin' => $this->settings['origin'], 'actor_provider' => DemoActorProvider::class,
            'policy_provider' => DemoPolicyProvider::class, 'authorizer' => DemoAuthorizer::class,
            'store' => PdoApprovalStore::class, 'verifier' => WebAuthnVerifier::class,
            // Each receipt is signed as the action commits, so a later edit to the record shows.
            'receipt_signer' => SodiumEvidenceSigner::class,
            'attempts_per_minute' => $this->settings['approval_attempts_per_minute'] ?? 30,
            // Claude Code shows URL-mode elicitation (2.1.287 and later); other clients get the card and link.
            'url_elicitation' => ['enabled' => true, 'clients' => ['claude-code']],
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load('ExactApproval\\Demo\\', __DIR__.'/')->exclude([__DIR__.'/DemoKernel.php', __DIR__.'/DemoSetup.php', __DIR__.'/Security/DemoUser.php']);
        $services->set(\PDO::class, \PDO::class)->factory([DemoDatabase::class, 'connect'])->args([$this->runtimeDirectory.'/application.sqlite']);
        $services->set(PdoApprovalStore::class);
        $services->set(PdoCredentialRepository::class);
        // Approvals accept only passkeys whose signed enrollment record vouches for them.
        $services->set(AuditLog::class)->args([service(\PDO::class), service(SodiumEvidenceSigner::class), $this->settings['receipt_public_key'], service('exact_approval.clock')]);
        $services->set(AnchoredCredentialRepository::class);
        $services->alias(CredentialRepository::class, AnchoredCredentialRepository::class);
        $services->alias(RequesterLabel::class, DemoRequesterLabel::class);
        $services->set(Configuration::class)->args([$this->settings['rp_id'], $this->settings['origin']]);
        $services->set(WebAuthnVerifier::class);
        $services->set(SodiumEvidenceSigner::class, SodiumEvidenceSigner::class)->factory([SodiumEvidenceSigner::class, 'fromEncoded'])->args([$this->settings['receipt_key_id'], $this->settings['receipt_secret_key']]);
        $services->set(EvidenceExporter::class)->args([service(SodiumEvidenceSigner::class)]);
        foreach ([DemoController::class, AccountController::class, AuditController::class, EvidenceController::class, PublicController::class, OAuthController::class] as $controller) $services->set($controller)->public()->tag('controller.service_arguments');
        $services->set('demo.enrollment_limiter', RateLimiterFactory::class)->args([
            ['id' => 'demo-passkey-enrollment', 'policy' => 'fixed_window', 'limit' => $this->settings['enrollment_attempts_per_minute'] ?? 10, 'interval' => '1 minute'],
            service('exact_approval.limiter.storage'), service('exact_approval.limiter.lock_factory'),
        ]);
        $services->get(AccountController::class)->arg('$limiter', service('demo.enrollment_limiter'));
        $services->get(OAuthController::class)->arg('$origin', rtrim($this->settings['origin'], '/'));
        $services->get(PersonalTokenAuthenticator::class)->arg('$origin', rtrim($this->settings['origin'], '/'));
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'mcp');
        $routes->import(\dirname(__DIR__, 3).'/packages/symfony/config/routes.php');
        $routes->add('exact_approval_home', '/')->controller([DemoController::class, 'home'])->methods(['GET']);
        $routes->add('exact_approval_login', '/login')->controller([AccountController::class, 'login'])->methods(['GET', 'POST']);
        $routes->add('exact_approval_logout', '/logout')->methods(['POST']);
        $routes->add('exact_approval_passkeys', '/account/passkeys')->controller([AccountController::class, 'passkeys'])->methods(['GET']);
        $routes->add('exact_approval_enrollment_options', '/account/passkeys/options')->controller([AccountController::class, 'enrollmentOptions'])->methods(['POST']);
        $routes->add('exact_approval_enrollment_complete', '/account/passkeys/complete')->controller([AccountController::class, 'enrollmentComplete'])->methods(['POST']);
        $routes->add('demo_passkey_link', '/account/passkeys/link/{token}')->controller([AccountController::class, 'passkeyLink'])->methods(['GET']);
        $routes->add('demo_confirm_options', '/account/passkeys/confirm/options')->controller([AccountController::class, 'confirmOptions'])->methods(['POST']);
        $routes->add('demo_confirm_complete', '/account/passkeys/confirm/complete')->controller([AccountController::class, 'confirmComplete'])->methods(['POST']);
        $routes->add('demo_passkey_revoke', '/account/passkeys/{id}/revoke')->controller([AccountController::class, 'revokePasskey'])->methods(['POST']);
        $routes->add('demo_connection_revoke', '/account/connections/{id}/revoke')->controller([AccountController::class, 'revokeConnection'])->methods(['POST']);
        $routes->add('demo_audit', '/admin/audit')->controller([AuditController::class, 'audit'])->methods(['GET']);
        $routes->add('demo_audit_evidence', '/admin/audit/{id}/evidence')->controller([AuditController::class, 'evidence'])->methods(['GET']);
        $routes->add('demo_audit_passkey_revoke', '/admin/audit/passkeys/{id}/revoke')->controller([AuditController::class, 'revokePasskey'])->methods(['POST']);
        $routes->add('demo_audit_connection_revoke', '/admin/audit/connections/{id}/revoke')->controller([AuditController::class, 'revokeConnection'])->methods(['POST']);
        $routes->add('demo_audit_link', '/admin/audit/links')->controller([AuditController::class, 'issueLink'])->methods(['POST']);
        $routes->add('exact_approval_policy', '/admin/approval-policy')->controller([DemoController::class, 'policy'])->methods(['GET', 'POST']);
        $routes->add('exact_approval_request_publication', '/announcements/{id}/publish')->controller([DemoController::class, 'requestPublication'])->methods(['POST']);
        $routes->add('exact_approval_edit_draft', '/announcements/{id}/draft')->controller([DemoController::class, 'editDraft'])->methods(['POST']);
        $routes->add('oauth_protected_resource', '/.well-known/oauth-protected-resource')->controller([OAuthController::class, 'protectedResource'])->methods(['GET']);
        $routes->add('oauth_protected_resource_mcp', '/.well-known/oauth-protected-resource/mcp')->controller([OAuthController::class, 'protectedResource'])->methods(['GET']);
        $routes->add('oauth_metadata', '/.well-known/oauth-authorization-server')->controller([OAuthController::class, 'authorizationServer'])->methods(['GET']);
        $routes->add('oauth_register', '/oauth/register')->controller([OAuthController::class, 'register'])->methods(['POST']);
        $routes->add('oauth_authorize', '/oauth/authorize')->controller([OAuthController::class, 'authorize'])->methods(['GET', 'POST']);
        $routes->add('oauth_token', '/oauth/token')->controller([OAuthController::class, 'token'])->methods(['POST']);
        $routes->add('riverside_alerts', '/alerts')->controller([PublicController::class, 'alerts'])->methods(['GET']);
        $routes->add('riverside_styles', '/site/riverside.css')->controller([PublicController::class, 'styles'])->methods(['GET']);
        $routes->add('exact_approval_evidence', '/approval/{id}/evidence')->controller([EvidenceController::class, 'export'])->methods(['GET']);
    }
}
