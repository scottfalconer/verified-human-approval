<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAction;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAuthorizer;
use VerifiedHumanApproval\StoreContracts\Fixture\FixturePolicies;
use VerifiedHumanApproval\StoreContracts\Fixture\TestProofVerifier;
use VerifiedHumanApproval\Tests\Support\DbalFixtureDatabase;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * An application on DoctrineBundle that lets the bundle wire its storage: persistence: { driver: doctrine }. The
 * fixture application (action, permissions, policy, test-only verifier) reads through the same connection.
 */
final class DoctrineKernel extends Kernel
{
    use MicroKernelTrait;

    /** @param array<string, mixed> $approval the verified_human_approval configuration */
    public function __construct(private readonly string $directory, private readonly string $database, private readonly array $approval)
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new \VerifiedHumanApproval\Bundle\VerifiedHumanApprovalBundle();
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

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'doctrine-kernel-test', 'test' => true, 'http_method_override' => false, 'handle_all_throwables' => true,
            'csrf_protection' => true, 'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'lock' => 'flock', 'router' => ['utf8' => true, 'resource' => __DIR__.'/routes.php'],
            'property_info' => ['with_constructor_extractor' => true],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'path' => $this->database],
            'orm' => [
                'controller_resolver' => ['auto_mapping' => false],
                'mappings' => ['Fixture' => ['type' => 'attribute', 'dir' => \dirname(__DIR__, 2).'/Doctrine/Fixture', 'prefix' => 'VerifiedHumanApproval\Tests\Doctrine\Fixture']],
            ],
        ]);
        $container->extension('verified_human_approval', $this->approval);

        $services = $container->services();
        $services->set(FixedActorProvider::class);
        $services->set(SodiumEvidenceSigner::class)->factory([WiringProbe::class, 'signer']);
        $services->set(WiringProbe::class)->autowire()->public();
        $services->set(DbalFixtureDatabase::class)->args([service(DbalTransaction::class)]);
        $services->set(FixturePolicies::class)->args([service(DbalFixtureDatabase::class)]);
        $services->set(FixtureAuthorizer::class)->args([service(DbalFixtureDatabase::class)]);
        $services->set(TestProofVerifier::class)->args([service(DbalFixtureDatabase::class)]);
        $services->set(FixtureAction::class)->args([service(DbalFixtureDatabase::class), service(ExecutionGuard::class)])->tag('verified_human_approval.action');
    }
}
