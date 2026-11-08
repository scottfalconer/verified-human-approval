<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Account;

use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Demo\DemoDatabase;

/**
 * One-time links for a person's first approval passkey: a password alone cannot add one. Each link is
 * stored only as a hash and recorded in the signed security log, in the same transaction.
 */
final readonly class PasskeyLinks
{
    public function __construct(private DemoDatabase $database, private AuditLog $audit)
    {
    }

    /**
     * @param array<string, mixed> $record what the security log says about who issued it
     *
     * @return string the link's token, shown once
     */
    public function issue(string $userId, string $tenantId, string $issuedBy, int $lifetime, array $record): string
    {
        return $this->database->transactional(function () use ($userId, $tenantId, $issuedBy, $lifetime, $record): string {
            $token = $this->database->issuePasskeyLink($userId, $tenantId, $issuedBy, $lifetime);
            $this->audit->append($tenantId, 'passkey_link.issued', $userId, $record + ['expiresAt' => time() + $lifetime]);

            return $token;
        });
    }
}
