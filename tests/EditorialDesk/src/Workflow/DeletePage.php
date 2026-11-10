<?php

declare(strict_types=1);

namespace App\Workflow;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\PreparedAction;

/** Deletes exactly the reviewed draft and published snapshot; approval records are retained independently. */
final class DeletePage extends PageAction
{
    public const ID = 'page.delete';

    public function id(): string
    {
        return self::ID;
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $this->exactInput($input, ['page_id']);
        $page = $this->page($input['page_id']);
        $title = $page->liveTitle ?? $page->draftTitle;

        return new PreparedAction(
            self::ID, $this->version(), $this->target($actor, $page),
            ['deleted' => true],
            $this->fullSnapshot($page),
            [
                'title' => 'Permanently delete: '.$title,
                'operation' => 'delete',
                'before' => $page->toArray(),
                'after' => ['status' => 'deleted'],
                'consequence' => 'The working draft and any published or archived snapshot are permanently deleted. Their approval history is retained.',
            ],
            ['operation' => 'permanently deleting', 'title' => $title],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        if (!$this->sameSnapshot($this->fullSnapshot($this->page($action->target['resource'], true)), $action->preconditions)) {
            throw new ApprovalException('stale_resource', 'The draft or published snapshot changed after the deletion request. Nothing was deleted.');
        }
    }

    protected function apply(Actor $actor, PreparedAction $action, array $entities): array
    {
        $this->manager()->remove($entities['page']);

        return ['page_id' => $action->target['resource'], 'status' => 'deleted', 'revision' => null];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $id = (string) $action->target['resource'];
        if (false !== $this->stored($id) || $result !== ['page_id' => $id, 'status' => 'deleted', 'revision' => null]) {
            throw new ApprovalException('unexpected_effect', 'The reviewed page was not deleted.');
        }
    }
}
