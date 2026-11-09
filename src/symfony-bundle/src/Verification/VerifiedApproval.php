<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Verification;

/**
 * What a page may show about a change a person approved with their passkey. It carries nothing that identifies
 * an account, tenant, agent connection or passkey: only what was approved, when, and the receipt the site signed
 * as the change committed (digests only).
 */
final readonly class VerifiedApproval
{
    public function __construct(
        public string $requestId,
        public string $application,
        public string $title,
        public string $statement,
        public \DateTimeImmutable $approvedAt,
        public \DateTimeImmutable $committedAt,
        public string $keyId,
        public string $manifest,
        public string $signature,
    ) {
    }
}
