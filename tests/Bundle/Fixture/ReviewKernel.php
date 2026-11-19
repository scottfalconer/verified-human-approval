<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle\Fixture;

use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use VerifiedHumanApproval\Bundle\Mcp\ApprovalCardApp;
use VerifiedHumanApproval\Bundle\VerifiedHumanApprovalBundle;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAction;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAuthorizer;
use VerifiedHumanApproval\StoreContracts\Fixture\FixturePolicies;
use VerifiedHumanApproval\StoreContracts\Fixture\TestProofVerifier;
use VerifiedHumanApproval\Tests\Support\CoreFixture;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The bundle on its own, as an application installs it: FrameworkBundle, TwigBundle and the bundle, over the core
 * test fixtures' SQLite application, with alice signed in and receipts signed. Each test chooses the bundle's
 * configuration, whether there is a translator, an application layout around the review pages, whether an MCP
 * server at /mcp serves alice's agent, and which of the bundle's services the application replaces with its own.
 *
 * The compiled container depends only on those choices, so kernels that make the same ones share it; the
 * database is the runtime's, reached through the kernel at runtime.
 */
final class ReviewKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed>                      $approval     verified_human_approval configuration beyond the required services
     * @param array<string, array<string, string>>|null $translations a translator holding these messages by domain, or no translator
     * @param string|null                               $templates    a Twig default path, whose bundles/VerifiedHumanApprovalBundle/ overrides the bundle's templates
     * @param list<class-string>|null                   $tools        the MCP server's tools, beside the fixture's PublishArticleTool and the card; or no MCP server
     * @param array<string, class-string>               $services     services the application defines, by id, in place of any the bundle defines under that id
     */
    public function __construct(
        private readonly string $runtime,
        private readonly array $approval = [],
        private readonly ?array $translations = null,
        private readonly ?string $templates = null,
        /** @var array{locale: string, path: string}|null Symfony's own translator, in this locale, with the application's catalogues in this directory */
        private readonly ?array $realTranslator = null,
        private readonly ?array $tools = null,
        private readonly array $services = [],
    ) {
        parent::__construct('test', false);
    }

    /** A runtime directory holding the fixture application's database, approval tables included. */
    public static function runtime(): string
    {
        $runtime = sys_get_temp_dir().'/verified-human-approval-review-kernel-'.bin2hex(random_bytes(8));
        mkdir($runtime, 0700);
        new CoreFixture($runtime.'/application.sqlite');

        return $runtime;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        if (null !== $this->tools) {
            yield new McpBundle();
        }
        yield new VerifiedHumanApprovalBundle();
    }

    public function getCacheDir(): string
    {
        return $this->compiled().'/cache';
    }

    public function getLogDir(): string
    {
        return $this->compiled().'/log';
    }

    /** The service factory for the runtime's database: the same file CoreFixture set up. */
    public function database(): \PDO
    {
        $pdo = new \PDO('sqlite:'.$this->runtime.'/application.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 15000');

        return $pdo;
    }

    /** The service factory for the receipt signer: a fixed test key, so the completed page offers the receipt. */
    public function signer(): SodiumEvidenceSigner
    {
        return new SodiumEvidenceSigner('review-kernel-test', sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat("\1", \SODIUM_CRYPTO_SIGN_SEEDBYTES))));
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'review-kernel-test-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'router' => ['utf8' => true],
            // Its own, as every application sets them: the trusted hosts are global, and an earlier kernel's last.
            'trusted_hosts' => ['^app\.example$'],
            'session' => ['handler_id' => null, 'storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => true,
            'lock' => 'flock',
            'property_info' => ['with_constructor_extractor' => true],
            // Off unless a test asks for Symfony's own translator, so "no translator" stays true when the component is installed.
            'translator' => null === $this->realTranslator ? ['enabled' => false] : ['enabled' => true, 'default_path' => $this->realTranslator['path'], 'fallbacks' => ['en']],
            'default_locale' => $this->realTranslator['locale'] ?? 'en',
        ]);
        $container->extension('twig', ['strict_variables' => true] + (null === $this->templates ? [] : ['default_path' => $this->templates]));
        $container->extension('verified_human_approval', [
            'public_origin' => 'https://app.example',
            'actor_provider' => SignedInAlice::class,
            'policy_provider' => FixturePolicies::class,
            'authorizer' => FixtureAuthorizer::class,
            'store' => PdoApprovalStore::class,
            'verifier' => TestProofVerifier::class,
            'receipt_signer' => SodiumEvidenceSigner::class,
        ] + $this->approval);

        $services = $container->services()->defaults()->autowire();
        $services->set(\PDO::class, \PDO::class)->factory([service('kernel'), 'database']);
        $services->set(SodiumEvidenceSigner::class, SodiumEvidenceSigner::class)->factory([service('kernel'), 'signer']);
        $services->set(PdoApprovalStore::class);
        // The fixtures take PDO or another fixture database, a union autowiring cannot choose for.
        $services->set(FixturePolicies::class)->arg('$database', service(\PDO::class));
        $services->set(FixtureAuthorizer::class)->arg('$database', service(\PDO::class));
        $services->set(TestProofVerifier::class)->arg('$database', service(\PDO::class));
        $services->set(FixtureAction::class)->arg('$database', service(\PDO::class))->tag('verified_human_approval.action');
        $services->set(SignedInAlice::class);
        foreach ($this->services as $id => $class) {
            $services->set($id, $class);
        }
        if (null !== $this->tools) {
            // Stateless MCP 2026-07-28 calls, as the agent's client makes them; the connection is alice's.
            $container->extension('mcp', ['servers' => ['application' => [
                'http' => ['path' => '/mcp', 'allowed_hosts' => ['app.example']],
                'session' => ['store' => 'memory'],
                'registry' => ['tools' => [PublishArticleTool::class, ...$this->tools], 'apps' => [ApprovalCardApp::class]],
            ]]]);
            $services->set(PublishArticleTool::class)->autoconfigure();
        }
        if (null !== $this->translations) {
            // Doubled, so the container leaves placeholders such as %agent% to the translator.
            $escape = static fn (array $messages): array => array_combine(str_replace('%', '%%', array_keys($messages)), str_replace('%', '%%', $messages));
            $services->set('translator', OverridingTranslator::class)->args([array_map($escape, $this->translations)]);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@VerifiedHumanApprovalBundle/config/routes.php');
        if (null !== $this->tools) {
            $routes->import('.', 'mcp');
        }
    }

    /** Where the container for these choices is compiled: shared by the test run, removed at its end. */
    private function compiled(): string
    {
        $shared = getenv('VERIFIED_HUMAN_APPROVAL_TEST_CACHE');
        $root = \is_string($shared) && '' !== $shared ? $shared : sys_get_temp_dir().'/verified-human-approval-review-kernel';

        return $root.'/review-kernel-'.hash('xxh128', serialize([$this->approval, $this->translations, $this->templates, $this->realTranslator, $this->tools, $this->services]));
    }
}
