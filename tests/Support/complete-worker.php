<?php

declare(strict_types=1);

use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Tests\Support\CoreFixture;
use VerifiedHumanApproval\Tests\Support\ProcessRace;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $input = ProcessRace::payload();
    $fixture = new CoreFixture($input['database']);
    ProcessRace::start($input);
    if ('request' === $input['operation']) {
        $state = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1], $input['idempotencyKey']);
    } else {
        $state = $fixture->service->complete($fixture->reviewer, $input['ceremonyId'], $input['assertion']);
    }
    fwrite(\STDOUT, json_encode(['status' => $state->status, 'requestId' => $state->request->id, 'result' => $state->result], \JSON_THROW_ON_ERROR)."\n");
} catch (ApprovalException $exception) {
    fwrite(\STDOUT, json_encode(['status' => 'rejected', 'reason' => $exception->reason], \JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $exception) {
    fwrite(\STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
