<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Yaml\Yaml;
use VerifiedHumanApproval\Bundle\VerifiedHumanApprovalBundle;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAuthorizer;
use VerifiedHumanApproval\StoreContracts\Fixture\FixturePolicies;
use VerifiedHumanApproval\Testing\VirtualAuthenticator;
use VerifiedHumanApproval\Tests\Bundle\Fixture\SignedInAlice;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The worked example's services, as first-action.md prints them, beside the bundle: the verifier reads passkeys
 * through AnchoredCredentialRepository, which reads the raw PdoCredentialRepository on the shared connection, so a
 * passkey is offered only once its enrollment is signed.
 */
final class WorkedExampleServicesTest extends TestCase
{
    private const GUIDE = __DIR__.'/../../src/symfony-bundle/docs/first-action.md';

    private string $directory;
    private ?Kernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/verified-human-approval-worked-example-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove($this->directory);
    }

    public function testTheVerifierReadsPasskeysThroughTheAnchoredRepository(): void
    {
        $container = $this->boot();
        // The passkey and audit tables, as the guide's install command creates them.
        self::service($container, PdoCredentialRepository::class)->initialize();
        self::service($container, PdoAuditStore::class)->initialize();
        $verifier = self::service($container, WebAuthnVerifier::class);
        $alice = new Actor('alice', 'tenant-a');
        $passkey = new VirtualAuthenticator('https://app.example');
        $options = $verifier->registrationOptions($alice, random_bytes(32), 'Alice');
        $credential = $verifier->register($alice, $options, $passkey->registration($options));
        $stored = self::service($container, \PDO::class)->query('SELECT COUNT(*) FROM verified_human_approval_credentials');
        self::assertInstanceOf(\PDOStatement::class, $stored);
        self::assertSame(1, (int) $stored->fetchColumn(), 'The raw row is on the shared connection.');

        try {
            $verifier->requestOptions($alice, random_bytes(32));
            self::fail('A passkey that no signed enrollment vouches for was offered.');
        } catch (ApprovalException $error) {
            self::assertSame('credential_unvouched', $error->reason, 'The verifier weighs the raw row through the anchored wrapper.');
        }
        self::service($container, AnchoredCredentialRepository::class)->anchor($credential, ['method' => 'test']);
        self::assertSame([$passkey->id()], array_column($verifier->requestOptions($alice, random_bytes(32))['allowCredentials'] ?? [], 'id'), 'Once its enrollment is signed, the passkey is offered.');
    }

    /** Boots the bundle with the guide's services, and returns the test container. */
    private function boot(): ContainerInterface
    {
        preg_match_all('/^```yaml\n(.*?)^```$/ms', (string) file_get_contents(self::GUIDE), $blocks);
        $services = [];
        foreach ($blocks[1] as $block) {
            $services += (array) (Yaml::parse($block)['services'] ?? []);
        }
        self::assertArrayHasKey(WebAuthnVerifier::class, $services, 'The guide defines the verifier.');
        // Without the reader's own classes, which the guide writes out, and with a connection in place of the one their
        // factory opens: in memory, so only that one connection sees what is written on it.
        $services = array_filter($services, static fn (string $id): bool => !str_starts_with($id, 'App\\'), \ARRAY_FILTER_USE_KEY);
        $services['PDO'] = ['class' => \PDO::class, 'arguments' => ['sqlite::memory:']];
        // The settings the guide adds to .env.local, in place of the environment.
        $settings = [
            '%env(APPROVAL_RECEIPT_KEY_ID)%' => 'application-1',
            '%env(APPROVAL_RECEIPT_SECRET_KEY)%' => ChallengeBinding::encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
            '%env(APP_ORIGIN)%' => 'https://app.example',
            '%env(APP_RP_ID)%' => 'app.example',
        ];
        array_walk_recursive($services, static function (mixed &$value) use ($settings): void {
            $value = \is_string($value) ? strtr($value, $settings) : $value;
        });
        file_put_contents($this->directory.'/services.yaml', Yaml::dump(['services' => $services], 4));

        $this->kernel = new class($this->directory) extends Kernel {
            use MicroKernelTrait;

            public function __construct(private readonly string $directory)
            {
                parent::__construct('test', false);
            }

            public function registerBundles(): iterable
            {
                yield new FrameworkBundle();
                yield new TwigBundle();
                yield new VerifiedHumanApprovalBundle();
            }

            public function getProjectDir(): string
            {
                return $this->directory;
            }

            public function getCacheDir(): string
            {
                return $this->directory.'/cache';
            }

            public function getLogDir(): string
            {
                return $this->directory.'/log';
            }

            /** The bundle as index.md configures it, with the fixture application in place of the reader's own classes. */
            protected function configureContainer(ContainerConfigurator $container): void
            {
                $container->extension('framework', [
                    'secret' => 'worked-example-test', 'test' => true, 'http_method_override' => false, 'handle_all_throwables' => true,
                    'csrf_protection' => true, 'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
                    'lock' => 'flock', 'property_info' => ['with_constructor_extractor' => true],
                ]);
                $container->extension('verified_human_approval', [
                    'public_origin' => 'https://app.example',
                    'actor_provider' => SignedInAlice::class,
                    'policy_provider' => FixturePolicies::class,
                    'authorizer' => FixtureAuthorizer::class,
                    'store' => PdoApprovalStore::class,
                    'verifier' => WebAuthnVerifier::class,
                    'receipt_signer' => SodiumEvidenceSigner::class,
                ]);
                $container->import($this->directory.'/services.yaml');
                $services = $container->services();
                $services->set(SignedInAlice::class);
                $services->set(FixturePolicies::class)->args([service(\PDO::class)]);
                $services->set(FixtureAuthorizer::class)->args([service(\PDO::class)]);
            }
        };
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(ContainerInterface $container, string $id): object
    {
        $service = $container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
