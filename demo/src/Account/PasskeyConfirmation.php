<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Account;

use Symfony\Component\HttpFoundation\Request;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;

/**
 * A fresh confirmation with an existing passkey, for the steps a password alone must not authorize:
 * adding another passkey, and issuing a passkey link. It lasts two minutes, and only while the passkey
 * that gave it is still active: revoking that passkey, from any browser, ends what it confirmed.
 */
final readonly class PasskeyConfirmation
{
    public const SECONDS = 120;
    private const SESSION_KEY = 'demo.confirmed';

    public function __construct(private AnchoredCredentialRepository $anchored)
    {
    }

    public function remember(Request $request, Actor $actor, string $credentialId): void
    {
        $request->getSession()->set(self::SESSION_KEY, ['user' => $actor->userId, 'tenant' => $actor->tenantId, 'credential' => $credentialId, 'at' => time()]);
    }

    public function forget(Request $request): void
    {
        $request->getSession()->remove(self::SESSION_KEY);
    }

    /** @return array{user: string, tenant: string, credential: string, at: int}|null this person's confirmation, if it is recent and its passkey still active */
    public function recent(Request $request, Actor $actor): ?array
    {
        $confirmed = $request->getSession()->get(self::SESSION_KEY);
        if (!\is_array($confirmed) || $confirmed['user'] !== $actor->userId || $confirmed['tenant'] !== $actor->tenantId
            || !\is_int($confirmed['at']) || $confirmed['at'] + self::SECONDS < time()
            || !\is_string($confirmed['credential']) || !$this->stillActive($actor, $confirmed['credential'])) {
            return null;
        }

        return ['user' => $actor->userId, 'tenant' => $actor->tenantId, 'credential' => $confirmed['credential'], 'at' => $confirmed['at']];
    }

    /** Whether this person's passkey is still active and vouched for by its signed enrollment record. */
    public function stillActive(Actor $actor, string $credentialId): bool
    {
        $credential = $this->anchored->find($credentialId);

        return null !== $credential && !$credential->revoked && $credential->belongsTo($actor);
    }
}
