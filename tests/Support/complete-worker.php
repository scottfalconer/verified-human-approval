<?php

declare(strict_types=1);

use ExactApproval\Core\ApprovalException;
use ExactApproval\Tests\Support\CoreFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $input = json_decode((string) fgets(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $fixture = new CoreFixture($input['database']);
    file_put_contents($input['ready'], 'ready', LOCK_EX);
    $deadline = microtime(true) + 15;
    while (!is_file($input['barrier'])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('The concurrency test barrier was not released.');
        }
        usleep(5000);
        clearstatcache(true, $input['barrier']);
    }
    if ($input['operation'] === 'request') {
        $state = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1], $input['idempotencyKey']);
    } else {
        $state = $fixture->service->complete($fixture->reviewer, $input['ceremonyId'], $input['assertion']);
    }
    fwrite(STDOUT, json_encode(['status' => $state->status, 'requestId' => $state->request->id, 'result' => $state->result], JSON_THROW_ON_ERROR)."\n");
} catch (ApprovalException $exception) {
    fwrite(STDOUT, json_encode(['status' => 'rejected', 'reason' => $exception->reason], JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
