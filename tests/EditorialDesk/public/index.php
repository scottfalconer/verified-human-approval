<?php

declare(strict_types=1);
require dirname(__DIR__).'/config/bootstrap.php';
// A test fixture, which its harnesses serve with the environment named. Served by hand without one it runs as prod;
// it is still not meant for a public address.
$kernel = new App\Kernel(getenv('APP_ENV') ?: 'prod', (bool) (getenv('APP_DEBUG') ?: false));
$request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
