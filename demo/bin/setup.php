<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;
use VerifiedHumanApproval\Demo\DemoKernel;
use VerifiedHumanApproval\Demo\DemoSetup;

$directory = getenv('VERIFIED_HUMAN_APPROVAL_RUNTIME') ?: dirname(__DIR__, 2).'/var/demo';
$origin = getenv('VERIFIED_HUMAN_APPROVAL_ORIGIN') ?: 'http://localhost:8080';
// OAuth clients may register themselves unless this is set to a false value; the public demo sets it to 0.
$registration = getenv('VERIFIED_HUMAN_APPROVAL_DYNAMIC_CLIENT_REGISTRATION');
$registration = false === $registration || filter_var($registration, \FILTER_VALIDATE_BOOL);
try {
    // --reset starts a fresh demo for another take. It only removes a directory this setup created.
    if (in_array('--reset', $argv, true) && is_dir($directory)) {
        if (!is_file($directory.'/application.sqlite')) {
            throw new RuntimeException("Refusing to reset $directory: it does not look like a demo runtime.");
        }
        (new Filesystem())->remove($directory);
    }
    $created = DemoSetup::initialize($directory, $origin, $registration);
    // The review pages load the bundle's script and stylesheet from demo/public/bundles/, like any Symfony app after assets:install.
    $console = new Application(new DemoKernel($directory));
    $console->setAutoExit(false);
    if (0 !== $console->run(new ArrayInput(['command' => 'assets:install', 'target' => dirname(__DIR__).'/public', '--symlink' => true, '--relative' => true]), new NullOutput())) {
        throw new RuntimeException('Publishing the bundle assets failed: run php demo/bin/console assets:install demo/public');
    }
    // A private header file lets mcp-remote send the editor's personal token without putting it in process arguments.
    $headers = $directory.'/claude-headers.txt';
    file_put_contents($headers, 'Authorization: Bearer '.$created['accounts']['editor']['personal_token']."\n");
    chmod($headers, 0600);
    $mcp = rtrim($origin, '/').'/mcp';
    fwrite(\STDOUT, "Local demo initialized. Save these credentials privately; passwords/tokens are shown only once.\n");
    fwrite(\STDOUT, json_encode($created, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n\n");
    fwrite(\STDOUT, "Add a passkey: sign in as an account, then open that account's passkey_link above. A password alone cannot add an approval passkey; each link works once, for seven days.\n\n");
    fwrite(\STDOUT, "Start the site:\n  PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080 -t demo/public demo/public/index.php\n\n");
    fwrite(\STDOUT, "Connect Claude Code as the editor:\n  claude mcp add --transport http verified-human-approval $mcp --header \"Authorization: Bearer ".$created['accounts']['editor']['personal_token']."\"\n\n");
    fwrite(\STDOUT, "Connect Claude Desktop as the editor: add this under \"mcpServers\" in claude_desktop_config.json, then restart Claude:\n");
    fwrite(\STDOUT, '  "verified-human-approval": {"command": "npx", "args": ["-y", "mcp-remote", "'.$mcp.'", "--allow-http", "--header-file", "'.$headers.'"]}'."\n");
} catch (Throwable $error) {
    fwrite(\STDERR, $error->getMessage()."\n");
    exit(1);
}
