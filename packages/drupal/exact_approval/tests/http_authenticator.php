<?php

declare(strict_types=1);

/** TEST ONLY: in-memory synthetic ES256 device for the HTTP ceremony smoke test. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$project = dirname(__DIR__, 4);
require $project . '/examples/drupal/vendor/autoload.php';
require $project . '/tests/Support/VirtualAuthenticator.php';
$origin = $argv[1] ?? 'http://localhost:8090';
if (!in_array(parse_url($origin, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('The HTTP smoke authenticator is restricted to a loopback demo.');
}
$authenticator = new \ExactApproval\Tests\Support\VirtualAuthenticator($origin);
while (($line = fgets(STDIN)) !== false) {
    try {
        $input = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        $response = match ($input['mode']) {
            'register' => $authenticator->registration($input['options']),
            'assert' => $authenticator->assertion($input['options']),
            default => throw new InvalidArgumentException('Unknown synthetic authenticator command.'),
        };
        fwrite(STDOUT, json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);
    } catch (Throwable $error) {
        fwrite(STDOUT, json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR) . "\n");
        fflush(STDOUT);
    }
}
