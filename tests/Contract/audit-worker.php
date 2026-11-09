<?php

declare(strict_types=1);

use Symfony\Component\Clock\NativeClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Tests\Contract\AuditHarness;
use VerifiedHumanApproval\Tests\Support\ProcessRace;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// One of several processes appending to the same audit log at once, through the harness the test names. It
// connects to prepared data and never creates tables.
try {
    $input = ProcessRace::payload();
    $harness = $input['harness'];
    if (!is_string($harness) || !is_subclass_of($harness, AuditHarness::class)) {
        throw new InvalidArgumentException('The payload must name an audit harness.');
    }
    $signer = SodiumEvidenceSigner::fromEncoded($input['keyId'], $input['secretKey']);
    $log = new AuditLog($harness::connect($input['location'])->store(), $signer, $signer->publicKey(), new NativeClock());
    ProcessRace::start($input);
    $sequences = [];
    for ($i = 0; $i < $input['appends']; ++$i) {
        $sequences[] = $log->append($input['tenant'], 'race.appended', $input['worker'].'-'.$i, ['worker' => $input['worker']])['seq'];
    }
    fwrite(\STDOUT, json_encode(['sequences' => $sequences], \JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $exception) {
    fwrite(\STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
