<?php

declare(strict_types=1);

namespace App\Approval;

use App\Workflow\PageAction;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalPolicy;
use VerifiedHumanApproval\Core\PolicyProvider;
use VerifiedHumanApproval\Doctrine\DbalTransaction;

/** Explicit role policy: editors need a passkey; the publisher role is an intentional exemption. */
final readonly class EditorialPolicy implements PolicyProvider
{
    public function __construct(private DbalTransaction $transaction)
    {
    }

    public function get(string $actionId, ?Actor $actor = null): ApprovalPolicy
    {
        if (!\in_array($actionId, PageAction::IDS, true)) {
            throw new ApprovalException('unknown_action', 'There is no policy for this action.');
        }
        $role = null === $actor ? false : $this->transaction->connection()->fetchOne(
            'SELECT role FROM app_accounts WHERE id = ? '.$this->transaction->forShare(), [$actor->userId],
        );

        return new ApprovalPolicy(
            $actionId,
            'editor-policy-v1',
            'I approve {{operation}} “{{title}}” on Editorial Desk, exactly as shown in this review.',
            600,
            required: 'ROLE_PUBLISHER' !== $role,
        );
    }
}
