<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;
use VerifiedHumanApproval\Example\Publishing\PublishingKernel;

// Under `php -S`, the files in public/ (the stylesheet, and the bundle's assets once installed) are served as they are.
if ('cli-server' === \PHP_SAPI) {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
    if ('/' !== $path && '/index.php' !== $path && !str_contains($path, '..') && is_file(__DIR__.$path)) {
        return false;
    }
}

require dirname(__DIR__, 3).'/vendor/autoload.php';
require dirname(__DIR__).'/config/bootstrap.php';

if (!isset($_SERVER['APP_SECRET']) && false === getenv('APP_SECRET')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Set the example up first: php examples/doctrine-publishing/bin/setup\n";

    return;
}
$kernel = new PublishingKernel((string) $_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
