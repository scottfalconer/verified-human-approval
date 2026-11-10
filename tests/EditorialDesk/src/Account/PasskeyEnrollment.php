<?php

declare(strict_types=1);

namespace App\Account;

use App\Security\ApprovalActors;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/** A setup operator's one-use link authorizes one registration, independently of the account password. */
final readonly class PasskeyEnrollment
{
    public function __construct(
        private DbalTransaction $transaction,
        private WebAuthnVerifier $webauthn,
        private AnchoredCredentialRepository $credentials,
        private AuditLog $audit,
    ) {
    }

    public function issueLink(string $userId, string $issuedBy): string
    {
        $token = ChallengeBinding::encode(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = time() + 86400;
        $this->transaction->run(function () use ($userId, $issuedBy, $hash, $expires): void {
            $db = $this->transaction->connection();
            if (1 !== (int) $db->fetchOne('SELECT enabled FROM app_accounts WHERE id = ? '.$this->transaction->forShare(), [$userId])) {
                throw new ApprovalException('account_unavailable', 'A registration link needs an enabled account.');
            }
            $db->insert('app_enrollment_links', ['token_hash' => $hash, 'user_id' => $userId, 'expires_at' => $expires, 'used_at' => null]);
            $this->audit->append(ApprovalActors::TENANT, 'enrollment_link.issued', $hash, ['userId' => $userId, 'issuedBy' => $issuedBy, 'expiresAt' => $expires]);
        });

        return $token;
    }

    public function openLink(Actor $actor, string $token): ?string
    {
        $this->assertBrowserActor($actor);
        if (1 !== preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $row = $this->transaction->connection()->fetchAssociative('SELECT user_id, expires_at, used_at FROM app_enrollment_links WHERE token_hash = ?', [$hash]);

        return false !== $row && $row['user_id'] === $actor->userId && null === $row['used_at'] && (int) $row['expires_at'] > time() ? $hash : null;
    }

    public function complete(Actor $actor, array $options, array $response, string $linkHash): Credential
    {
        $this->assertBrowserActor($actor);

        return $this->transaction->run(function () use ($actor, $options, $response, $linkHash): Credential {
            $db = $this->transaction->connection();
            if (1 !== (int) $db->fetchOne('SELECT enabled FROM app_accounts WHERE id = ? '.$this->transaction->forShare(), [$actor->userId])) {
                throw new ApprovalException('account_unavailable', 'The account is no longer enabled.');
            }
            $used = $db->executeStatement('UPDATE app_enrollment_links SET used_at = ? WHERE token_hash = ? AND user_id = ? AND used_at IS NULL AND expires_at > ?', [time(), $linkHash, $actor->userId, time()]);
            if (1 !== $used) {
                throw new ApprovalException('link_used', 'This registration link expired or was already used.');
            }
            $credential = $this->webauthn->register($actor, $options, $response, 'Editorial passkey');
            $this->credentials->anchor($credential, ['method' => 'operator-issued one-time link', 'authorizedBy' => $linkHash]);

            return $credential;
        });
    }

    private function assertBrowserActor(Actor $actor): void
    {
        if (ApprovalActors::TENANT !== $actor->tenantId || null !== $actor->delegationId) {
            throw new ApprovalException('interactive_required', 'Register from your own signed-in account.');
        }
    }
}
