<?php

declare(strict_types=1);

// Runs inside a separate Composer project, with only that project's autoloader: what another application,
// a Drupal module say, gets from the packages. Usage: php consumer-smoke.php <consumer directory> <webauthn|mcp>

use Composer\InstalledVersions;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

[, $consumer, $variant] = $argv + [null, null, null];
if (!is_string($consumer) || !in_array($variant, ['webauthn', 'mcp'], true)) {
    fwrite(\STDERR, "Usage: php consumer-smoke.php <consumer directory> <webauthn|mcp>\n");
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

exit([] === $failures ? 0 : 1);
