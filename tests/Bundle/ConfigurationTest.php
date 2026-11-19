<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;
use VerifiedHumanApproval\Bundle\VerifiedHumanApprovalBundle;

/**
 * The bundle's configuration: what its guides show is taken as it is, every key it takes is in a guide, and a
 * configuration that could never work is refused as the container is built.
 */
final class ConfigurationTest extends TestCase
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

    /** @return iterable<string, array{array<string, mixed>, bool, list<string>}> */
    public static function urlPrompts(): iterable
    {
        yield 'left out: off' => [[], false, []];
        yield 'clients listed: on for them' => [['url_elicitation' => ['clients' => ['claude-code']]], true, ['claude-code']];
        yield 'turned off, the list kept' => [['url_elicitation' => ['enabled' => false, 'clients' => ['claude-code']]], false, ['claude-code']];
        yield 'on for every client that declares URL mode' => [['url_elicitation' => ['enabled' => true, 'clients' => ['*']]], true, ['*']];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $clients
     */
    #[DataProvider('urlPrompts')]
    public function testListingClientsTurnsTheUrlPromptOn(array $config, bool $enabled, array $clients): void
    {
        $prompt = (new Processor())->processConfiguration($this->configuration(), [self::REQUIRED + ['store' => 'store'], $config])['url_elicitation'];

        self::assertSame([$enabled, $clients], [$prompt['enabled'], $prompt['clients']]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function configurationsThatCouldNeverWork(): iterable
    {
        yield 'a public page with no receipt to show' => [['receipt_signer' => null, 'public_verification' => true], 'public_verification shows the receipt signed as the change committed: configure receipt_signer too.'];
        foreach (['enabled: true' => ['enabled' => true], 'url_elicitation: true' => true, 'an empty clients list' => ['clients' => []]] as $how => $prompt) {
            yield 'a URL prompt for no client, '.$how => [['url_elicitation' => $prompt], 'url_elicitation is enabled for no client: list the clientInfo names to prompt under "clients", or "*" for every client that declares URL mode.'];
        }
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('configurationsThatCouldNeverWork')]
    public function testAConfigurationThatCouldNeverWorkIsRefused(array $config, string $refusal): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($refusal);

        (new Processor())->processConfiguration($this->configuration(), [self::REQUIRED + ['store' => 'store'], $config]);
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
