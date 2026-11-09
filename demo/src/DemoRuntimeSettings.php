<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo;

use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Serves the runtime's settings.json to the container as environment variables, read when a service
 * first needs one. The compiled container then names no runtime, so one compiled container serves
 * every runtime of an environment, and no secret is written into a compiled file.
 */
final readonly class DemoRuntimeSettings implements EnvVarLoaderInterface
{
    public function __construct(private KernelInterface $kernel)
    {
    }

    /** @return array<string, string> */
    public function loadEnvVars(): array
    {
        if (!$this->kernel instanceof DemoKernel) {
            throw new \LogicException('The demo runtime settings come from the demo kernel.');
        }
        $directory = $this->kernel->runtimeDirectory();
        $settings = $this->kernel->settings();
        $site = $this->kernel->getProjectDir();

        return [
            'DEMO_RUNTIME_DIRECTORY' => $directory,
            'DEMO_RUNTIME_SECRET' => (string) $settings['app_secret'],
            // The MCP request state gets its own key, derived from the application secret.
            'DEMO_RUNTIME_REQUEST_STATE_KEY' => hash_hmac('sha256', 'mcp-request-state', (string) $settings['app_secret']),
            'DEMO_RUNTIME_ORIGIN' => rtrim((string) $settings['origin'], '/'),
            'DEMO_RUNTIME_RP_ID' => (string) $settings['rp_id'],
            'DEMO_RUNTIME_TRUSTED_HOST' => '^'.preg_quote((string) $settings['rp_id'], '/').'$',
            'DEMO_RUNTIME_RECEIPT_KEY_ID' => (string) $settings['receipt_key_id'],
            'DEMO_RUNTIME_RECEIPT_SECRET_KEY' => (string) $settings['receipt_secret_key'],
            'DEMO_RUNTIME_RECEIPT_PUBLIC_KEY' => (string) $settings['receipt_public_key'],
            // The OAuth server's token signing key pair and its code encryption key.
            'DEMO_RUNTIME_OAUTH_PRIVATE_KEY' => (string) $settings['oauth_private_key'],
            'DEMO_RUNTIME_OAUTH_PUBLIC_KEY' => (string) $settings['oauth_public_key'],
            'DEMO_RUNTIME_OAUTH_ENCRYPTION_KEY' => (string) $settings['oauth_encryption_key'],
            'DEMO_RUNTIME_APPROVAL_ATTEMPTS' => (string) ($settings['approval_attempts_per_minute'] ?? 30),
            'DEMO_RUNTIME_ENROLLMENT_ATTEMPTS' => (string) ($settings['enrollment_attempts_per_minute'] ?? 10),
            'DEMO_RUNTIME_REGISTRATION_ATTEMPTS' => (string) ($settings['registration_attempts_per_minute'] ?? 10),
            // The public alerts page's layout files, part of what a person approves.
            'DEMO_RUNTIME_LAYOUT_FILES' => json_encode($settings['layout_files'] ?? [$site.'/templates/public/_page.html.twig', $site.'/assets/riverside.css'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        ];
    }
}
