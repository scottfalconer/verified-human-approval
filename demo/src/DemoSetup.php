<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo;

use Symfony\Component\Clock\NativeClock;
use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKey;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;
use VerifiedHumanApproval\Demo\Account\PasskeyLinks;
use VerifiedHumanApproval\WebAuthn\Configuration;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;

final class DemoSetup
{
    /** The runtime layout this code expects; an older runtime has to be reset. */
    public const SCHEMA = 4;
    private const LINK_SECONDS = 7 * 86400;

    /** Returns credentials once. No plaintext account password or token is stored. */
    public static function initialize(string $directory, string $origin = 'http://localhost:8080'): array
    {
        if (is_file($directory.'/settings.json')) {
            throw new \RuntimeException('This demo is already initialized; existing accounts were not replaced.');
        }
        $rpId = parse_url($origin, \PHP_URL_HOST);
        if (!\is_string($rpId)) {
            throw new \InvalidArgumentException('Provide a valid origin.');
        }
        new Configuration($rpId, $origin);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the runtime directory.');
        }
        chmod($directory, 0700);
        foreach (['sessions', 'cache', 'log', 'mcp'] as $subdirectory) {
            if (!is_dir($directory.'/'.$subdirectory)) {
                mkdir($directory.'/'.$subdirectory, 0700, true);
            }
        }
        $database = new DemoDatabase(DemoDatabase::connect($directory.'/application.sqlite'));
        $database->initialize();
        (new PdoApprovalStore($database->pdo))->initialize();
        (new PdoCredentialRepository($database->pdo))->initialize();
        // The application's evidence key signs receipts and the security log. It lives in settings.json,
        // outside the database, so database access alone cannot forge either. It is trusted from now on;
        // demo:rotate-receipt-key replaces it, keeping only its public key, to check what it signed.
        $keypair = sodium_crypto_sign_keypair();
        $signer = new SodiumEvidenceSigner('demo-application-1', sodium_crypto_sign_secretkey($keypair));
        $clock = new NativeClock();
        $validFrom = ApprovalRequest::timestamp($clock->now());
        $auditStore = new PdoAuditStore($database->pdo);
        $auditStore->initialize();
        $audit = new AuditLog($auditStore, $signer, new TrustedKeys([new TrustedKey($signer->keyId(), $signer->publicKey(), TrustedKey::time($validFrom))]), $clock);
        $links = new PasskeyLinks($database, $audit);
        $credentials = [];
        $users = [
            ['manager', 'campaigns', 'Emergency manager', true, true, 'manager'],
            ['editor', 'campaigns', 'County staff', true, false, 'staff'],
            ['reader', 'campaigns', 'Read-only colleague', false, false, 'reader'],
            ['other', 'other-organization', 'Other organization', true, false, 'staff'],
        ];
        foreach ($users as [$id, $tenant, $name, $publish, $admin, $role]) {
            $password = ChallengeBinding::encode(random_bytes(18));
            $token = ChallengeBinding::encode(random_bytes(32));
            $database->pdo->prepare('INSERT INTO demo_users (id, tenant_id, display_name, password_hash, can_publish, is_admin, role) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $tenant, $name, password_hash($password, \PASSWORD_DEFAULT), (int) $publish, (int) $admin, $role]);
            $scopes = $publish ? ['alert.read', 'alert.publish', 'approval.read'] : ['alert.read', 'approval.read'];
            $database->pdo->prepare('INSERT INTO demo_grants (id, user_id, tenant_id, token_hash, scopes, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id.'-personal', $id, $tenant, hash('sha256', $token), json_encode($scopes, \JSON_THROW_ON_ERROR), time()]);
            // A password alone cannot add an approval passkey: each person's first one needs this one-time link.
            $link = $links->issue($id, $tenant, 'setup', self::LINK_SECONDS, ['issuedBy' => 'setup']);
            $credentials[$id] = ['password' => $password, 'personal_token' => $token, 'passkey_link' => rtrim($origin, '/').'/account/passkeys/link/'.$link];
        }
        $database->pdo->prepare('INSERT INTO demo_alerts (id, tenant_id, revision, title, body) VALUES (?, ?, ?, ?, ?)')
            ->execute(['riverside-evacuation', 'campaigns', 1, 'Evacuation order: Riverside Road area', "Water is rising fast. If you are between Mill Street and the river bridge, leave now.\nUse the Hill Street route: Riverside Road is closed.\nShelter is open at the Station Lane community centre."]);
        $database->pdo->prepare('INSERT INTO demo_policies (action_id, version, statement, required) VALUES (?, ?, ?, ?)')
            ->execute(['alert.publish', 1, 'I approve issuing the public alert “{{title}}” (revision {{revision}}) on {{application}}. Every resident sees it as soon as I approve.', 1]);
        // The administrator's rule per role: who may issue an alert, and who must verify each exact alert with a passkey.
        foreach (['manager' => 'direct', 'staff' => 'approval', 'reader' => 'denied'] as $role => $mode) {
            $database->pdo->prepare('INSERT INTO demo_role_policies (action_id, role, mode) VALUES (?, ?, ?)')->execute(['alert.publish', $role, $mode]);
        }
        $settings = [
            'schema' => self::SCHEMA, 'origin' => $origin, 'rp_id' => $rpId, 'app_secret' => ChallengeBinding::encode(random_bytes(48)),
            'receipt_key_id' => 'demo-application-1',
            'receipt_secret_key' => ChallengeBinding::encode(sodium_crypto_sign_secretkey($keypair)),
            'receipt_public_key' => ChallengeBinding::encode(sodium_crypto_sign_publickey($keypair)),
            // When that key began signing, and the public keys it replaced, each with the window it signed in.
            'receipt_key_valid_from' => $validFrom,
            'retired_receipt_keys' => [],
        ];
        file_put_contents($directory.'/settings.json', json_encode($settings, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n", \LOCK_EX);
        chmod($directory.'/settings.json', 0600);
        chmod($directory.'/application.sqlite', 0600);

        return ['origin' => $origin, 'accounts' => $credentials, 'receipt_key_id' => $settings['receipt_key_id'], 'receipt_public_key' => $settings['receipt_public_key']];
    }
}
