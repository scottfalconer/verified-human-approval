<?php

declare(strict_types=1);

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\Receipt;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
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

// register_argc_argv can disable the argument globals. Missing input is a usage error, not a PHP warning.
$argv ??= [];
$argc = count($argv);
if (3 !== $argc && 4 !== $argc) {
    fwrite(\STDERR, <<<'USAGE'
        Usage: vendor/bin/verify-evidence.php receipt.json site-keys.json [passkey.json]
          receipt.json    a receipt (evidence bundle), as the person downloaded it
          site-keys.json  the site's published keys, saved from a source you trust, never taken from the receipt:
                          its key set (receipt-keys.json, every key with its window) or its one-key file (receipt-key.json)
          passkey.json    optional: the approving passkey's trusted record; without it the passkey signature is not checked

        USAGE);
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
    // The key set lists every key the site has signed with, each with its window; the earlier file names one
    // key (keyId and publicKey) and trusts it at any time. Both name the passkey relying party and origin.
    $application = $read($argv[2]);
    $application = $fields($application, TrustedKeys::FORMAT === ($application['format'] ?? null) ? ['rpId', 'origin'] : ['keyId', 'publicKey', 'rpId', 'origin'], 'application');
    try {
        $keys = TrustedKeys::fromDocument($application);
    } catch (InvalidArgumentException $error) {
        throw new RuntimeException('The trusted application file is not a key file this command reads: '.$error->getMessage(), 0, $error);
    }
    $trusted = 4 === $argc ? $fields($read($argv[3]), ['id', 'userId', 'tenantId', 'sourceJson'], 'credential') : null;
    $verified = (new ReceiptVerifier())->verify($bundle, $keys);
    $proof = $verified['proof'];
    // false: no passkey approved it (the rule did not require one). null: a passkey signed it, but without its
    // trusted record that signature was not checked here.
    $human = null === $proof ? false : null;
    if (null !== $proof && null !== $trusted) {
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
        // Which of the trusted keys vouched for the report, inside its window.
        'applicationKeyId' => $verified['keyId'],
        'statement' => $verified['request']->statement(),
        'qualification' => match ($human) {
            true => 'Verifies credential approval and the application report.',
            false => 'Verifies the application report. No passkey approved this action: the application’s rule did not require one.',
            null => 'Verifies the application report. The passkey signature was not checked: no trusted passkey record was given.',
        }.' It does not independently witness the database effect, display, historical revocation state or human understanding.'
            .(Receipt::EXPORT === $verified['receiptSignedAt'] ? ' The report was signed when it was exported, not when the action committed.' : ''),
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    // The verifier's refusals carry what did not match, such as a changed record or a key the trusted file does not list.
    $why = $error->getPrevious() instanceof UnexpectedValueException ? $error->getPrevious() : $error;
    fwrite(\STDERR, 'Verification failed: '.$why->getMessage()."\n");
    exit(1);
}
