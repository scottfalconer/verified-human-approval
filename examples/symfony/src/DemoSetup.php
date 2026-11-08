<?php

declare(strict_types=1);

namespace ExactApproval\Demo;

use ExactApproval\Core\ChallengeBinding;
use ExactApproval\Core\Storage\PdoApprovalStore;
use ExactApproval\WebAuthn\Configuration;
use ExactApproval\WebAuthn\PdoCredentialRepository;

final class DemoSetup
{
    /** Returns credentials once. No plaintext account password or token is stored. */
    public static function initialize(string $directory, string $origin = 'http://localhost:8080'): array
    {
        if (is_file($directory.'/settings.json')) throw new \RuntimeException('This demo is already initialized; existing accounts were not replaced.');
        $rpId = parse_url($origin, PHP_URL_HOST);
        if (!\is_string($rpId)) throw new \InvalidArgumentException('Provide a valid origin.');
        new Configuration($rpId, $origin);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Could not create the runtime directory.');
        chmod($directory, 0700);
        foreach (['sessions', 'cache', 'log', 'mcp'] as $subdirectory) {
            if (!is_dir($directory.'/'.$subdirectory)) mkdir($directory.'/'.$subdirectory, 0700, true);
        }
        $database = new DemoDatabase(DemoDatabase::connect($directory.'/application.sqlite'));
        $database->initialize();
        (new PdoApprovalStore($database->pdo))->initialize();
        (new PdoCredentialRepository($database->pdo))->initialize();
        $credentials = [];
        $users = [
            ['editor', 'campaigns', 'Campaign editor', true, true],
            ['reader', 'campaigns', 'Read-only colleague', false, false],
            ['other', 'other-organization', 'Other organization', true, false],
        ];
        foreach ($users as [$id, $tenant, $name, $publish, $admin]) {
            $password = ChallengeBinding::encode(random_bytes(18));
            $token = ChallengeBinding::encode(random_bytes(32));
            $database->pdo->prepare('INSERT INTO demo_users (id, tenant_id, display_name, password_hash, can_publish, is_admin) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id, $tenant, $name, password_hash($password, PASSWORD_DEFAULT), (int) $publish, (int) $admin]);
            $scopes = $publish ? ['announcement.read', 'announcement.publish', 'approval.read'] : ['announcement.read', 'approval.read'];
            $database->pdo->prepare('INSERT INTO demo_grants (id, user_id, tenant_id, token_hash, scopes) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id.'-personal', $id, $tenant, hash('sha256', $token), json_encode($scopes, JSON_THROW_ON_ERROR)]);
            $credentials[$id] = ['password' => $password, 'personal_token' => $token];
        }
        $database->pdo->prepare('INSERT INTO demo_announcements (id, tenant_id, revision, title, body) VALUES (?, ?, ?, ?, ?)')
            ->execute(['summer-campaign', 'campaigns', 1, 'Summer campaign', 'Applications close on June 20. Join your local campaign team.']);
        $database->pdo->prepare('INSERT INTO demo_policies (action_id, version, statement, required) VALUES (?, ?, ?, ?)')
            ->execute(['announcement.publish', 1, 'I approve publishing “{{title}}” revision {{revision}} on {{application}} ({{environment}}). This content will become publicly visible immediately.', 1]);
        $keypair = sodium_crypto_sign_keypair();
        $settings = [
            'origin' => $origin, 'rp_id' => $rpId, 'app_secret' => ChallengeBinding::encode(random_bytes(48)),
            'receipt_key_id' => 'demo-application-1',
            'receipt_secret_key' => ChallengeBinding::encode(sodium_crypto_sign_secretkey($keypair)),
            'receipt_public_key' => ChallengeBinding::encode(sodium_crypto_sign_publickey($keypair)),
        ];
        file_put_contents($directory.'/settings.json', json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
        chmod($directory.'/settings.json', 0600);
        chmod($directory.'/application.sqlite', 0600);

        return ['origin' => $origin, 'accounts' => $credentials, 'receipt_key_id' => $settings['receipt_key_id'], 'receipt_public_key' => $settings['receipt_public_key']];
    }
}
