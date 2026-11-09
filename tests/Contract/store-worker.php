<?php

declare(strict_types=1);

use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Tests\Contract\StoreHarness;
use VerifiedHumanApproval\Tests\Support\ProcessRace;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// One competing process in a contract race. It connects to data the test prepared, through the harness the
// test names, and runs no DDL.
try {
    $input = ProcessRace::payload();
    $harness = $input['harness'];
    if (!is_string($harness) || !is_subclass_of($harness, StoreHarness::class)) {
        throw new InvalidArgumentException('The payload must name a store harness.');
    }
    $connection = $harness::connect($input['location']);
    ProcessRace::start($input);
    $answer = match ($input['operation']) {
        'complete' => $connection->service()->complete($connection->reviewer(), $input['ceremonyId'], $input['proof']),
        'request' => $connection->service()->request($connection->actor(), 'article.publish', ['id' => $input['article'] ?? 1], $input['key'] ?? null),
        'edit' => $connection->editArticle(),
        'revoke_grant' => $connection->revokeGrant(),
        default => throw new InvalidArgumentException('Unknown operation.'),
    };
    $report = null === $answer ? ['status' => 'done'] : ['status' => $answer->status, 'requestId' => $answer->request->id, 'result' => $answer->result];
    fwrite(\STDOUT, json_encode($report + ['executeCalls' => $connection->executeCalls()], \JSON_THROW_ON_ERROR)."\n");
} catch (ApprovalException $exception) {
    fwrite(\STDOUT, json_encode(['status' => 'rejected', 'reason' => $exception->reason, 'executeCalls' => isset($connection) ? $connection->executeCalls() : 0], \JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $exception) {
    fwrite(\STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
