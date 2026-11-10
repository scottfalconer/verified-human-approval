<?php

declare(strict_types=1);

namespace App\Approval;

use App\Workflow\PageAction;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Authorizer;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Doctrine\DbalTransaction;

/** Current authority is read inside the operation's transaction, including the original agent delegation. */
final readonly class CurrentAuthority implements Authorizer
{
    public const TENANT = 'editorial';
    public const READ = 'pages.read';
    public const WRITE = 'pages.write';
    public const RESULTS = 'approval.read';

    public function __construct(private DbalTransaction $transaction)
    {
    }

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        if (!\in_array($action->actionId, PageAction::IDS, true) || ($action->target['tenant_id'] ?? null) !== self::TENANT) {
            throw new ApprovalException('permission_denied', 'This action is not allowed on this site.');
        }
        $this->check($actor, true, [self::WRITE, self::RESULTS]);
    }

    public function assertWriter(Actor $actor): void
    {
        $this->check($actor, true, [self::WRITE]);
    }

    public function assertReader(Actor $actor): void
    {
        $this->check($actor, false, [self::READ]);
    }

    /** @param list<string> $scopes */
    private function check(Actor $actor, bool $write, array $scopes): void
    {
        $this->transaction->usePrimary();
        if (self::TENANT !== $actor->tenantId) {
            throw new ApprovalException('permission_denied', 'This account does not belong to this site.');
        }
        $db = $this->transaction->connection();
        $account = $db->fetchAssociative('SELECT role, enabled FROM app_accounts WHERE id = ? '.$this->transaction->forShare(), [$actor->userId]);
        $roles = $write ? ['ROLE_EDITOR', 'ROLE_PUBLISHER'] : ['ROLE_EDITOR', 'ROLE_PUBLISHER', 'ROLE_READER'];
        if (false === $account || !(bool) $account['enabled'] || !\in_array($account['role'], $roles, true)) {
            throw new ApprovalException('permission_denied', $write ? 'An editor or publisher role is required.' : 'This account may not read editorial content.');
        }
        if (null === $actor->delegationId) {
            return;
        }
        $grant = $db->fetchAssociative('SELECT user_id, scopes, expires_at, revoked_at FROM app_connections WHERE id = ? '.$this->transaction->forShare(), [$actor->delegationId]);
        $granted = false === $grant ? [] : preg_split('/\s+/', trim((string) $grant['scopes']));
        if (false === $grant || $grant['user_id'] !== $actor->userId || null !== $grant['revoked_at'] || (int) $grant['expires_at'] <= time()
            || [] !== array_diff($scopes, $granted ?: [])) {
            throw new ApprovalException('delegation_denied', 'The original agent connection is revoked, expired, or lacks the required scope.');
        }
    }
}
