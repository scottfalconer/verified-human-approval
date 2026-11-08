<?php

declare(strict_types=1);

/** TEST ONLY. Credentials arrive on stdin; they are never printed or passed in command arguments. */
require dirname(__DIR__, 4).'/examples/drupal/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
if (parse_url($input['endpoint'], PHP_URL_HOST) !== 'localhost') {
    throw new RuntimeException('This acceptance client only sends demo credentials to localhost.');
}
$factory = new \GuzzleHttp\Psr7\HttpFactory();
$client = \Mcp\Client::builder()->setMaxRetries(0)->build();
$client->connect(new \Mcp\Client\Transport\HttpTransport(
    endpoint: $input['endpoint'],
    headers: ['Authorization' => 'Basic '.base64_encode($input['user'].':'.$input['password'])],
    httpClient: new \GuzzleHttp\Client(['timeout' => 15, 'allow_redirects' => false]),
    requestFactory: $factory,
    streamFactory: $factory,
));
try {
    $result = match ($input['method']) {
        'list' => $client->listTools(),
        'resources' => $client->listResources(),
        'read' => $client->readResource($input['arguments']['uri']),
        default => $client->callTool($input['method'], $input['arguments'] ?? []),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} finally {
    $client->disconnect();
}

