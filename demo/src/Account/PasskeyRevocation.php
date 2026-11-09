<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Account;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;

/**
 * Revokes passkeys. The revocation and its signed security-log entry commit in one transaction, so a
 * failure to sign or record the entry leaves the passkey as it was.
 */
final readonly class PasskeyRevocation
{
    public function __construct(
        private DemoDatabase $database,
        private PdoCredentialRepository $credentials,
        private AnchoredCredentialRepository $anchored,
    ) {
    }

    /** Revokes one of the person's own passkeys. Returns whether one was revoked. */
    public function byOwner(Actor $owner, string $id): bool
    {
        return $this->database->transactional(fn (): bool => $this->anchored->revoke($owner, $id, ['by' => $owner->userId, 'reason' => 'revoked by its owner']));
    }

    /** Revokes a passkey in the administrator's own organization. Returns whether one was revoked. */
    public function byAdministrator(Actor $administrator, string $id): bool
    {
        return $this->database->transactional(function () use ($administrator, $id): bool {
            $credential = $this->credentials->find($id);
            if (null === $credential || $credential->tenantId !== $administrator->tenantId) {
                return false;
            }

            return $this->anchored->revoke(new Actor($credential->userId, $credential->tenantId), $id, ['by' => $administrator->userId, 'reason' => 'revoked by an administrator']);
        });
    }
}
