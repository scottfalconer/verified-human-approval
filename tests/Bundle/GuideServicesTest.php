<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The service definitions the bundle's guides ask readers to copy name only arguments that exist: the container
 * refuses to compile a named argument that the constructor, or the factory, does not take.
 */
final class GuideServicesTest extends TestCase
{
    /** @return iterable<string, array{class-string, string, list<string>}> */
    public static function namedArguments(): iterable
    {
        foreach (glob(\dirname(__DIR__, 2).'/src/symfony-bundle/docs/*.md') ?: [] as $guide) {
            preg_match_all('/^```yaml\n(.*?)^```/ms', (string) file_get_contents($guide), $blocks);
            foreach ($blocks[1] as $block) {
                foreach ((array) (Yaml::parse($block)['services'] ?? []) as $id => $service) {
                    $service = \is_array($service) ? $service : [];
                    $named = array_values(array_filter(array_map('strval', array_keys((array) ($service['arguments'] ?? []))), static fn (string $name): bool => str_starts_with($name, '$')));
                    [$class, $method] = \is_array($service['factory'] ?? null) ? $service['factory'] : [$service['class'] ?? $id, '__construct'];
                    // The reader's own classes are written in the guide, not here.
                    if ([] !== $named && class_exists($class)) {
                        yield basename($guide).': '.$id => [$class, $method, $named];
                    }
                }
            }
        }
    }

    /**
     * @param class-string $class
     * @param list<string> $named
     */
    #[DataProvider('namedArguments')]
    public function testEveryNamedArgumentIsAParameter(string $class, string $method, array $named): void
    {
        $parameters = array_map(static fn (\ReflectionParameter $parameter): string => '$'.$parameter->getName(), (new \ReflectionMethod($class, $method))->getParameters());
        self::assertSame([], array_values(array_diff($named, $parameters)), $class.'::'.$method.'() takes '.implode(', ', $parameters).'.');
    }
}
