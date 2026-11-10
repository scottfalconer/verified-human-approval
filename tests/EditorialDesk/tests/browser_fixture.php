<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use VerifiedHumanApproval\Core\ChallengeBinding;

$directory = $argv[1] ?? throw new RuntimeException('Supply a new test runtime directory.');
$origin = $argv[2] ?? throw new RuntimeException('Supply the test origin.');
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
mkdir($directory.'/state', 0700, true);
$settings = [
    'APP_ENV' => 'dev', 'APP_DEBUG' => '0', 'APP_ORIGIN' => $origin, 'APP_SECRET' => bin2hex(random_bytes(32)),
    'APP_CACHE_DIR' => $directory.'/cache', 'APP_LOG_DIR' => $directory.'/log', 'APP_STATE_DIR' => $directory.'/state',
    'DATABASE_URL' => 'sqlite:///'.$directory.'/app.sqlite', 'APPROVAL_RECEIPT_KEY_ID' => 'browser-check',
    'APPROVAL_RECEIPT_SECRET_KEY' => ChallengeBinding::encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
    'APPROVAL_RECEIPT_KEY_VALID_FROM' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'MCP_REQUEST_STATE_KEY' => bin2hex(random_bytes(32)),
];
foreach ($settings as $key => $value) {
    $_SERVER[$key] = $_ENV[$key] = $value;
    putenv($key.'='.$value);
}
$kernel = new App\Kernel('dev', false);
$kernel->boot();
$accounts = $kernel->getContainer()->get(App\Setup\Installer::class)->install();
file_put_contents($directory.'/fixture.json', json_encode(['env' => $settings, 'accounts' => $accounts], \JSON_THROW_ON_ERROR));
chmod($directory.'/fixture.json', 0600);
$kernel->shutdown();
echo "Isolated browser fixture ready.\n";
