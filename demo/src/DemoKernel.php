<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo;

use League\Bundle\OAuth2ServerBundle\LeagueOAuth2ServerBundle;
use League\Bundle\OAuth2ServerBundle\Manager\InMemory\DeviceCodeManager;
use Omouren\McpOauthBundle\Cimd\ClientIdMetadataResolver;
use Omouren\McpOauthBundle\McpOauthBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalCardApp;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalHandoffTool;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalStatusTool;
use VerifiedHumanApproval\Bundle\SecurityHeadersSubscriber;
use VerifiedHumanApproval\Bundle\VerifiedHumanApprovalBundle;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\Demo\Action\DemoAuthorizer;
use VerifiedHumanApproval\Demo\Action\DemoPolicyProvider;
use VerifiedHumanApproval\Demo\Action\PublishAlert;
use VerifiedHumanApproval\Demo\Controller\AccountController;
use VerifiedHumanApproval\Demo\Controller\AuditController;
use VerifiedHumanApproval\Demo\Controller\DemoController;
use VerifiedHumanApproval\Demo\Controller\PublicController;
use VerifiedHumanApproval\Demo\Mcp\DemoTools;
use VerifiedHumanApproval\Demo\OAuth\ClientMetadataHttpClient;
use VerifiedHumanApproval\Demo\OAuth\ConnectionOpeningCodeManager;
use VerifiedHumanApproval\Demo\OAuth\ConsentExtension;
use VerifiedHumanApproval\Demo\OAuth\Storage\PdoAccessTokenManager;
use VerifiedHumanApproval\Demo\OAuth\Storage\PdoClientManager;
use VerifiedHumanApproval\Demo\OAuth\Storage\PdoCredentialsRevoker;
use VerifiedHumanApproval\Demo\OAuth\Storage\PdoRefreshTokenManager;
use VerifiedHumanApproval\Demo\Security\AgentTokenAuthenticator;
use VerifiedHumanApproval\Demo\Security\DemoActorProvider;
use VerifiedHumanApproval\Demo\Security\DemoRequesterLabel;
use VerifiedHumanApproval\Demo\Security\DemoUser;
use VerifiedHumanApproval\Demo\Security\DemoUserProvider;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\CredentialRepository;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class DemoKernel extends Kernel
{
    use MicroKernelTrait;

    private readonly array $settings;

    public function __construct(private readonly string $runtimeDirectory, string $environment = 'dev', bool $debug = false)
    {
        if (!is_file($runtimeDirectory.'/settings.json')) {
            throw new \RuntimeException('Initialize the demo with: php demo/bin/setup.php');
        }
        $settings = file_get_contents($runtimeDirectory.'/settings.json');
        if (false === $settings) {
            throw new \RuntimeException('Cannot read '.$runtimeDirectory.'/settings.json. Start a fresh demo with: php demo/bin/setup.php --reset');
        }
        $this->settings = json_decode($settings, true, 32, \JSON_THROW_ON_ERROR);
        if (($this->settings['schema'] ?? 1) < DemoSetup::SCHEMA) {
            throw new \RuntimeException('This demo was set up by an older version. Start a fresh one with: php demo/bin/setup.php --reset');
        }
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new MonologBundle();
        yield new McpBundle();
        // Remote MCP clients connect through OAuth: league/oauth2-server issues and checks the tokens, and
        // omouren/mcp-oauth-bundle adds MCP's discovery, client ID metadata documents, registration and consent.
        yield new LeagueOAuth2ServerBundle();
        yield new McpOauthBundle();
        yield new VerifiedHumanApprovalBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    /** The runtime this kernel serves; its settings reach services through DemoRuntimeSettings. */
    public function runtimeDirectory(): string
    {
        return $this->runtimeDirectory;
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * The compiled container depends on no runtime, so a test run shares one: the bootstrap names its
     * directory. Elsewhere each runtime keeps its own.
     */
    public function getCacheDir(): string
    {
        return ($this->sharedTestDirectory() ?? $this->runtimeDirectory).'/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return ($this->sharedTestDirectory() ?? $this->runtimeDirectory).'/log';
    }

    private function sharedTestDirectory(): ?string
    {
        $directory = 'test' === $this->environment ? getenv('VERIFIED_HUMAN_APPROVAL_TEST_CACHE') : false;

        return \is_string($directory) && '' !== $directory ? $directory : null;
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        // Every runtime-specific value is an environment variable that DemoRuntimeSettings serves from this
        // runtime's settings.json, so nothing here depends on which runtime the container was compiled for.
        $origin = '%env(DEMO_RUNTIME_ORIGIN)%';
        $runtime = '%env(DEMO_RUNTIME_DIRECTORY)%';
        $container->extension('framework', [
            'secret' => '%env(DEMO_RUNTIME_SECRET)%',
            'test' => 'test' === $this->environment,
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'trusted_hosts' => ['%env(DEMO_RUNTIME_TRUSTED_HOST)%'],
            // The public copy runs behind a local tunnel; only localhost may forward the original scheme and host.
            'trusted_proxies' => '127.0.0.1,::1',
            'trusted_headers' => ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host'],
            'session' => [
                'handler_id' => null, 'save_path' => $runtime.'/sessions',
                'cookie_secure' => 'auto', 'cookie_httponly' => true, 'cookie_samesite' => 'lax',
                'storage_factory_id' => 'test' === $this->environment ? 'session.storage.factory.mock_file' : 'session.storage.factory.native',
            ],
            'csrf_protection' => true,
            'form' => true,
            'validation' => ['email_validation_mode' => 'html5'],
            // Each runtime's cache pools (rate-limit state, phone codes) stay its own.
            'cache' => ['directory' => $runtime.'/cache/pools'],
            // Locks for the rate limiters and the phone code's single redemption.
            'lock' => 'flock',
            'rate_limiter' => [
                'demo_passkey_enrollment' => ['policy' => 'fixed_window', 'limit' => '%env(int:DEMO_RUNTIME_ENROLLMENT_ATTEMPTS)%', 'interval' => '1 minute'],
                'demo_oauth_registration' => ['policy' => 'fixed_window', 'limit' => '%env(int:DEMO_RUNTIME_REGISTRATION_ATTEMPTS)%', 'interval' => '1 minute'],
            ],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        // The MCP bundle logs to its own "mcp" channel: session-save failures, refused request state and the like land here.
        $container->extension('monolog', ['channels' => ['mcp'], 'handlers' => [
            'main' => ['type' => 'stream', 'path' => '%kernel.logs_dir%/%kernel.environment%.log', 'level' => 'info', 'channels' => ['!event']],
        ]]);
        $container->extension('security', [
            'password_hashers' => [DemoUser::class => ['algorithm' => 'auto']],
            'providers' => ['demo' => ['id' => DemoUserProvider::class]],
            'firewalls' => [
                'mcp' => [
                    'pattern' => '^/mcp(?:$|/)', 'stateless' => true, 'provider' => 'demo',
                    'custom_authenticators' => [AgentTokenAuthenticator::class],
                    'entry_point' => AgentTokenAuthenticator::class,
                ],
                'main' => [
                    'provider' => 'demo',
                    'form_login' => ['login_path' => 'demo_login', 'check_path' => 'demo_login', 'enable_csrf' => true, 'default_target_path' => '/'],
                    'login_throttling' => ['max_attempts' => 5, 'interval' => '1 minute'],
                    'logout' => ['path' => 'demo_logout', 'target' => 'demo_login', 'enable_csrf' => true],
                ],
            ],
            'access_control' => [
                ['path' => '^/login$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/(alerts$|site/)', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/(\.well-known/|oauth/(register|token)$)', 'roles' => 'PUBLIC_ACCESS'],
                // The bundle's review pages open without a sign-in, for a one-time phone link; its controller
                // still requires the signed-in person or a session scoped to that one request.
                ['request_matcher' => 'verified_human_approval.review_request_matcher', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/mcp(?:$|/)', 'roles' => 'ROLE_USER'],
                ['path' => '^/', 'roles' => 'ROLE_USER'],
            ],
        ]);
        $container->extension('mcp', ['servers' => ['application' => [
            'name' => 'Verified Human Approval demo', 'version' => '0.1.0',
            'instructions' => 'Riverside Council public alerts. The draft evacuation alert is riverside-evacuation: read it with read_alert, then ask to issue it with publish_alert. If approval is required, show the review URL; only the person’s own passkey on the site can approve. Call approval_status afterwards and never say the alert is live while it is pending. If a retried call is refused, call approval_status with the request id instead of asking again.',
            'transports' => ['http' => true, 'stdio' => false],
            'http' => ['path' => '/mcp', 'allowed_hosts' => ['%env(DEMO_RUNTIME_RP_ID)%']],
            // Handshake-era clients (Claude Desktop, claude.ai) keep a session, and the card polls it while the
            // model calls tools: the lock serializes those requests so none overwrites what another saved.
            'session' => ['store' => 'file', 'directory' => $runtime.'/mcp', 'lock' => ['enabled' => true]],
            // The signed state a URL-mode elicitation's retry carries. Its own key, derived from the application
            // secret, and a lifetime past the proposal's, so a retry after a slow approval still reads the outcome.
            'request_state' => ['key' => '%env(DEMO_RUNTIME_REQUEST_STATE_KEY)%', 'ttl' => DemoPolicyProvider::LIFETIME_SECONDS + 300],
            'registry' => ['tools' => [DemoTools::class, ApprovalStatusTool::class, ApprovalHandoffTool::class], 'apps' => [ApprovalCardApp::class]],
        ]]]);
        // The OAuth server. Its codes, tokens and clients live on the demo's own SQLite connection (demo/src/OAuth/Storage).
        $container->extension('league_oauth2_server', [
            'authorization_server' => [
                'private_key' => '%env(DEMO_RUNTIME_OAUTH_PRIVATE_KEY)%',
                'encryption_key' => '%env(DEMO_RUNTIME_OAUTH_ENCRYPTION_KEY)%',
                'encryption_key_type' => 'plain',
                'access_token_ttl' => 'PT1H',
                'refresh_token_ttl' => 'P30D',
                'auth_code_ttl' => 'PT5M',
                'enable_client_credentials_grant' => false,
                'enable_password_grant' => false,
                'enable_implicit_grant' => false,
                'enable_auth_code_grant' => true,
                'enable_refresh_token_grant' => true,
                'require_code_challenge_for_public_clients' => true,
            ],
            'resource_server' => ['public_key' => '%env(DEMO_RUNTIME_OAUTH_PUBLIC_KEY)%'],
            'scopes' => ['available' => array_keys(ConsentExtension::SCOPE_DESCRIPTIONS), 'default' => array_keys(ConsentExtension::SCOPE_DESCRIPTIONS)],
            'persistence' => ['custom' => [
                'access_token_manager' => PdoAccessTokenManager::class,
                // Saving a new code is when the person's consent opens the agent connection its tokens belong to.
                'authorization_code_manager' => ConnectionOpeningCodeManager::class,
                'client_manager' => PdoClientManager::class,
                'refresh_token_manager' => PdoRefreshTokenManager::class,
                'credentials_revoker' => PdoCredentialsRevoker::class,
                // The device flow is never enabled, so its codes need no storage that lasts.
                'device_code_manager' => DeviceCodeManager::class,
            ]],
        ]);
        // MCP authorization on top of it: RFC 8414 and 9728 metadata, client ID metadata documents (claude.ai's),
        // registration, resource-bound tokens, the `iss` response parameter and the WWW-Authenticate challenge.
        $container->extension('mcp_oauth', [
            'authorization_prefix' => '/oauth',
            'scopes' => array_keys(ConsentExtension::SCOPE_DESCRIPTIONS),
            'login_route' => 'demo_login',
            'consent' => 'page',
            'public_base_url' => $origin,
        ]);
        $container->extension('verified_human_approval', [
            'public_origin' => $origin, 'actor_provider' => DemoActorProvider::class,
            'policy_provider' => DemoPolicyProvider::class, 'authorizer' => DemoAuthorizer::class,
            'store' => PdoApprovalStore::class, 'verifier' => WebAuthnVerifier::class,
            // Names the agent connection on the review page, so an agent's request never looks like the person's own.
            'requester_label' => DemoRequesterLabel::class,
            // Each receipt is signed as the action commits, so a later edit to the record shows. The person can
            // download theirs from the completed page, and /approval/receipt-key.json publishes the key that checks it.
            'receipt_signer' => SodiumEvidenceSigner::class,
            'relying_party_id' => '%env(DEMO_RUNTIME_RP_ID)%',
            'attempts_per_minute' => '%env(int:DEMO_RUNTIME_APPROVAL_ATTEMPTS)%',
            // The framework's lock, which a multi-host deployment would back with a shared store.
            'lock_factory' => 'lock.factory',
            // Claude Code shows URL-mode elicitation (2.1.287 and later); other clients get the card and link.
            'url_elicitation' => ['enabled' => true, 'clients' => ['claude-code']],
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load('VerifiedHumanApproval\\Demo\\', __DIR__.'/')->exclude([__DIR__.'/DemoKernel.php', __DIR__.'/DemoSetup.php', __DIR__.'/Security/DemoUser.php']);
        $services->set(DemoRuntimeSettings::class)->autoconfigure(false)->args([service('kernel')])->tag('container.env_var_loader');
        $services->set(\PDO::class, \PDO::class)->factory([DemoDatabase::class, 'connect'])->args([$runtime.'/application.sqlite']);
        $services->set(PdoApprovalStore::class);
        $services->set(PdoCredentialRepository::class);
        // Approvals accept only passkeys whose signed enrollment record vouches for them.
        $services->set(AuditLog::class)->args([service(\PDO::class), service(SodiumEvidenceSigner::class), '%env(DEMO_RUNTIME_RECEIPT_PUBLIC_KEY)%', service('verified_human_approval.clock')]);
        $services->set(AnchoredCredentialRepository::class);
        $services->alias(CredentialRepository::class, AnchoredCredentialRepository::class);
        $services->set(Configuration::class)->args(['%env(DEMO_RUNTIME_RP_ID)%', $origin]);
        $services->set(WebAuthnVerifier::class);
        $services->set(SodiumEvidenceSigner::class, SodiumEvidenceSigner::class)->factory([SodiumEvidenceSigner::class, 'fromEncoded'])->args(['%env(DEMO_RUNTIME_RECEIPT_KEY_ID)%', '%env(DEMO_RUNTIME_RECEIPT_SECRET_KEY)%']);
        // One description of the protected resource (RFC 9728): served at /.well-known and named in every 401.
        // Client ID metadata documents are fetched through a client that stays off this server's network and stops at a size limit.
        $services->set(DeviceCodeManager::class);
        $services->set(ClientMetadataHttpClient::class);
        $services->set(ClientIdMetadataResolver::class)->arg('$httpClient', service(ClientMetadataHttpClient::class))->arg('$cache', service('cache.app'));
        $services->get(PublishAlert::class)->arg('$layoutFiles', '%env(json:DEMO_RUNTIME_LAYOUT_FILES)%');
        $services->get(AccountController::class)->arg('$limiter', service('limiter.demo_passkey_enrollment'));
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'mcp');
        $routes->import('@VerifiedHumanApprovalBundle/config/routes.php');
        // The account, passkey and administration pages get the same headers as the bundle's review pages.
        $private = [SecurityHeadersSubscriber::ROUTE_DEFAULT => true];
        $routes->add('demo_home', '/')->controller([DemoController::class, 'home'])->methods(['GET'])->defaults($private);
        $routes->add('demo_login', '/login')->controller([AccountController::class, 'login'])->methods(['GET', 'POST'])->defaults($private);
        $routes->add('demo_logout', '/logout')->methods(['POST'])->defaults($private);
        $routes->add('demo_passkeys', '/account/passkeys')->controller([AccountController::class, 'passkeys'])->methods(['GET'])->defaults($private);
        $routes->add('demo_enrollment_options', '/account/passkeys/options')->controller([AccountController::class, 'enrollmentOptions'])->methods(['POST'])->defaults($private);
        $routes->add('demo_enrollment_complete', '/account/passkeys/complete')->controller([AccountController::class, 'enrollmentComplete'])->methods(['POST'])->defaults($private);
        $routes->add('demo_passkey_link', '/account/passkeys/link/{token}')->controller([AccountController::class, 'passkeyLink'])->methods(['GET'])->defaults($private);
        $routes->add('demo_confirm_options', '/account/passkeys/confirm/options')->controller([AccountController::class, 'confirmOptions'])->methods(['POST'])->defaults($private);
        $routes->add('demo_confirm_complete', '/account/passkeys/confirm/complete')->controller([AccountController::class, 'confirmComplete'])->methods(['POST'])->defaults($private);
        $routes->add('demo_passkey_revoke', '/account/passkeys/{id}/revoke')->controller([AccountController::class, 'revokePasskey'])->methods(['POST'])->defaults($private);
        $routes->add('demo_connection_revoke', '/account/connections/{id}/revoke')->controller([AccountController::class, 'revokeConnection'])->methods(['POST'])->defaults($private);
        $routes->add('demo_audit', '/admin/audit')->controller([AuditController::class, 'audit'])->methods(['GET'])->defaults($private);
        $routes->add('demo_audit_evidence', '/admin/audit/{id}/evidence')->controller([AuditController::class, 'evidence'])->methods(['GET'])->defaults($private);
        $routes->add('demo_audit_passkey_revoke', '/admin/audit/passkeys/{id}/revoke')->controller([AuditController::class, 'revokePasskey'])->methods(['POST'])->defaults($private);
        $routes->add('demo_audit_connection_revoke', '/admin/audit/connections/{id}/revoke')->controller([AuditController::class, 'revokeConnection'])->methods(['POST'])->defaults($private);
        $routes->add('demo_audit_link', '/admin/audit/links')->controller([AuditController::class, 'issueLink'])->methods(['POST'])->defaults($private);
        $routes->add('demo_policy', '/admin/approval-policy')->controller([DemoController::class, 'policy'])->methods(['GET', 'POST'])->defaults($private);
        $routes->add('demo_publish_alert', '/alerts/{id}/publish')->controller([DemoController::class, 'requestPublication'])->methods(['POST'])->defaults($private);
        $routes->add('demo_edit_alert', '/alerts/{id}/draft')->controller([DemoController::class, 'editDraft'])->methods(['POST'])->defaults($private);
        // OAuth: league's /oauth/authorize and /oauth/token, and omouren's metadata, registration and consent.
        $routes->import('@LeagueOAuth2ServerBundle/config/routes.php')->prefix('/oauth');
        $routes->import('@McpOauthBundle/config/routes.yaml');
        $routes->add('riverside_alerts', '/alerts')->controller([PublicController::class, 'alerts'])->methods(['GET']);
        $routes->add('riverside_styles', '/site/riverside.css')->controller([PublicController::class, 'styles'])->methods(['GET']);
    }
}
