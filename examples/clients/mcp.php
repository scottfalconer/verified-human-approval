<?php

declare(strict_types=1);

require __DIR__.'/connection.php';

try {
    $tools = approvalToolset();
    $command = $argv[1] ?? 'list';
    $result = match ($command) {
        'list' => ['tools' => $tools->getTools()],
        'read' => $tools->callTool('read_announcement', ['announcement_id' => $argv[2] ?? 'summer-campaign']),
        'publish' => $tools->callTool('publish_announcement', ['announcement_id' => $argv[2] ?? 'summer-campaign', 'request_key' => $argv[3] ?? bin2hex(random_bytes(16))]),
        'status' => $tools->callTool('approval_status', ['request_id' => $argv[2] ?? throw new InvalidArgumentException('Provide a request ID.')]),
        default => throw new InvalidArgumentException('Usage: php examples/clients/mcp.php list|read [id]|publish [id] [request-key]|status <request-id>'),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    $tools->disconnect();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
