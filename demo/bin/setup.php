<?php

declare(strict_types=1);

require \dirname(__DIR__, 2).'/vendor/autoload.php';

use VerifiedHumanApproval\Demo\DemoSetup;
use Symfony\Component\Filesystem\Filesystem;

$directory = getenv('VERIFIED_HUMAN_APPROVAL_RUNTIME') ?: \dirname(__DIR__, 2).'/var/demo';
$origin = getenv('VERIFIED_HUMAN_APPROVAL_ORIGIN') ?: 'http://localhost:8080';
try {
    // --reset starts a fresh demo for another take. It only removes a directory this setup created.
    if (in_array('--reset', $argv, true) && is_dir($directory)) {
        if (!is_file($directory.'/application.sqlite')) {
            throw new RuntimeException("Refusing to reset $directory: it does not look like a demo runtime.");
        }
        (new Filesystem())->remove($directory);
    }
    $created = DemoSetup::initialize($directory, $origin);
    // A private header file lets mcp-remote send the editor's personal token without putting it in process arguments.
    $headers = $directory.'/claude-headers.txt';
    file_put_contents($headers, 'Authorization: Bearer '.$created['accounts']['editor']['personal_token']."\n");
    chmod($headers, 0600);
    $mcp = rtrim($origin, '/').'/mcp';
    fwrite(STDOUT, "Local demo initialized. Save these credentials privately; passwords/tokens are shown only once.\n");
    fwrite(STDOUT, json_encode($created, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n");
    fwrite(STDOUT, "Add a passkey: sign in as an account, then open that account's passkey_link above. A password alone cannot add an approval passkey; each link works once, for seven days.\n\n");
    fwrite(STDOUT, "Start the site:\n  PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080 -t demo/public demo/public/index.php\n\n");
    fwrite(STDOUT, "Connect Claude Code as the editor:\n  claude mcp add --transport http verified-human-approval $mcp --header \"Authorization: Bearer ".$created['accounts']['editor']['personal_token']."\"\n\n");
    fwrite(STDOUT, "Connect Claude Desktop as the editor: add this under \"mcpServers\" in claude_desktop_config.json, then restart Claude:\n");
    fwrite(STDOUT, '  "verified-human-approval": {"command": "npx", "args": ["-y", "mcp-remote", "'.$mcp.'", "--allow-http", "--header-file", "'.$headers.'"]}'."\n");
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
