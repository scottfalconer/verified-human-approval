<?php

declare(strict_types=1);

require \dirname(__DIR__, 3).'/vendor/autoload.php';

use ExactApproval\Demo\DemoKernel;
use Symfony\Component\HttpFoundation\Request;

$runtime = getenv('EXACT_APPROVAL_RUNTIME') ?: \dirname(__DIR__, 3).'/var/demo';
if (!is_file($runtime.'/settings.json')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Initialize the example first: php examples/symfony/bin/setup.php\n";
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
