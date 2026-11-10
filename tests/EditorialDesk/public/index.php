<?php

declare(strict_types=1);
require dirname(__DIR__).'/config/bootstrap.php';
$kernel = new App\Kernel(getenv('APP_ENV') ?: 'dev', (bool) (getenv('APP_DEBUG') ?: false));
$request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
