<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Account;

use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\OAuth\OAuthConnections;
use VerifiedHumanApproval\Demo\OAuth\OAuthTransaction;

/** Ends agent connections. Each revocation is in the signed security log, in the same transaction. */
final readonly class AgentConnections
{
    public function __construct(private DemoDatabase $database, private AuditLog $audit, private OAuthConnections $oauth, private OAuthTransaction $transaction)
    {
    }

    /**
     * Revokes a connection in this tenant, or only one of this person's own when an owner is given.
     * Its access and refresh tokens stop working at once. Returns whether a connection was revoked.
     */
    public function revoke(string $grantId, string $tenantId, string $by, ?string $ownerId = null): bool
    {
        return $this->transaction->run(function () use ($grantId, $tenantId, $by, $ownerId): bool {
            $grant = $this->database->grant($grantId);
            if (null === $grant || !$this->database->revokeGrant($grantId, $tenantId, $ownerId)) {
                return false;
            }
            // An OAuth connection's codes and tokens end with it, so the client cannot refresh its way back.
            if (null === $grant['token_hash'] && null !== $grant['client_id']) {
                $this->oauth->revokeTokensForGrant($grantId);
            }
            $this->audit->append($tenantId, 'connection.revoked', $grantId, ['userId' => $grant['user_id'], 'by' => $by]);

            return true;
        });
    }
}
