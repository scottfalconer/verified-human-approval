<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** The demo's own scripts, run as a person runs them, each in a directory of its own. */
final class DemoScriptsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/verified-human-approval-scripts-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testSetupLeavesOpenClientRegistrationOnUnlessItIsTurnedOff(): void
    {
        foreach (['not set' => [null, true], 'set to 0' => ['0', false], 'set to off' => ['off', false], 'set to 1' => ['1', true]] as $case => [$value, $open]) {
            $runtime = $this->directory.'/'.bin2hex(random_bytes(4));
            [$status, $output] = self::script([\PHP_BINARY, 'demo/bin/setup.php'], ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $runtime, 'VERIFIED_HUMAN_APPROVAL_ORIGIN' => null, 'VERIFIED_HUMAN_APPROVAL_DYNAMIC_CLIENT_REGISTRATION' => $value]);
            self::assertSame(0, $status, $output);
            self::assertSame($open, self::settings($runtime)['dynamic_client_registration'], $case);
        }
    }

    /** @return array<string, mixed> */
    private static function settings(string $runtime): array
    {
        return json_decode((string) file_get_contents($runtime.'/settings.json'), true, 32, \JSON_THROW_ON_ERROR);
    }

    /**
     * Runs a command from the repository root with no terminal on its input, and these environment variables
     * changed (null removes one).
     *
     * @param list<string>               $command
     * @param array<string, string|null> $environment
     *
     * @return array{int, string} the exit status, and what it printed on both outputs
     */
    private static function script(array $command, array $environment): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, \dirname(__DIR__, 2), array_filter($environment + getenv(), \is_string(...)));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}
