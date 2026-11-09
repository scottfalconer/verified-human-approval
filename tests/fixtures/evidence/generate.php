<?php

declare(strict_types=1);

// Writes commit-v2/: the evidence of one synthetic approval, its receipt signed as it committed, and the
// public trust files that check it. The passkey and the receipt key are made here and thrown away, so the
// fixture holds public material only. Run it again only to replace the fixture on purpose.

use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Tests\Support\CoreFixture;
use VerifiedHumanApproval\Tests\Support\FixtureAction;
use VerifiedHumanApproval\Tests\Support\VirtualAuthenticator;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$fixture = new CoreFixture();
$credentials = new PdoCredentialRepository($fixture->pdo);
$credentials->initialize();
$verifier = new WebAuthnVerifier(new Configuration('approval.test', 'https://approval.test'), $credentials);
$authenticator = new VirtualAuthenticator();
$options = $verifier->registrationOptions($fixture->reviewer, random_bytes(32), 'Alice');
$credential = $verifier->register($fixture->reviewer, $options, $authenticator->registration($options));
$signer = new SodiumEvidenceSigner('synthetic-fixture-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
$guard = new ExecutionGuard();
$service = new ApprovalService(new ActionRegistry([new FixtureAction($fixture->pdo, $guard)]), $fixture->policies, $fixture->authorizer, $fixture->store, $verifier, $fixture->clock, $guard, $signer);

$pending = $service->request($fixture->actor, 'article.publish', ['id' => 1]);
$ceremony = $service->begin($fixture->reviewer, $pending->request->id);
$completed = $service->complete($fixture->reviewer, $ceremony->id, $authenticator->assertion($ceremony->options));

$directory = __DIR__.'/commit-v2';
if (!is_dir($directory)) {
    mkdir($directory);
}
$files = [
    'evidence.json' => (new EvidenceExporter())->export($service->get($fixture->actor, $completed->request->id)),
    'trusted-application.json' => ['keyId' => $signer->keyId(), 'publicKey' => $signer->publicKey(), 'rpId' => 'approval.test', 'origin' => 'https://approval.test'],
    'trusted-credential.json' => ['id' => $credential->id, 'userId' => $credential->userId, 'tenantId' => $credential->tenantId, 'sourceJson' => $credential->sourceJson],
];
foreach ($files as $name => $contents) {
    file_put_contents($directory.'/'.$name, json_encode($contents, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
}
echo "Wrote the synthetic commit-v2 evidence fixture.\n";
