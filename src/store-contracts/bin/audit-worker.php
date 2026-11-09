<?php

declare(strict_types=1);

use Symfony\Component\Clock\NativeClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\StoreContracts\AuditHarness;
use VerifiedHumanApproval\StoreContracts\ProcessRace;

// The autoloader of the project that installed this package, of this repository, or of a checkout of the package.
foreach ([dirname(__DIR__, 3).'/autoload.php', dirname(__DIR__, 3).'/vendor/autoload.php', dirname(__DIR__).'/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

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
