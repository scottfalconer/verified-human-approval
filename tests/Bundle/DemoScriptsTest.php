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

    public function testSetupOpensClientRegistrationOnlyOnThisComputerUnlessTold(): void
    {
        foreach ([
            'not set' => [null, null, true], 'set to 0' => [null, '0', false], 'set to off' => [null, 'off', false], 'set to 1' => [null, '1', true],
            'not set, at a public address' => ['https://alerts.example', null, false],
        ] as $case => [$origin, $value, $open]) {
            $runtime = $this->directory.'/'.bin2hex(random_bytes(4));
            [$status, $output] = self::script([\PHP_BINARY, 'demo/bin/setup.php'], ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $runtime, 'VERIFIED_HUMAN_APPROVAL_ORIGIN' => $origin, 'VERIFIED_HUMAN_APPROVAL_DYNAMIC_CLIENT_REGISTRATION' => $value]);
            self::assertSame(0, $status, $output);
            self::assertSame($open, self::settings($runtime)['dynamic_client_registration'], $case);
        }
    }

    public function testSetupPrintsHowToServeTheAddressItWasGiven(): void
    {
        $runtime = $this->directory.'/local';
        $setup = [\PHP_BINARY, 'demo/bin/setup.php'];
        [$status, $output] = self::script($setup, ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $runtime, 'VERIFIED_HUMAN_APPROVAL_ORIGIN' => 'http://localhost:8090']);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString("Start the site:\n  VERIFIED_HUMAN_APPROVAL_RUNTIME=".escapeshellarg($runtime).' PHP_CLI_SERVER_WORKERS=4 php -S localhost:8090 -t demo/public demo/public/index.php', $output);
        self::assertStringContainsString('export VERIFIED_HUMAN_APPROVAL_MCP_URL=http://localhost:8090/mcp', $output);
        self::assertStringNotContainsString('localhost:8080', $output);

        [$status, $output] = self::script($setup, ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $runtime, 'VERIFIED_HUMAN_APPROVAL_ORIGIN' => 'http://localhost:8090']);
        self::assertSame(1, $status);
        self::assertStringContainsString('This demo is already initialized; existing accounts were not replaced. To replace it with a fresh demo, run setup again with --reset.', $output);

        // The public kind is served through a tunnel to a local server, so there is no server command to copy.
        [$status, $output] = self::script($setup, ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $this->directory.'/public', 'VERIFIED_HUMAN_APPROVAL_ORIGIN' => 'https://alerts.example']);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Serve the site at https://alerts.example through an HTTPS proxy or tunnel to this computer', $output);
        self::assertStringNotContainsString('php -S', $output);
    }

    public function testPublicStartAsksBeforeReplacingADemoSetUpForAnotherAddress(): void
    {
        $root = $this->publicDemo('https://old-tunnel-name.trycloudflare.com', 'https://new-tunnel-name.trycloudflare.com');
        [$status, $output] = self::publicStart($root);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('The public demo was set up for https://old-tunnel-name.trycloudflare.com, and the tunnel now answers at https://new-tunnel-name.trycloudflare.com.', $output);
        self::assertStringContainsString('its accounts, passkeys, connections, records and signing key', $output);
        self::assertStringContainsString('Nothing was replaced.', $output, 'With nobody at a terminal to answer, it replaces nothing.');
        self::assertFileDoesNotExist($root.'/calls.log', 'Nothing was stopped, set up or started.');

        [$status, $output] = self::publicStart($root, '--yes');
        self::assertSame(0, $status, $output);
        self::assertSame(['server stop', 'setup --reset for https://new-tunnel-name.trycloudflare.com, registration 0', 'server start'], file($root.'/calls.log', \FILE_IGNORE_NEW_LINES));
    }

    public function testPublicStartKeepsADemoSetUpForTheTunnelAndSetsUpAMissingOneWithoutAsking(): void
    {
        $same = $this->publicDemo('https://tunnel-name.trycloudflare.com', 'https://tunnel-name.trycloudflare.com');
        [$status, $output] = self::publicStart($same);
        self::assertSame(0, $status, $output);
        self::assertSame(['server start'], file($same.'/calls.log', \FILE_IGNORE_NEW_LINES));

        $none = $this->publicDemo(null, 'https://tunnel-name.trycloudflare.com');
        [$status, $output] = self::publicStart($none);
        self::assertSame(0, $status, $output);
        self::assertSame(['server stop', 'setup --reset for https://tunnel-name.trycloudflare.com, registration 0', 'server start'], file($none.'/calls.log', \FILE_IGNORE_NEW_LINES));
    }

    /** @return array{int, string} */
    private static function publicStart(string $root, string ...$arguments): array
    {
        return self::script([$root.'/demo/bin/public', 'start', ...$arguments], ['PATH' => $root.'/stand-ins:'.\dirname(\PHP_BINARY).':/usr/bin:/bin']);
    }

    /**
     * A copy of demo/bin/public in a tree of its own, beside stand-ins for launchd, the server script and setup
     * that only record what they are asked to do, with a tunnel already answering at $tunnel.
     *
     * @return string the tree's root
     */
    private function publicDemo(?string $setUpFor, string $tunnel): string
    {
        $root = $this->directory.'/'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->copy(\dirname(__DIR__, 2).'/demo/bin/public', $root.'/demo/bin/public');
        $filesystem->chmod($root.'/demo/bin/public', 0755);
        $filesystem->dumpFile($root.'/demo/bin/server', "#!/bin/sh\necho \"server \$1\" >> '$root/calls.log'\n");
        $filesystem->dumpFile($root.'/demo/bin/setup.php', "<?php\nfile_put_contents('$root/calls.log', 'setup '.\$argv[1].' for '.getenv('VERIFIED_HUMAN_APPROVAL_ORIGIN').', registration '.getenv('VERIFIED_HUMAN_APPROVAL_DYNAMIC_CLIENT_REGISTRATION').\"\\n\", \\FILE_APPEND);\n");
        $filesystem->dumpFile($root.'/stand-ins/launchctl', "#!/bin/sh\nexit 0\n");
        $filesystem->chmod([$root.'/demo/bin/server', $root.'/stand-ins/launchctl'], 0755);
        $filesystem->dumpFile($root.'/var/public-tunnel.log', "Your quick Tunnel has been created! Visit it at:\n$tunnel\n");
        if (null !== $setUpFor) {
            $filesystem->dumpFile($root.'/var/public-demo/settings.json', json_encode(['origin' => $setUpFor], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        }

        return $root;
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
