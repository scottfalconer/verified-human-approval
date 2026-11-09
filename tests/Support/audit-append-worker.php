<?php

declare(strict_types=1);

use Symfony\Component\Clock\NativeClock;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;
use VerifiedHumanApproval\Tests\Support\ProcessRace;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// One of several processes appending to the same audit log at once. It connects to a prepared database
// and never creates tables.
try {
    $input = ProcessRace::payload();
    $pdo = new PDO('sqlite:'.$input['database'], options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 15000');
    $signer = SodiumEvidenceSigner::fromEncoded($input['keyId'], $input['secretKey']);
    $log = new AuditLog(new PdoAuditStore($pdo), $signer, $signer->publicKey(), new NativeClock());
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
