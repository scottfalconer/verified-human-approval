<?php

declare(strict_types=1);

require \dirname(__DIR__, 3).'/vendor/autoload.php';

use ExactApproval\Demo\DemoSetup;
use Symfony\Component\Filesystem\Filesystem;

$directory = getenv('EXACT_APPROVAL_RUNTIME') ?: \dirname(__DIR__, 3).'/var/demo';
$origin = getenv('EXACT_APPROVAL_ORIGIN') ?: 'http://localhost:8080';
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
    fwrite(STDOUT, "Start the site:\n  PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080 -t examples/symfony/public examples/symfony/public/index.php\n\n");
    fwrite(STDOUT, "Connect Claude Code as the editor:\n  claude mcp add --transport http verified-human-approval $mcp --header \"Authorization: Bearer ".$created['accounts']['editor']['personal_token']."\"\n\n");
    fwrite(STDOUT, "Connect Claude Desktop as the editor: add this under \"mcpServers\" in claude_desktop_config.json, then restart Claude:\n");
    fwrite(STDOUT, '  "verified-human-approval": {"command": "npx", "args": ["-y", "mcp-remote", "'.$mcp.'", "--allow-http", "--header-file", "'.$headers.'"]}'."\n");
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
