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

    public function testSetupPrintsOnlyTheCredentialsForAScript(): void
    {
        [$status, $output] = self::script([\PHP_BINARY, 'demo/bin/setup.php', '--json'], ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $this->directory.'/local', 'VERIFIED_HUMAN_APPROVAL_ORIGIN' => null]);
        self::assertSame(0, $status, $output);
        $created = json_decode($output, true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame('http://localhost:8080', $created['origin']);
        self::assertSame(['manager', 'editor', 'reader', 'other'], array_keys($created['accounts']));
        self::assertSame(['password', 'personal_token', 'passkey_link'], array_keys($created['accounts']['editor']));
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

    public function testServerServesTheAddressTheDemoWasSetUpFor(): void
    {
        $root = $this->serverTree('http://localhost:8090');
        [$status, $output] = self::server($root, 'start');
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Serving http://localhost:8090', $output);
        [$submit] = self::launched($root);
        self::assertStringContainsString('-l dev.verified-human-approval.demo.8090 ', $submit);
        self::assertStringContainsString(' -S localhost:8090 -t '.$root.'/demo/public ', $submit);
        self::assertSame("Serving http://localhost:8090\n", self::server($root, 'status')[1]);

        [$status, $output] = self::server($root, 'stop');
        self::assertSame(0, $status, $output);
        self::assertContains('launchctl remove dev.verified-human-approval.demo.8090', self::calls($root));
        // Only that port's server: php -S localhost:80 must not also stop the one on 8080 or 8081.
        self::assertContains('pkill -f php -S localhost:8090 ', self::calls($root));
        self::assertSame("Not running\n", self::server($root, 'status')[1]);
    }

    public function testServerRefusesToServeADemoOnAPortItDoesNotAnswerOn(): void
    {
        $root = $this->serverTree('http://localhost:8090');
        foreach (['start', 'restart', 'stop', 'status'] as $command) {
            [$status, $output] = self::server($root, $command, ['PORT' => '8080']);
            self::assertSame(1, $status, $command.': '.$output);
            self::assertStringContainsString('This demo was set up for http://localhost:8090, so it is served on port 8090.', $output, $command);
            self::assertStringContainsString('VERIFIED_HUMAN_APPROVAL_ORIGIN=http://localhost:8080 php demo/bin/setup.php --reset', $output, $command);
        }
        self::assertFileDoesNotExist($root.'/calls.log', 'Nothing was started or stopped.');

        // A demo nobody set up has no address to serve.
        $root = $this->serverTree(null);
        [$status, $output] = self::server($root, 'start');
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('No demo is set up in '.$root.'/var/demo. Set one up first: php demo/bin/setup.php', $output);
        self::assertFileDoesNotExist($root.'/calls.log');
        self::assertSame("Not running\n", self::server($root, 'status')[1]);
    }

    public function testServerServesADemoBehindATunnelOnTheLocalPortTheTunnelForwardsTo(): void
    {
        $root = $this->serverTree('https://tunnel-name.trycloudflare.com', 'var/public-demo');
        $runtime = ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $root.'/var/public-demo'];
        [$status, $output] = self::server($root, 'start', $runtime + ['PORT' => '8081']);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Serving http://localhost:8081 for https://tunnel-name.trycloudflare.com', $output);
        [$submit] = self::launched($root);
        self::assertStringContainsString('-l dev.verified-human-approval.demo.8081 ', $submit);
        self::assertStringContainsString(' VERIFIED_HUMAN_APPROVAL_RUNTIME='.$root.'/var/public-demo ', $submit);
        self::assertStringContainsString(' -S localhost:8081 -t ', $submit);
        self::assertSame(0, self::server($root, 'stop', $runtime + ['PORT' => '8081'])[0]);

        [$status, $output] = self::server($root, 'start', $runtime);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString(' -S localhost:8080 -t ', self::launched($root)[1], 'Without PORT, the tunnel is taken to forward to port 8080.');
    }

    public function testPublicStatusAsksAboutThePublicDemosOwnServer(): void
    {
        // A local demo set up for port 8080, beside the public one, whose tunnel forwards to its server on port 8081.
        $root = $this->serverTree('http://localhost:8080');
        $filesystem = new Filesystem();
        $filesystem->copy(\dirname(__DIR__, 2).'/demo/bin/public', $root.'/demo/bin/public');
        $filesystem->chmod($root.'/demo/bin/public', 0755);
        $filesystem->dumpFile($root.'/var/public-demo/settings.json', json_encode(['origin' => 'https://tunnel-name.trycloudflare.com'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        $filesystem->dumpFile($root.'/var/public-tunnel.log', "Your quick Tunnel has been created! Visit it at:\nhttps://tunnel-name.trycloudflare.com\n");
        $filesystem->dumpFile($root.'/listening', "8081\n");

        [$status, $output] = self::script([$root.'/demo/bin/public', 'status'], ['PATH' => $root.'/stand-ins:'.\dirname(\PHP_BINARY).':/usr/bin:/bin', 'PORT' => null, 'VERIFIED_HUMAN_APPROVAL_RUNTIME' => null]);
        self::assertSame(0, $status, $output);
        self::assertSame("Tunnel: https://tunnel-name.trycloudflare.com\nServing http://localhost:8081 for https://tunnel-name.trycloudflare.com\n", $output);
    }

    /** @return array{int, string} */
    private static function publicStart(string $root, string ...$arguments): array
    {
        return self::script([$root.'/demo/bin/public', 'start', ...$arguments], ['PATH' => $root.'/stand-ins:'.\dirname(\PHP_BINARY).':/usr/bin:/bin']);
    }

    /**
     * Runs the copy of demo/bin/server in $root, with no PORT and no runtime named unless $environment names them.
     *
     * @param array<string, string> $environment
     *
     * @return array{int, string}
     */
    private static function server(string $root, string $command, array $environment = []): array
    {
        return self::script([$root.'/demo/bin/server', $command], $environment + [
            'PATH' => $root.'/stand-ins:'.\dirname(\PHP_BINARY).':/usr/bin:/bin', 'PHP_BIN' => \PHP_BINARY,
            'PORT' => null, 'VERIFIED_HUMAN_APPROVAL_RUNTIME' => null,
        ]);
    }

    /**
     * A copy of demo/bin/server in a tree of its own, beside stand-ins for launchd, lsof, pkill and the console that
     * only record what they are asked to do. A job the stand-in launchd is given listens on the port its php -S names,
     * until it is removed or killed, so the script sees the server it started. The runtime at $runtime, set up for
     * $setUpFor, holds only its settings.
     *
     * @return string the tree's root
     */
    private function serverTree(?string $setUpFor, string $runtime = 'var/demo'): string
    {
        $root = $this->directory.'/'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->copy(\dirname(__DIR__, 2).'/demo/bin/server', $root.'/demo/bin/server');
        $filesystem->dumpFile($root.'/demo/bin/console', "<?php\nfile_put_contents('$root/calls.log', 'console '.implode(' ', array_slice(\$argv, 1)).\"\\n\", \\FILE_APPEND);\n");
        $filesystem->dumpFile($root.'/stand-ins/launchctl', <<<SH
            #!/bin/sh
            echo "launchctl \$*" >> '$root/calls.log'
            case "\$1" in
              submit) for argument; do case "\$argument" in localhost:*) echo "\${argument#localhost:}" > '$root/listening' ;; esac; done ;;
              remove) rm -f '$root/listening' ;;
            esac
            SH);
        $filesystem->dumpFile($root.'/stand-ins/lsof', "#!/bin/sh\n[ -f '$root/listening' ] && [ \"\$2\" = \"-iTCP:\$(cat '$root/listening')\" ]\n");
        $filesystem->dumpFile($root.'/stand-ins/pkill', "#!/bin/sh\necho \"pkill \$*\" >> '$root/calls.log'\nrm -f '$root/listening'\n");
        $filesystem->chmod([$root.'/demo/bin/server', $root.'/stand-ins/launchctl', $root.'/stand-ins/lsof', $root.'/stand-ins/pkill'], 0755);
        if (null !== $setUpFor) {
            $filesystem->dumpFile($root.'/'.$runtime.'/settings.json', json_encode(['origin' => $setUpFor], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        }

        return $root;
    }

    /** @return list<string> what the scripts in $root asked the stand-ins to do, in order */
    private static function calls(string $root): array
    {
        return is_file($root.'/calls.log') ? file($root.'/calls.log', \FILE_IGNORE_NEW_LINES) : [];
    }

    /** @return list<string> the jobs the stand-in launchd was given, in order */
    private static function launched(string $root): array
    {
        return array_values(array_filter(self::calls($root), static fn (string $call): bool => str_starts_with($call, 'launchctl submit ')));
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
