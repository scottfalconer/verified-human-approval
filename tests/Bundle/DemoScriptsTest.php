<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The demo's own scripts, run as a person runs them, each in a directory of its own. demo/bin/server and
 * demo/bin/public run on a stand-in for this computer, so no server, tunnel or launchd job is ever started.
 */
final class DemoScriptsTest extends TestCase
{
    private const TUNNEL = 'dev.verified-human-approval.tunnel';

    /**
     * The commands the scripts start, stop and look at servers with, over the world {@see self::computer()} describes.
     */
    private const STAND_INS = [
        'launchctl' => <<<'SH'
            #!/bin/sh
            # launchctl list, remove and submit, over the world's jobs and process table.
            computer=$(cd "$(dirname "$0")/.." && pwd)
            world=$computer/world
            case "$1" in
              list)
                [ -f "$world/jobs/$2" ] || { echo "Could not find service \"$2\" in domain for port" >&2; exit 113; }
                cat "$world/jobs/$2"
                ;;
              remove)
                echo "launchctl $*" >> "$computer/calls.log"
                [ -f "$world/jobs/$2" ] || exit 3
                rm "$world/jobs/$2"
                awk -v job="$2" '$3 != job' "$world/processes" > "$world/processes.next"
                mv "$world/processes.next" "$world/processes"
                ;;
              submit)
                echo "launchctl $*" >> "$computer/calls.log"
                shift
                while [ "$1" != -- ]; do
                  case "$1" in -l) label=$2 ;; -o) log=$2 ;; esac
                  shift 2
                done
                shift
                [ ! -f "$world/jobs/$label" ] || { echo "Job $label already exists" >&2; exit 1; }
                pid=$(($(cat "$world/last-pid") + 1))
                {
                  printf '{\n\t"StandardOutPath" = "%s";\n\t"LimitLoadToSessionType" = "Aqua";\n\t"StandardErrorPath" = "%s";\n' "$log" "$log"
                  printf '\t"Label" = "%s";\n\t"OnDemand" = false;\n\t"LastExitStatus" = 0;\n\t"PID" = %s;\n\t"Program" = "%s";\n' "$label" "$pid" "$1"
                  printf '\t"ProgramArguments" = (\n'
                  printf '\t\t"%s";\n' "$@"
                  printf '\t);\n};\n'
                } > "$world/jobs/$label"
                # The program starts once env has applied its settings, and php -S forks the workers they ask for.
                workers=0
                if [ "$1" = /usr/bin/env ]; then
                  shift
                  while :; do
                    case "$1" in
                      PHP_CLI_SERVER_WORKERS=*) workers=${1#*=}; shift ;;
                      *=*) shift ;;
                      *) break ;;
                    esac
                  done
                fi
                port=-
                previous=
                for argument; do
                  [ "$previous" != -S ] || port=${argument#localhost:}
                  previous=$argument
                done
                echo "$pid $port $label $*" >> "$world/processes"
                while [ "$workers" -gt 0 ]; do
                  pid=$((pid + 1))
                  echo "$pid $port - $*" >> "$world/processes"
                  workers=$((workers - 1))
                done
                echo "$pid" > "$world/last-pid"
                case "$1" in
                  */cloudflared)
                    cat "$world/tunnel-address" >> "$log"
                    cat "$world/meanwhile" >> "$world/processes"
                    ;;
                esac
                ;;
            esac
            SH,
        'lsof' => <<<'SH'
            #!/bin/sh
            # lsof -nP -iTCP:<port> -sTCP:LISTEN -t, over the process table: the processes listening on the port.
            world=$(cd "$(dirname "$0")/../world" && pwd)
            for argument; do
              case "$argument" in -iTCP:*) wanted=${argument#-iTCP:} ;; esac
            done
            found=1
            while read -r pid port job command; do
              if [ "$port" = "$wanted" ]; then echo "$pid"; found=0; fi
            done < "$world/processes"
            exit $found
            SH,
        'ps' => <<<'SH'
            #!/bin/sh
            # ps -axww -o pid= -o command=, or ps -ww -o command= -p <pid>, over the process table.
            world=$(cd "$(dirname "$0")/../world" && pwd)
            wanted=
            previous=
            for argument; do
              [ "$previous" != -p ] || wanted=$argument
              previous=$argument
            done
            while read -r pid port job command; do
              if [ -z "$wanted" ]; then
                echo "$pid $command"
              elif [ "$pid" = "$wanted" ]; then
                echo "$command"
                exit 0
              fi
            done < "$world/processes"
            [ -z "$wanted" ]
            SH,
        'nc' => <<<'SH'
            #!/bin/sh
            # nc -z localhost <port>: whether anything listens on the port, whether lsof can see it or not.
            world=$(cd "$(dirname "$0")/../world" && pwd)
            for argument; do wanted=$argument; done
            while read -r pid port job command; do
              [ "$port" != "$wanted" ] || exit 0
            done < "$world/processes"
            grep -qx "$wanted" "$world/hidden"
            SH,
        'kill' => <<<'SH'
            #!/bin/sh
            # kill, over the process table: each process it is given stops.
            computer=$(cd "$(dirname "$0")/.." && pwd)
            echo "kill $*" >> "$computer/calls.log"
            for wanted; do
              awk -v pid="$wanted" '$1 != pid' "$computer/world/processes" > "$computer/world/processes.next"
              mv "$computer/world/processes.next" "$computer/world/processes"
            done
            SH,
        // A pattern can match any checkout's processes, so these only record that they were asked.
        'pkill' => <<<'SH'
            #!/bin/sh
            echo "pkill $*" >> "$(cd "$(dirname "$0")/.." && pwd)/calls.log"
            exit 1
            SH,
        'pgrep' => <<<'SH'
            #!/bin/sh
            echo "pgrep $*" >> "$(cd "$(dirname "$0")/.." && pwd)/calls.log"
            exit 1
            SH,
        'cloudflared' => <<<'SH'
            #!/bin/sh
            echo 'This stand-in only names the program a tunnel job runs.' >&2
            exit 1
            SH,
    ];

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
        $checkout = $this->publicTree('https://old-tunnel-name.trycloudflare.com', 'https://new-tunnel-name.trycloudflare.com');
        [$status, $output] = $this->publicDemo($checkout, 'start');
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('The public demo was set up for https://old-tunnel-name.trycloudflare.com, and the tunnel now answers at https://new-tunnel-name.trycloudflare.com.', $output);
        self::assertStringContainsString('its accounts, passkeys, connections, records and signing key', $output);
        self::assertStringContainsString('Nothing was replaced.', $output, 'With nobody at a terminal to answer, it replaces nothing.');
        self::assertSame(['server status'], $this->calls(), 'Nothing was stopped, set up or started.');

        $this->forgetCalls();
        [$status, $output] = $this->publicDemo($checkout, 'start', '--yes');
        self::assertSame(0, $status, $output);
        self::assertSame(['server status', 'server stop', 'setup --reset for https://new-tunnel-name.trycloudflare.com, registration 0', 'server start'], $this->calls());
    }

    public function testPublicStartKeepsADemoSetUpForTheTunnel(): void
    {
        $checkout = $this->publicTree('https://tunnel-name.trycloudflare.com', 'https://tunnel-name.trycloudflare.com');
        [$status, $output] = $this->publicDemo($checkout, 'start');
        self::assertSame(0, $status, $output);
        self::assertSame(['server status', 'server start'], $this->calls());
    }

    public function testPublicStartSetsUpAMissingDemoWithoutAsking(): void
    {
        $checkout = $this->publicTree(null, 'https://tunnel-name.trycloudflare.com');
        [$status, $output] = $this->publicDemo($checkout, 'start');
        self::assertSame(0, $status, $output);
        self::assertSame(['server status', 'server stop', 'setup --reset for https://tunnel-name.trycloudflare.com, registration 0', 'server start'], $this->calls());
    }

    public function testServerServesTheAddressTheDemoWasSetUpFor(): void
    {
        $checkout = $this->checkout('http://localhost:8090');
        [$status, $output] = $this->server($checkout, 'start');
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Serving http://localhost:8090', $output);
        [$submit] = $this->launched();
        self::assertStringContainsString('-l dev.verified-human-approval.demo.8090 ', $submit);
        self::assertStringContainsString(' -S localhost:8090 -t '.$checkout.'/demo/public ', $submit);
        self::assertSame([0, "Serving http://localhost:8090\n"], $this->server($checkout, 'status'));
        self::assertSame([0, "Already serving http://localhost:8090\n"], $this->server($checkout, 'start'));
        self::assertCount(1, $this->launched(), 'Its own server is not started twice.');

        // Removing the job leaves its workers behind, here, and stop stops them too.
        [$job, $workers] = [array_key_first($this->processes()), \array_slice(array_keys($this->processes()), 1)];
        self::assertCount(4, $workers);
        $this->forgetCalls();
        self::assertSame([0, "Stopped\n"], $this->server($checkout, 'stop'));
        self::assertSame(['launchctl remove dev.verified-human-approval.demo.8090', 'kill '.implode(' ', $workers)], $this->calls());
        self::assertSame([], $this->processes(), "The job's process $job stopped with it.");
        self::assertSame([3, "Not running\n"], $this->server($checkout, 'status'));

        // An origin without a port is served on port 80. Stopping it leaves this checkout's servers on 8080 and 8081 alone.
        $local = $this->runtime($checkout, 'var/local', 'http://localhost:8080');
        $tunnel = $this->runtime($checkout, 'var/public-demo', 'https://tunnel-name.trycloudflare.com');
        self::assertSame(0, $this->server($checkout, 'start', ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $local])[0]);
        self::assertSame(0, $this->server($checkout, 'start', ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $tunnel, 'PORT' => '8081'])[0]);
        $running = $this->processes();
        $this->forgetCalls();
        $port80 = $this->runtime($checkout, 'var/port-80', 'http://localhost');
        self::assertSame([0, "Stopped\n"], $this->server($checkout, 'stop', ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $port80]));
        self::assertSame([], $this->calls());
        self::assertSame($running, $this->processes());
    }

    public function testServerReplacesAJobOfItsOwnThatServesNothing(): void
    {
        $checkout = $this->checkout('http://localhost:8090');
        self::assertSame(0, $this->server($checkout, 'start')[0]);
        // Its processes are gone, and the job is still there, as for a server that keeps failing.
        $this->stopEveryProcess();
        $this->forgetCalls();
        self::assertSame([3, "Not running\n"], $this->server($checkout, 'status'));
        [$status, $output] = $this->server($checkout, 'start');
        self::assertSame(0, $status, $output);
        self::assertSame('launchctl remove dev.verified-human-approval.demo.8090', $this->calls()[0]);
        self::assertCount(1, $this->launched());
    }

    public function testServerRefusesToServeADemoOnAPortItDoesNotAnswerOn(): void
    {
        $checkout = $this->checkout('http://localhost:8090');
        foreach (['start', 'restart', 'stop', 'status'] as $command) {
            [$status, $output] = $this->server($checkout, $command, ['PORT' => '8080']);
            self::assertSame(1, $status, $command.': '.$output);
            self::assertStringContainsString('This demo was set up for http://localhost:8090, so it is served on port 8090.', $output, $command);
            self::assertStringContainsString('VERIFIED_HUMAN_APPROVAL_ORIGIN=http://localhost:8080 php demo/bin/setup.php --reset', $output, $command);
        }
        self::assertSame([], $this->calls(), 'Nothing was started or stopped.');

        // A demo nobody set up has no address to serve.
        $checkout = $this->checkout(null, name: 'not-set-up');
        [$status, $output] = $this->server($checkout, 'start');
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('No demo is set up in '.$checkout.'/var/demo. Set one up first: php demo/bin/setup.php', $output);
        self::assertSame([], $this->calls());
        self::assertSame([3, "Not running\n"], $this->server($checkout, 'status'));

        // The port goes into the patterns that find this checkout's processes, so it must be a number.
        self::assertSame([2, "PORT must be a port number, not \"80*\".\n"], $this->server($checkout, 'stop', ['PORT' => '80*']));
        self::assertSame([], $this->calls());
    }

    public function testServerServesADemoBehindATunnelOnTheLocalPortTheTunnelForwardsTo(): void
    {
        $checkout = $this->checkout('https://tunnel-name.trycloudflare.com', 'var/public-demo');
        $runtime = ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $checkout.'/var/public-demo'];
        [$status, $output] = $this->server($checkout, 'start', $runtime + ['PORT' => '8081']);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Serving http://localhost:8081 for https://tunnel-name.trycloudflare.com', $output);
        [$submit] = $this->launched();
        self::assertStringContainsString('-l dev.verified-human-approval.demo.8081 ', $submit);
        self::assertStringContainsString(' VERIFIED_HUMAN_APPROVAL_RUNTIME='.$checkout.'/var/public-demo ', $submit);
        self::assertStringContainsString(' -S localhost:8081 -t ', $submit);
        self::assertSame(0, $this->server($checkout, 'stop', $runtime + ['PORT' => '8081'])[0]);

        [$status, $output] = $this->server($checkout, 'start', $runtime);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString(' -S localhost:8080 -t ', $this->launched()[1], 'Without PORT, the tunnel is taken to forward to port 8080.');

        // This checkout's local demo, set up for that port, is not taken for the one served there.
        $this->runtime($checkout, 'var/demo', 'http://localhost:8080');
        $other = "Port 8080 is in use by this checkout's server for another demo, in $checkout/var/public-demo.";
        self::assertSame([1, "Not running. $other\n"], $this->server($checkout, 'status'));
        self::assertSame([1, "$other Stop it first: $checkout/demo/bin/server stop\n"], $this->server($checkout, 'start'));
        self::assertCount(2, $this->launched());
    }

    public function testServerTellsItsOwnServerFromAnotherProgramOnThePort(): void
    {
        $checkout = $this->checkout('http://localhost:8080');
        $program = $this->listen(8080, '/usr/bin/python3 -m http.server 8080');
        $taken = "Port 8080 is in use by another program: PID $program (/usr/bin/python3 -m http.server 8080).";
        self::assertSame([1, "$taken\nStop that program, or set this demo up for another port, which replaces its accounts and passkeys:\n  VERIFIED_HUMAN_APPROVAL_ORIGIN=http://localhost:8090 php demo/bin/setup.php --reset\n"], $this->server($checkout, 'start'));
        self::assertSame([1, "Not running. $taken\n"], $this->server($checkout, 'status'));
        self::assertSame([0, "Stopped\n$taken This script did not start it, so it is left running.\n"], $this->server($checkout, 'stop'));
        self::assertSame([], $this->calls(), 'Nothing was started, and nothing of the program’s was stopped.');
        self::assertArrayHasKey($program, $this->processes());

        // Behind a proxy or tunnel, another port is one the proxy forwards to. The command to copy names the runtime.
        $behind = $this->runtime($checkout, 'var/behind-a-proxy', 'https://alerts.example');
        [$status, $output] = $this->server($checkout, 'start', ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $behind]);
        self::assertSame([1, "$taken\nStop that program, or serve this demo on another port, one the proxy or tunnel forwards to: VERIFIED_HUMAN_APPROVAL_RUNTIME='$behind' PORT=8090 $checkout/demo/bin/server start\n"], [$status, $output]);

        // A listener this account cannot inspect, such as another account's, is never taken for this demo.
        $this->runtime($checkout, 'var/demo', 'http://localhost:8070');
        file_put_contents($this->world().'/hidden', "8070\n");
        [$status, $output] = $this->server($checkout, 'start');
        self::assertSame(1, $status, $output);
        self::assertStringStartsWith("Port 8070 is in use by a program this account cannot inspect.\nStop that program, or set this demo up for another port", $output);
        self::assertSame([1, "Not running. Port 8070 is in use by a program this account cannot inspect.\n"], $this->server($checkout, 'status'));
        self::assertSame([], $this->calls());
    }

    public function testServerLeavesAnotherCheckoutsServerAlone(): void
    {
        // Two checkouts on one computer, each set up for port 8080, so both name the same launchd label.
        $first = $this->checkout('http://localhost:8080', name: 'first');
        $second = $this->checkout('http://localhost:8080', name: 'second');
        self::assertSame(0, $this->server($first, 'start')[0]);
        $running = $this->processes();
        $theirs = 'Port 8080 is in use by another program: PID '.array_key_first($running).' ('.\PHP_BINARY." -S localhost:8080 -t $first/demo/public $first/demo/public/index.php).";
        $this->forgetCalls();

        self::assertSame([1, "Not running. $theirs\n"], $this->server($second, 'status'));
        [$status, $output] = $this->server($second, 'start');
        self::assertSame(1, $status, $output);
        self::assertStringStartsWith("$theirs\nStop that program", $output);
        self::assertSame([1, "The launchd job dev.verified-human-approval.demo.8080 was started from another checkout, $first, and is left alone: stop it from there.\n"], $this->server($second, 'stop'));
        self::assertSame([], $this->calls(), 'The second checkout submitted, removed and killed nothing.');
        self::assertSame($running, $this->processes());
        self::assertSame([0, "Serving http://localhost:8080\n"], $this->server($first, 'status'));

        // A job that serves nothing, as one that keeps failing, is still the first checkout's.
        $this->stopEveryProcess();
        self::assertSame([1, "The launchd job dev.verified-human-approval.demo.8080 was started from another checkout, $first, and is left alone: stop it from there.\n"], $this->server($second, 'start'));
        self::assertSame([], $this->calls());

        // A job this script did not start, from any checkout, is left alone too.
        self::assertSame([0, "Stopped\n"], $this->server($first, 'stop'));
        $this->launchd('submit', '-l', 'dev.verified-human-approval.demo.8080', '-o', '/var/log/something-else.log', '-e', '/var/log/something-else.log', '--', '/usr/local/bin/something-else');
        $this->forgetCalls();
        self::assertSame([1, "The launchd job dev.verified-human-approval.demo.8080 was not started from this checkout, and is left alone. Stop it where it was started, or remove it: launchctl remove dev.verified-human-approval.demo.8080\n"], $this->server($second, 'stop'));
        self::assertSame([], $this->calls());
    }

    public function testPublicStatusAsksAboutThePublicDemosOwnServer(): void
    {
        // A local demo set up for port 8080, beside the public one, whose tunnel forwards to its server on port 8081.
        $checkout = $this->checkout('http://localhost:8080');
        $this->tunnel($checkout, 'https://tunnel-name.trycloudflare.com');
        $public = $this->runtime($checkout, 'var/public-demo', 'https://tunnel-name.trycloudflare.com');
        self::assertSame(0, $this->server($checkout, 'start', ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $public, 'PORT' => '8081'])[0]);

        [$status, $output] = $this->publicDemo($checkout, 'status');
        self::assertSame(0, $status, $output);
        self::assertSame("Tunnel: https://tunnel-name.trycloudflare.com\nServing http://localhost:8081 for https://tunnel-name.trycloudflare.com\n", $output);
    }

    public function testPublicStartPublishesNothingButThisCheckoutsPublicDemo(): void
    {
        $checkout = $this->checkout();
        $this->tunnelAddress('https://tunnel-name.trycloudflare.com');
        $program = $this->listen(8081, '/usr/bin/python3 -m http.server 8081');
        $refused = "Not running. Port 8081 is in use by another program: PID $program (/usr/bin/python3 -m http.server 8081).\n";
        $uses = 'The public demo uses port 8081, which nothing else may use while it runs.';
        self::assertSame([1, $refused."$uses Free the port, then run: $checkout/demo/bin/public start\n"], $this->publicDemo($checkout, 'start'));
        self::assertSame([], $this->calls(), 'No tunnel was started, so nothing was published.');

        // This checkout's tunnel, already running, would publish the program, so it stops.
        $this->tunnel($checkout, 'https://tunnel-name.trycloudflare.com');
        self::assertSame([1, $refused."Stopped this checkout's tunnel, so that it publishes nothing else.\n$uses Free the port, then run: $checkout/demo/bin/public start\n"], $this->publicDemo($checkout, 'start'));
        self::assertSame(['launchctl remove '.self::TUNNEL], $this->calls());

        // Nor does this checkout's local demo, set up for that port, pass for the public one.
        $this->stopEveryProcess();
        $this->runtime($checkout, 'var/demo', 'http://localhost:8081');
        self::assertSame(0, $this->server($checkout, 'start')[0]);
        $this->forgetCalls();
        [$status, $output] = $this->publicDemo($checkout, 'start');
        self::assertSame(1, $status, $output);
        self::assertStringStartsWith("Not running. Port 8081 is in use by this checkout's server for another demo, in $checkout/var/demo.\n$uses", $output);
        self::assertSame([], $this->calls());

        // With the port free, the tunnel starts, then the public demo's own server on port 8081.
        self::assertSame(0, $this->server($checkout, 'stop')[0]);
        $this->forgetCalls();
        [$status, $output] = $this->publicDemo($checkout, 'start');
        self::assertSame(0, $status, $output);
        self::assertStringEndsWith("Public demo: https://tunnel-name.trycloudflare.com\nclaude.ai connector URL: https://tunnel-name.trycloudflare.com/mcp\n$uses\n", $output);
        [$tunnel, $server] = $this->launched();
        self::assertStringContainsString('-l '.self::TUNNEL.' ', $tunnel);
        self::assertStringContainsString('-l dev.verified-human-approval.demo.8081 ', $server);
        self::assertStringContainsString(' VERIFIED_HUMAN_APPROVAL_RUNTIME='.$checkout.'/var/public-demo ', $server);
    }

    public function testPublicStartStopsItsTunnelWhenAnotherProgramTakesThePortMeanwhile(): void
    {
        $checkout = $this->checkout();
        $this->tunnelAddress('https://tunnel-name.trycloudflare.com');
        $program = $this->meanwhile(8081, '/usr/bin/python3 -m http.server 8081');
        [$status, $output] = $this->publicDemo($checkout, 'start');
        self::assertSame(1, $status, $output);
        self::assertStringEndsWith("Not running. Port 8081 is in use by another program: PID $program (/usr/bin/python3 -m http.server 8081).\nStopped this checkout's tunnel, so that it publishes nothing else.\nThe public demo uses port 8081, which nothing else may use while it runs. Free the port, then run: $checkout/demo/bin/public start\n", $output);
        self::assertCount(1, $this->launched(), 'Only the tunnel was started, never a server on the taken port.');
        self::assertSame('launchctl remove '.self::TUNNEL, \array_slice($this->calls(), -1)[0]);
        self::assertSame([$program], array_keys($this->processes()), 'The tunnel stopped; the program was left running.');
    }

    public function testPublicStartAndStopLeaveAnotherCheckoutsPublicDemoAlone(): void
    {
        // Two checkouts on one computer: one tunnel label and one port 8081 for both.
        $first = $this->checkout(name: 'first');
        $second = $this->checkout(name: 'second');
        $this->tunnelAddress('https://first-tunnel.trycloudflare.com');
        [$status, $output] = $this->publicDemo($first, 'start');
        self::assertSame(0, $status, $output);
        $running = $this->processes();
        $server = (int) array_search(\PHP_BINARY." -S localhost:8081 -t $first/demo/public $first/demo/public/index.php", $running, true);
        $theirs = "Not running. Port 8081 is in use by another program: PID $server (".\PHP_BINARY." -S localhost:8081 -t $first/demo/public $first/demo/public/index.php).";
        $this->forgetCalls();

        [$status, $output] = $this->publicDemo($second, 'start');
        self::assertSame(1, $status, $output);
        self::assertStringStartsWith("$theirs\nThe public demo uses port 8081, which nothing else may use while it runs.", $output);
        self::assertSame([1, "Tunnel: started from another checkout, $first\n$theirs\n"], $this->publicDemo($second, 'status'));
        self::assertSame([1, 'The tunnel job '.self::TUNNEL." was started from another checkout, $first, and is left running.\nThe launchd job dev.verified-human-approval.demo.8081 was started from another checkout, $first, and is left alone: stop it from there.\n"], $this->publicDemo($second, 'stop'));
        self::assertSame([], $this->calls(), 'The second checkout submitted, removed and killed nothing.');
        self::assertSame($running, $this->processes());

        // With only the first checkout's tunnel left, the second still does not take it over.
        self::assertSame(0, $this->server($first, 'stop', ['VERIFIED_HUMAN_APPROVAL_RUNTIME' => $first.'/var/public-demo', 'PORT' => '8081'])[0]);
        $this->forgetCalls();
        self::assertSame([1, 'The tunnel job '.self::TUNNEL." was started from another checkout, $first. Only one public demo runs on this computer at a time: stop that one first, with $first/demo/bin/public stop.\n"], $this->publicDemo($second, 'start'));
        self::assertSame([], $this->calls());

        // The first checkout stops its own.
        self::assertSame([0, "Stopped\n"], $this->publicDemo($first, 'stop'));
        self::assertSame(['launchctl remove '.self::TUNNEL], $this->calls());
        self::assertSame([], $this->processes());
    }

    /**
     * This computer as demo/bin/server and demo/bin/public see it, with nothing real started: stand-ins for launchd
     * and for the commands the scripts look at processes and ports with, over a world they share. Each launchd job
     * is a file in world/jobs holding what launchctl list prints for it. world/processes is the process table, one
     * line per process: its ID, the port it listens on or "-", the label of the job it is the main process of or
     * "-", and its command line. The IDs are higher than any system hands out, so a signal sent to one by mistake
     * reaches nothing. Removing a job stops its main process only: the workers it forked are left behind, as they
     * can be. A port in world/hidden has a listener lsof cannot see, such as another account's. A tunnel that starts
     * writes world/tunnel-address to its log, and the processes in world/meanwhile start with it. What the scripts
     * change (a job submitted or removed, a process killed, a console command, a setup) is recorded in calls.log.
     *
     * @return string the directory of the stand-ins, to put first in PATH
     */
    private function computer(): string
    {
        $computer = $this->directory.'/computer';
        if (!is_dir($computer)) {
            $filesystem = new Filesystem();
            $filesystem->mkdir($computer.'/world/jobs');
            foreach (['processes', 'hidden', 'tunnel-address', 'meanwhile'] as $file) {
                $filesystem->dumpFile($computer.'/world/'.$file, '');
            }
            $filesystem->dumpFile($computer.'/world/last-pid', "4999999\n");
            foreach (self::STAND_INS as $name => $script) {
                $filesystem->dumpFile($computer.'/stand-ins/'.$name, $script."\n");
                $filesystem->chmod($computer.'/stand-ins/'.$name, 0755);
            }
        }

        return $computer.'/stand-ins';
    }

    private function world(): string
    {
        return \dirname($this->computer()).'/world';
    }

    /**
     * A checkout of the demo on this computer: copies of demo/bin/server and demo/bin/public, beside stand-ins for
     * its console and its setup that record what they are asked to do. Setup writes the address it was given into the
     * runtime's settings, as the real one does. The runtime at $runtime, set up for $setUpFor, holds only its settings.
     *
     * @return string the checkout's root
     */
    private function checkout(?string $setUpFor = null, string $runtime = 'var/demo', string $name = 'checkout'): string
    {
        $root = $this->directory.'/'.$name;
        $calls = var_export(\dirname($this->computer()).'/calls.log', true);
        $filesystem = new Filesystem();
        foreach (['server', 'public'] as $script) {
            $filesystem->copy(\dirname(__DIR__, 2).'/demo/bin/'.$script, $root.'/demo/bin/'.$script);
            $filesystem->chmod($root.'/demo/bin/'.$script, 0755);
        }
        $filesystem->dumpFile($root.'/demo/bin/console', str_replace('CALLS', $calls, <<<'PHP'
            <?php
            file_put_contents(CALLS, 'console '.implode(' ', array_slice($argv, 1))."\n", \FILE_APPEND);
            PHP));
        $filesystem->dumpFile($root.'/demo/bin/setup.php', str_replace('CALLS', $calls, <<<'PHP'
            <?php
            file_put_contents(CALLS, 'setup '.$argv[1].' for '.getenv('VERIFIED_HUMAN_APPROVAL_ORIGIN').', registration '.getenv('VERIFIED_HUMAN_APPROVAL_DYNAMIC_CLIENT_REGISTRATION')."\n", \FILE_APPEND);
            $runtime = (string) getenv('VERIFIED_HUMAN_APPROVAL_RUNTIME');
            is_dir($runtime) || mkdir($runtime, 0700, true);
            file_put_contents($runtime.'/settings.json', json_encode(['origin' => getenv('VERIFIED_HUMAN_APPROVAL_ORIGIN')], \JSON_UNESCAPED_SLASHES));
            PHP));
        $filesystem->mkdir($root.'/var');
        if (null !== $setUpFor) {
            $this->runtime($root, $runtime, $setUpFor);
        }

        return $root;
    }

    /** @return string the runtime at $runtime in $checkout, set up for $origin as far as the scripts read it: its settings */
    private function runtime(string $checkout, string $runtime, string $origin): string
    {
        (new Filesystem())->dumpFile($checkout.'/'.$runtime.'/settings.json', json_encode(['origin' => $origin], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        return $checkout.'/'.$runtime;
    }

    /**
     * A checkout whose demo/bin/server only records what it is asked and finds nothing on its port, with its tunnel
     * already answering at $tunnel and its public demo set up for $setUpFor.
     *
     * @return string the checkout's root
     */
    private function publicTree(?string $setUpFor, string $tunnel): string
    {
        $checkout = $this->checkout($setUpFor, 'var/public-demo');
        $calls = \dirname($this->computer()).'/calls.log';
        (new Filesystem())->dumpFile($checkout.'/demo/bin/server', "#!/bin/sh\necho \"server \$1\" >> '$calls'\n[ \"\$1\" != status ] || exit 3\n");
        $this->tunnel($checkout, $tunnel);

        return $checkout;
    }

    /** Starts $checkout's tunnel as demo/bin/public starts it, answering at $address, and forgets what was called. */
    private function tunnel(string $checkout, string $address): void
    {
        $this->tunnelAddress($address);
        $log = $checkout.'/var/public-tunnel.log';
        $this->launchd('submit', '-l', self::TUNNEL, '-o', $log, '-e', $log, '--', $this->computer().'/cloudflared', 'tunnel', '--no-autoupdate', '--url', 'http://localhost:8081');
        $this->forgetCalls();
    }

    /** The address the next tunnel to start answers at, as a quick tunnel writes it to its log. */
    private function tunnelAddress(string $address): void
    {
        file_put_contents($this->world().'/tunnel-address', "Your quick Tunnel has been created! Visit it at:\n$address\n");
    }

    private function launchd(string ...$arguments): void
    {
        [$status, $output] = self::script([$this->computer().'/launchctl', ...$arguments], []);
        self::assertSame(0, $status, $output);
    }

    /** @return int the ID of a program of someone's own that now listens on $port */
    private function listen(int $port, string $command): int
    {
        $pid = $this->nextProcessId();
        file_put_contents($this->world().'/processes', "$pid $port - $command\n", \FILE_APPEND);

        return $pid;
    }

    /** @return int the ID of a program of someone's own that starts listening on $port while a tunnel starts */
    private function meanwhile(int $port, string $command): int
    {
        $pid = $this->nextProcessId();
        file_put_contents($this->world().'/meanwhile', "$pid $port - $command\n", \FILE_APPEND);

        return $pid;
    }

    private function nextProcessId(): int
    {
        $pid = (int) file_get_contents($this->world().'/last-pid') + 1;
        file_put_contents($this->world().'/last-pid', $pid."\n");

        return $pid;
    }

    /** Every process stops, as if each had failed; launchd's jobs stay. */
    private function stopEveryProcess(): void
    {
        file_put_contents($this->world().'/processes', '');
    }

    /** @return array<int, string> the command line of each process on this computer, by process ID */
    private function processes(): array
    {
        $processes = [];
        foreach (file($this->world().'/processes', \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$pid, , , $command] = explode(' ', $line, 4);
            $processes[(int) $pid] = $command;
        }

        return $processes;
    }

    /** @return list<string> what the scripts asked this computer to change, in order */
    private function calls(): array
    {
        $calls = \dirname($this->computer()).'/calls.log';

        return is_file($calls) ? file($calls, \FILE_IGNORE_NEW_LINES) : [];
    }

    private function forgetCalls(): void
    {
        (new Filesystem())->remove(\dirname($this->computer()).'/calls.log');
    }

    /** @return list<string> the jobs this computer's launchd was given, in order */
    private function launched(): array
    {
        return array_values(array_filter($this->calls(), static fn (string $call): bool => str_starts_with($call, 'launchctl submit ')));
    }

    /**
     * Runs $checkout's demo/bin/server on this computer, with no PORT and no runtime named unless $environment names them.
     *
     * @param array<string, string> $environment
     *
     * @return array{int, string}
     */
    private function server(string $checkout, string $command, array $environment = []): array
    {
        return $this->onThisComputer([$checkout.'/demo/bin/server', $command], $environment);
    }

    /** @return array{int, string} what $checkout's demo/bin/public did on this computer */
    private function publicDemo(string $checkout, string ...$arguments): array
    {
        return $this->onThisComputer([$checkout.'/demo/bin/public', ...$arguments], []);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     *
     * @return array{int, string}
     */
    private function onThisComputer(array $command, array $environment): array
    {
        return self::script($command, $environment + [
            'PATH' => $this->computer().':'.\dirname(\PHP_BINARY).':/usr/bin:/bin', 'PHP_BIN' => \PHP_BINARY,
            'PORT' => null, 'VERIFIED_HUMAN_APPROVAL_RUNTIME' => null,
        ]);
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
