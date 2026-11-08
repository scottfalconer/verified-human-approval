<?php

declare(strict_types=1);

/** Install an isolated, fictional Drupal demo. Refuses to replace an existing site. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__.'/vendor/autoload.php';

$root = __DIR__;
$runtime = $root.'/var';
$database = $runtime.'/drupal.sqlite';
if (is_file($database)) {
    fwrite(STDERR, "This demo already has a database. Setup does not replace existing sites.\n");
    exit(1);
}
foreach ([$runtime, $runtime.'/private', $runtime.'/config', $root.'/web/sites/default/files'] as $directory) {
    if (!is_dir($directory)) mkdir($directory, 0700, true);
}
$origin = getenv('EXACT_APPROVAL_ORIGIN') ?: 'http://localhost:8090';
$host = parse_url($origin, PHP_URL_HOST);
new \ExactApproval\WebAuthn\Configuration($host, $origin);
$adminPassword = bin2hex(random_bytes(16));
$editorPassword = bin2hex(random_bytes(16));
$key = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
$settings = file_get_contents($root.'/web/sites/default/default.settings.php');
$settings .= "\n// Local disposable exact-approval demo. Never copy these generated settings to production.\n";
$settings .= '$databases[\'default\'][\'default\'] = '.var_export([
    'database' => $database, 'prefix' => '', 'driver' => 'sqlite',
    'namespace' => 'Drupal\\sqlite\\Driver\\Database\\sqlite',
    'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/',
], true).";\n";
foreach ([
    'hash_salt' => bin2hex(random_bytes(32)),
    'config_sync_directory' => $runtime.'/config',
    'file_private_path' => $runtime.'/private',
    'trusted_host_patterns' => ['^'.preg_quote($host, '/').'$'],
    'extension_discovery_scan_tests' => true,
    'exact_approval_acceptance_test' => true,
    'exact_approval_signing_key_id' => 'drupal-demo-'.bin2hex(random_bytes(4)),
    'exact_approval_signing_secret' => \ExactApproval\Core\ChallengeBinding::encode($key),
] as $name => $value) {
    $settings .= '$settings['.var_export($name, true).'] = '.var_export($value, true).";\n";
}
file_put_contents($root.'/web/sites/default/settings.php', $settings);
chmod($root.'/web/sites/default/settings.php', 0600);
file_put_contents($runtime.'/demo.json', json_encode(['origin' => $origin, 'rp_id' => $host, 'admin_password' => $adminPassword, 'editor_password' => $editorPassword], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
chmod($runtime.'/demo.json', 0600);

$run = static function (array $arguments) use ($root, $origin): void {
    $command = array_merge([PHP_BINARY, $root.'/vendor/drush/drush/drush.php', '--root='.$root.'/web', '--uri='.$origin], $arguments);
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Demo setup failed; inspect the preceding Drupal output.');
    }
};
$run(['site:install', 'minimal', '--yes', '--account-name=admin', '--account-pass='.$adminPassword, '--site-name=Verified Human Approval Demo']);
$run(['en', '--yes', 'exact_approval', 'content_moderation', 'basic_auth', 'exact_approval_test', 'mcp_server', 'exact_approval_mcp']);
$run(['php:script', $root.'/configure.php']);
echo "\nDemo installed at $origin\nSign in as editor. The generated local passwords are in examples/drupal/var/demo.json.\n";
echo "Start from examples/drupal/web: php -S localhost:8090 .ht.router.php\n";

