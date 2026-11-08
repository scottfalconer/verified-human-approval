<?php

declare(strict_types=1);

require \dirname(__DIR__, 3).'/vendor/autoload.php';

use ExactApproval\Demo\DemoSetup;

$directory = getenv('EXACT_APPROVAL_RUNTIME') ?: \dirname(__DIR__, 3).'/var/demo';
$origin = getenv('EXACT_APPROVAL_ORIGIN') ?: 'http://localhost:8080';
try {
    $created = DemoSetup::initialize($directory, $origin);
    fwrite(STDOUT, "Local demo initialized. Save these credentials privately; passwords/tokens are shown only once.\n");
    fwrite(STDOUT, json_encode($created, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    fwrite(STDOUT, "Start: php -S localhost:8080 -t examples/symfony/public examples/symfony/public/index.php\n");
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
