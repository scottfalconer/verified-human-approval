<?php

declare(strict_types=1);

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\CredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

// Installed through Composer, or run from the monorepo checkout.
foreach ([dirname(__DIR__, 4).'/autoload.php', dirname(__DIR__, 3).'/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

if (4 !== $argc) {
    fwrite(\STDERR, "Usage: php src/core/bin/verify-evidence.php evidence.json trusted-application.json trusted-credential.json\nTrust files must come from an independently trusted registration/key source, not the evidence itself.\n");
    exit(2);
}

try {
    $read = static function (string $path): array {
        if (!is_file($path) || filesize($path) > 1048576) {
            throw new RuntimeException('Expected a local JSON file smaller than 1 MiB.');
        }

        return CanonicalJson::map(CanonicalJson::decode(file_get_contents($path)));
    };
    $bundle = $read($argv[1]);
    $application = $read($argv[2]);
    $trusted = $read($argv[3]);
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
        'statement' => $verified['request']->statement(),
        'qualification' => 'Verifies credential approval and the application report. It does not independently witness the database effect, display, historical revocation state or human understanding.',
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(\STDERR, 'Verification failed: '.$error->getMessage()."\n");
    exit(1);
}
