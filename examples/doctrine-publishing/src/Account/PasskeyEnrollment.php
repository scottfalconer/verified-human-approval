<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Account;

use Doctrine\ORM\EntityManagerInterface;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Example\Publishing\Entity\PasskeyLink;
use VerifiedHumanApproval\Example\Publishing\Security\ApprovalActors;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/**
 * How a person gets the passkey that approves: a one-time link, opened while they are signed in. A password alone,
 * or an agent's token, never adds one. Each link and each passkey is recorded in the signed audit log, in the same
 * transaction as the row it vouches for, and only a passkey with that signed record approves anything.
 */
final readonly class PasskeyEnrollment
{
    public const LINK_SECONDS = 7 * 86400;

    public function __construct(
        private DbalTransaction $transaction,
        private EntityManagerInterface $entities,
        private WebAuthnVerifier $webauthn,
        private AnchoredCredentialRepository $passkeys,
        private AuditLog $audit,
    ) {
    }

    /** Issues a link for one person's passkey, and returns its token: shown once, stored only as a hash. */
    public function issueLink(string $userId, string $issuedBy): string
    {
        $token = ChallengeBinding::encode(random_bytes(32));
        $expires = time() + self::LINK_SECONDS;
        $this->transaction->run(function () use ($token, $userId, $issuedBy, $expires): void {
            // Nothing in memory may claim a link that never committed.
            $this->transaction->onRollback($this->entities->clear(...));
            $this->entities->persist(new PasskeyLink(hash('sha256', $token), $userId, $expires));
            $this->entities->flush();
            $this->audit->append(ApprovalActors::TENANT, 'passkey_link.issued', $userId, ['issuedBy' => $issuedBy, 'expiresAt' => $expires]);
        });

        return $token;
    }

    /** The link's hash, if it is this person's and can still be used. */
    public function openLink(Actor $actor, string $token): ?string
    {
        $link = $this->entities->find(PasskeyLink::class, hash('sha256', $token));

        return null !== $link && $link->opensFor($actor->userId, time()) ? $link->tokenHash() : null;
    }

    /**
     * Registers the passkey, uses up the link and appends the signed enrollment: all of it commits, or none does.
     *
     * @param array<string, mixed> $options  the registration options this server saved for the ceremony
     * @param array<string, mixed> $response the browser's registration response
     */
    public function complete(Actor $actor, array $options, array $response, string $linkHash): Credential
    {
        return $this->transaction->run(function () use ($actor, $options, $response, $linkHash): Credential {
            $credential = $this->webauthn->register($actor, $options, $response, 'Passkey');
            $used = $this->transaction->connection()->executeStatement(
                'UPDATE publishing_passkey_links SET used_at = ? WHERE token_hash = ? AND user_id = ? AND used_at IS NULL AND expires_at >= ?',
                [time(), $linkHash, $actor->userId, time()],
            );
            if (1 !== $used) {
                throw new ApprovalException('link_used', 'This passkey link was used already, or it expired. Ask for a new one.');
            }
            $this->passkeys->anchor($credential, ['method' => 'one-time link', 'authorizedBy' => 'link '.substr($linkHash, 0, 12)]);

            return $credential;
        });
    }
}
