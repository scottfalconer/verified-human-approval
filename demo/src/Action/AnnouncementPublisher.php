<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Action;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Demo\DemoDatabase;

/** The one supported publication boundary. Every application entry point uses it. */
final readonly class AnnouncementPublisher
{
    public function __construct(private DemoDatabase $database, private ExecutionGuard $guard)
    {
    }

    public function publish(Actor $actor, PreparedAction $action): array
    {
        $this->guard->assertExecuting($actor, $action);
        $query = $this->database->pdo->prepare(<<<'SQL'
UPDATE demo_announcements
SET published_revision = revision, published_title = ?, published_body = ?, mutation_count = mutation_count + 1
WHERE id = ? AND tenant_id = ? AND revision = ? AND title = ? AND body = ? AND published_revision IS ?
SQL);
        $query->execute([
            $action->effect['title'], $action->effect['body'], $action->target['resource'], $actor->tenantId,
            $action->preconditions['revision'], $action->effect['title'], $action->effect['body'], $action->preconditions['published_revision'],
        ]);
        if (1 !== $query->rowCount()) {
            throw new ApprovalException('stale_resource', 'The announcement changed. Nothing was published.');
        }

        return ['disposition' => 'published', 'announcement_id' => $action->target['resource'], 'source_revision' => $action->preconditions['revision']];
    }
}
