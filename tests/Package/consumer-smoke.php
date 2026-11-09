<?php

declare(strict_types=1);

// Runs inside a separate Composer project, with only that project's autoloader: what another application,
// a Drupal module say, gets from the packages. Usage: php consumer-smoke.php <consumer directory> <webauthn|mcp|doctrine>

use Composer\InstalledVersions;
use Doctrine\DBAL\DriverManager;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\DbalApprovalStore;
use VerifiedHumanApproval\Doctrine\DbalAuditStore;
use VerifiedHumanApproval\Doctrine\DbalCredentialRepository;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

[, $consumer, $variant] = $argv + [null, null, null];
if (!is_string($consumer) || !in_array($variant, ['webauthn', 'mcp', 'doctrine'], true)) {
    fwrite(\STDERR, "Usage: php consumer-smoke.php <consumer directory> <webauthn|mcp|doctrine>\n");
    exit(2);
}
require $consumer.'/vendor/autoload.php';

$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ').$what."\n";
    if (!$ok) {
        $failures[] = $what;
    }
};
$vendor = (string) realpath($consumer.'/vendor');
$from = static fn (string $class): string => (string) (new ReflectionClass($class))->getFileName();

$check(str_starts_with($from(ApprovalService::class), $vendor.'/verified-human-approval/core/'), 'core loads from the consumer’s own vendor directory');
$check(str_starts_with($from(WebAuthnVerifier::class), $vendor.'/verified-human-approval/webauthn/'), 'webauthn loads from the consumer’s own vendor directory');
foreach (['symfony/framework-bundle', 'symfony/security-bundle', 'symfony/twig-bundle', 'symfony/mcp-bundle', 'verified-human-approval/symfony-bundle'] as $package) {
    $check(!InstalledVersions::isInstalled($package), $package.' is not installed');
}

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$credentials = new PdoCredentialRepository($pdo);
$credentials->initialize();
$options = (new WebAuthnVerifier(new Configuration('consumer.test', 'https://consumer.test'), $credentials))->registrationOptions(new Actor('alice', 'tenant-a'), random_bytes(32), 'Alice');
$check('consumer.test' === ($options['rp']['id'] ?? null) && is_string($options['challenge'] ?? null), 'WebAuthn builds registration options');

if ('mcp' === $variant) {
    $check(str_starts_with($from(ApprovalCard::class), $vendor.'/verified-human-approval/mcp/'), 'mcp loads from the consumer’s own vendor directory');
    $resource = ApprovalCard::resource();
    $check(ApprovalCard::URI === $resource->uri && str_contains((string) $resource->text, 'ui/initialize'), 'the MCP card resource loads from the installed package');
} else {
    $check(!InstalledVersions::isInstalled('verified-human-approval/mcp'), 'mcp is not pulled in when it is not asked for');
}

if ('doctrine' === $variant) {
    $check(str_starts_with($from(DbalTransaction::class), $vendor.'/verified-human-approval/doctrine/'), 'doctrine loads from the consumer’s own vendor directory');
    $check(!InstalledVersions::isInstalled('doctrine/orm'), 'the ORM stays optional');
    // The three stores on one DBAL connection, in one transaction, as an application without a framework uses them.
    $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    ApprovalSchema::install($connection);
    $transaction = new DbalTransaction($connection);
    $signer = new SodiumEvidenceSigner('consumer-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    $log = new AuditLog(new DbalAuditStore($transaction), $signer, $signer->publicKey(), new class implements Psr\Clock\ClockInterface {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-09T10:00:00Z');
        }
    });
    $anchored = new AnchoredCredentialRepository(new DbalCredentialRepository($transaction), $log);
    $credential = new Credential('cred-1', 'alice', 'tenant-a', '{"publicKeyCredentialId":"Y3JlZC0x","credentialPublicKey":"a2V5","userHandle":"aGFuZGxl"}');
    $transaction->run(static function () use ($anchored, $credential): void {
        $anchored->add($credential);
        $anchored->anchor($credential, ['method' => 'consumer check']);
    });
    $check(null !== $anchored->find('cred-1') && $log->verify()['intact'], 'a passkey enrolled through the Doctrine stores is vouched for by the signed log');
    try {
        $connection->transactional(static fn () => (new DbalApprovalStore($transaction))->transaction(static fn () => null));
        $check(false, 'the approval transaction refuses an outer one');
    } catch (LogicException) {
        $check(true, 'the approval transaction refuses an outer one');
    }
}

exit([] === $failures ? 0 : 1);
