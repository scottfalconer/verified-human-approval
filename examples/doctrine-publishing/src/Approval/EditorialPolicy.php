<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Approval;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalPolicy;
use VerifiedHumanApproval\Core\PolicyProvider;
use VerifiedHumanApproval\Example\Publishing\Action\ArchiveArticles;
use VerifiedHumanApproval\Example\Publishing\Action\DeleteArticle;
use VerifiedHumanApproval\Example\Publishing\Action\EditLiveArticle;
use VerifiedHumanApproval\Example\Publishing\Action\PublishArticle;

/**
 * Every change to what visitors see needs the person's passkey, whoever asks and from wherever: no role skips it.
 * The statement is what the person approves, filled in from the frozen proposal. Change the version when a
 * statement's meaning changes: a request made under the old one can no longer be approved.
 */
final class EditorialPolicy implements PolicyProvider
{
    /** How long a request waits for the person's passkey. */
    public const LIFETIME_SECONDS = 600;

    private const STATEMENTS = [
        PublishArticle::ID => 'I approve publishing “{{title}}” on the {{application}} as live revision {{revision}}: visitors see this exact text as soon as I approve.',
        EditLiveArticle::ID => 'I approve replacing the live text of “{{current_title}}” on the {{application}}, now revision {{from}}, with the text I reviewed, titled “{{title}}”, as revision {{to}}.',
        ArchiveArticles::ID => 'I approve archiving {{articles}} on the {{application}}, all of them or none: visitors stop seeing each as soon as I approve.',
        DeleteArticle::ID => 'I approve permanently deleting “{{title}}” from the {{application}}: {{what}}. It cannot be restored.',
    ];

    public function get(string $actionId, ?Actor $actor = null): ApprovalPolicy
    {
        $statement = self::STATEMENTS[$actionId] ?? throw new ApprovalException('unknown_action', 'No approval policy exists for this action.');

        return new ApprovalPolicy($actionId, '1', $statement, self::LIFETIME_SECONDS, required: true);
    }
}
