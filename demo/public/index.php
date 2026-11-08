<?php

declare(strict_types=1);

// The PHP development server sends every request here. Let it serve the assets assets:install published.
if (\PHP_SAPI === 'cli-server') {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
    if (str_starts_with($path, '/bundles/') && !str_contains($path, '..') && is_file(__DIR__.$path)) {
        return false;
    }
}

require \dirname(__DIR__, 2).'/vendor/autoload.php';

use VerifiedHumanApproval\Demo\DemoKernel;
use Symfony\Component\HttpFoundation\Request;

$runtime = getenv('VERIFIED_HUMAN_APPROVAL_RUNTIME') ?: \dirname(__DIR__, 2).'/var/demo';
if (!is_file($runtime.'/settings.json')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Initialize the example first: php demo/bin/setup.php\n";
    exit;
}
try {
    $kernel = new DemoKernel($runtime);
} catch (\RuntimeException $error) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo $error->getMessage(), "\n";
    exit;
}
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
