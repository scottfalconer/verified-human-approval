<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\WebAuthn;

/** Trusted application configuration. Never build this from a Host header or tool argument. */
final readonly class Configuration
{
    public string $host;

    public function __construct(
        public string $rpId,
        public string $origin,
        public int $timeoutMs = 60000,
    ) {
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('The WebAuthn origin must contain only scheme, host and optional port.');
        }
        $host = $parts['host'];
        if ($host !== strtolower($host) || $rpId !== strtolower($rpId)
            || !preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $rpId)
            || str_contains($rpId, '..')
            || ($host !== $rpId && !str_ends_with($host, '.'.$rpId))
        ) {
            throw new \InvalidArgumentException('The RP ID must be the configured origin host or its domain suffix.');
        }
        if ($parts['scheme'] !== 'https' && !($parts['scheme'] === 'http' && $host === 'localhost' && $rpId === 'localhost')) {
            throw new \InvalidArgumentException('WebAuthn requires HTTPS; HTTP localhost is allowed for local development.');
        }
        if ($timeoutMs < 1000 || $timeoutMs > 600000) {
            throw new \InvalidArgumentException('The ceremony timeout must be between one second and ten minutes.');
        }
        $this->host = $host;
    }
}
