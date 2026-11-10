<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use VerifiedHumanApproval\Demo\DemoDatabase;

/** Carries the validated stored credential's original connection through League's token issuance. */
final class TokenExchangeContext
{
    private bool $active = false;
    private ?string $grantId = null;

    public function __construct(private readonly DemoDatabase $database, private readonly OAuthTransaction $transaction, private readonly OAuthConnections $connections)
    {
    }

    public function begin(): void
    {
        if ($this->active) {
            throw new \LogicException('A token exchange is already in progress.');
        }
        $this->active = true;
    }

    /** Called only after a stored code or refresh token passes its validity checks. */
    public function bind(string $grantId): void
    {
        if (!$this->active) {
            return;
        }
        $this->transaction->assertActive();
        if (null !== $this->grantId && $this->grantId !== $grantId) {
            throw new \LogicException('A token exchange cannot change its original connection.');
        }
        if (null === $this->grantId) {
            // Rejected exchanges undo consumption and partial issuance. Explicit revocations made before
            // accepting a credential (for example when detecting reuse) remain in the outer transaction.
            $this->database->pdo->exec('SAVEPOINT oauth_token_issuance');
            $this->grantId = $grantId;
        }
    }

    public function grantFor(?string $userId, string $clientId): string
    {
        $this->transaction->assertActive();
        if (!$this->active || null === $this->grantId || null === $this->connections->bound($this->grantId, $userId, $clientId)) {
            throw new \LogicException('A new token needs the original, live connection of its code or refresh token.');
        }

        return $this->grantId;
    }

    public function finish(bool $accepted): void
    {
        if (null === $this->grantId) {
            if ($accepted) {
                throw new \LogicException('A successful exchange must have a stored original connection.');
            }

            return;
        }
        if (!$accepted) {
            $this->database->pdo->exec('ROLLBACK TO SAVEPOINT oauth_token_issuance');
        }
        $this->database->pdo->exec('RELEASE SAVEPOINT oauth_token_issuance');
    }

    public function reset(): void
    {
        $this->active = false;
        $this->grantId = null;
    }
}
