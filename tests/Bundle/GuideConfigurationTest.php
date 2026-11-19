<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;
use VerifiedHumanApproval\Bundle\VerifiedHumanApprovalBundle;

/** The bundle configuration its guides show is configuration it takes, and every key it takes is in a guide. */
final class GuideConfigurationTest extends TestCase
{
    private const GUIDES = __DIR__.'/../../src/symfony-bundle/docs';
    /** What the snippets leave to the reader's own setup. */
    private const REQUIRED = ['public_origin' => 'https://app.example.com', 'actor_provider' => 'actors', 'policy_provider' => 'policies', 'authorizer' => 'authorizer', 'verifier' => 'verifier', 'receipt_signer' => 'signer'];

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function snippets(): iterable
    {
        foreach (glob(self::GUIDES.'/*.md') ?: [] as $guide) {
            preg_match_all('/^```ya?ml\n(.*?)^```$/ms', (string) file_get_contents($guide), $blocks);
            foreach ($blocks[1] as $number => $block) {
                $yaml = Yaml::parse($block);
                // The routes import shares the name: it is not the bundle's configuration.
                if (\is_array($yaml) && \is_array($yaml['verified_human_approval'] ?? null) && !isset($yaml['verified_human_approval']['resource'])) {
                    yield basename($guide).', block '.($number + 1) => [$yaml['verified_human_approval']];
                }
            }
        }
    }

    /** @param array<string, mixed> $snippet */
    #[DataProvider('snippets')]
    public function testAGuidesConfigurationIsTakenAsItIs(array $snippet): void
    {
        // An environment variable or parameter stands for the reader's own value.
        array_walk_recursive($snippet, static function (mixed &$value): void {
            $value = \is_string($value) && str_starts_with($value, '%') ? 'https://app.example.com' : $value;
        });
        $store = isset($snippet['store']) || isset($snippet['persistence']) ? [] : ['store' => 'store'];

        $processed = (new Processor())->processConfiguration($this->configuration(), [self::REQUIRED + $store, $snippet]);

        foreach (array_keys($snippet) as $key) {
            self::assertArrayHasKey($key, $processed);
        }
    }

    public function testEveryKeyTheBundleTakesIsNamedInAGuide(): void
    {
        $guides = implode("\n", array_map(static fn (string $guide): string => (string) file_get_contents($guide), glob(self::GUIDES.'/*.md') ?: []));
        $keys = array_keys((new Processor())->processConfiguration($this->configuration(), [self::REQUIRED + ['store' => 'store']]));
        self::assertContains('url_elicitation', $keys);

        self::assertSame([], array_values(array_filter($keys, static fn (string $key): bool => !str_contains($guides, $key))), 'Keys no guide names.');
    }

    private function configuration(): ConfigurationInterface
    {
        $extension = (new VerifiedHumanApprovalBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        return $configuration;
    }
}
