<?php

declare(strict_types=1);

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\CredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

// Composer's vendor/bin proxy names the autoloader. Run directly, the script finds it next to an installed
// package (vendor/verified-human-approval/webauthn/bin) or in the monorepo checkout (src/webauthn/bin).
foreach ([$GLOBALS['_composer_autoload_path'] ?? null, dirname(__DIR__, 3).'/autoload.php', dirname(__DIR__, 3).'/vendor/autoload.php'] as $autoload) {
    if (is_string($autoload) && is_file($autoload)) {
        require $autoload;
        break;
    }
}
if (!class_exists(ReceiptVerifier::class)) {
    fwrite(\STDERR, "No Composer autoloader found. Install verified-human-approval/webauthn with Composer, then run vendor/bin/verify-evidence.php.\n");
    exit(2);
}

if (4 !== $argc) {
    fwrite(\STDERR, "Usage: vendor/bin/verify-evidence.php evidence.json trusted-application.json trusted-credential.json\nTrust files must come from an independently trusted registration/key source, not the evidence itself.\n");
    exit(2);
}

try {
    $read = static function (string $path): array {
        if (!is_file($path) || filesize($path) > 1048576) {
            throw new RuntimeException('Expected a local JSON file smaller than 1 MiB.');
        }

        $json = file_get_contents($path);
        if (false === $json) {
            throw new RuntimeException('Could not read '.$path.'.');
        }

        return CanonicalJson::map(CanonicalJson::decode($json));
    };
    // Each trust file names exactly what is trusted; anything missing is an error, not a guess.
    $fields = static function (array $file, array $names, string $which): array {
        foreach ($names as $name) {
            if (!is_string($file[$name] ?? null) || '' === $file[$name]) {
                throw new RuntimeException(sprintf('The trusted %s file needs "%s".', $which, $name));
            }
        }

        return $file;
    };
    $bundle = $read($argv[1]);
    $application = $fields($read($argv[2]), ['keyId', 'publicKey', 'rpId', 'origin'], 'application');
    $trusted = $fields($read($argv[3]), ['id', 'userId', 'tenantId', 'sourceJson'], 'credential');
    $verified = (new ReceiptVerifier())->verify($bundle, $application['keyId'], $application['publicKey']);
    $proof = $verified['proof'];
    $human = false;
    if (null !== $proof) {
        $repository = new class implements CredentialRepository {
            public function find(string $id): ?Credential
            {
                return null;
            }

            public function forActor(Actor $actor): array
            {
                return [];
            }

            public function add(Credential $credential): void
            {
                throw new LogicException('Offline verification cannot register credentials.');
            }

            public function update(Credential $credential, string $expectedSourceJson): void
            {
                throw new LogicException('Offline verification cannot update credentials.');
            }
        };
        $verifier = new WebAuthnVerifier(new Configuration($application['rpId'], $application['origin']), $repository);
        $credential = new Credential($trusted['id'], $trusted['userId'], $trusted['tenantId'], $trusted['sourceJson']);
        $result = $verifier->verifyEvidenceAssertion($verified['request']->actor(), ChallengeBinding::decode($proof['challenge']), CanonicalJson::map($proof['assertion']), $credential);
        if (!hash_equals($proof['credentialId'], $result->credentialId)) {
            throw new RuntimeException('The evidence identifies a different approval credential.');
        }
        $human = true;
    }
    echo json_encode([
        'requestId' => $verified['request']->id,
        'credentialApprovedExactProposal' => $human,
        'applicationSignedExecutionReport' => true,
        // "commit": signed as the action committed, so an edit made afterwards would not verify. "export":
        // signed only when the evidence was exported; it cannot show an edit made before that.
        'applicationReportSignedAt' => $verified['receiptSignedAt'],
        'statement' => $verified['request']->statement(),
        'qualification' => 'Verifies credential approval and the application report. It does not independently witness the database effect, display, historical revocation state or human understanding.'
            .(Receipt::EXPORT === $verified['receiptSignedAt'] ? ' The report was signed when it was exported, not when the action committed.' : ''),
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(\STDERR, 'Verification failed: '.$error->getMessage()."\n");
    exit(1);
}
