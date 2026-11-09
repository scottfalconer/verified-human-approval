<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Approval;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Authorizer;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Example\Publishing\Action\ArticleAction;
use VerifiedHumanApproval\Example\Publishing\Entity\User;

/**
 * Who may change what visitors see, read again from the database each time it matters: when a request is made, when
 * the person opens the passkey prompt and inside the transaction that executes the approved change. A passkey never
 * adds a permission: an account that is no longer an editor, or an agent connection that was revoked, stops the
 * approval, even one already reviewed.
 *
 * The rows are read FOR SHARE (nothing on SQLite, where the approval holds the only write lock), so on PostgreSQL and
 * MySQL a revocation that commits first is seen, and one that comes later waits for the approval to commit.
 */
final readonly class EditorialPermissions implements Authorizer
{
    /** The agent may read articles. */
    public const READ = 'articles.read';
    /** The agent may write drafts and ask for the four approved changes. */
    public const WRITE = 'articles.write';
    /** The agent may read the outcome of its requests. */
    public const RESULTS = 'approval.read';

    public function __construct(private DbalTransaction $transaction)
    {
    }

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        if (!\in_array($action->actionId, ArticleAction::IDS, true) || ($action->target['tenant_id'] ?? null) !== $actor->tenantId) {
            throw new ApprovalException('permission_denied', 'This account may not do this.');
        }
        $connection = $this->transaction->connection();
        $role = $connection->fetchOne('SELECT role FROM publishing_users WHERE id = ? '.$this->transaction->forShare(), [$actor->userId]);
        if (User::EDITOR !== $role) {
            throw new ApprovalException('permission_denied', 'Only an editor may change what visitors see.');
        }
        if (null === $actor->delegationId) {
            return;
        }
        $agent = $connection->fetchAssociative('SELECT user_id, scopes, revoked_at FROM publishing_agent_connections WHERE id = ? '.$this->transaction->forShare(), [$actor->delegationId]);
        $scopes = \is_array($agent) ? explode(' ', (string) $agent['scopes']) : [];
        if (!\is_array($agent) || $agent['user_id'] !== $actor->userId || null !== $agent['revoked_at']
            || !\in_array(self::WRITE, $scopes, true) || !\in_array(self::RESULTS, $scopes, true)) {
            throw new ApprovalException('delegation_denied', 'The agent connection that asked for this is no longer allowed to.');
        }
    }
}
