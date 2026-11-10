<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Page;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\PreparedAction;

/** Applies an immediate live correction after approval, leaving a separately saved working draft intact. */
final class EditLivePage extends PageAction
{
    public const ID = 'page.edit_live';

    public function id(): string
    {
        return self::ID;
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $this->exactInput($input, ['page_id', 'title', 'body']);
        $page = $this->page($input['page_id']);
        if (Page::PUBLISHED !== $page->status) {
            throw new ApprovalException('not_published', 'A direct live edit requires a published page. Publish its working draft first.');
        }
        $title = Text::title($input['title']);
        $body = Text::body($input['body']);
        $revision = (int) $page->liveRevision + 1;

        return new PreparedAction(
            self::ID, $this->version(), $this->target($actor, $page),
            ['title' => $title, 'body' => $body, 'status' => Page::PUBLISHED],
            $this->liveSnapshot($page),
            [
                'title' => 'Change published page: '.$page->liveTitle,
                'operation' => 'edit_live',
                'before' => ['title' => $page->liveTitle, 'body' => $page->liveBody, 'status' => Page::PUBLISHED, 'revision' => $page->liveRevision],
                'after' => ['title' => $title, 'body' => $body, 'status' => Page::PUBLISHED, 'revision' => $revision],
                'consequence' => 'The reviewed text replaces the public snapshot. The separately saved working draft is preserved.',
            ],
            ['operation' => 'replacing the published text of', 'title' => (string) $page->liveTitle],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        if (!$this->sameSnapshot($this->liveSnapshot($this->page($action->target['resource'], true)), $action->preconditions)) {
            throw new ApprovalException('stale_resource', 'The published page changed. Review the proposed edit against its current revision.');
        }
    }

    protected function apply(Actor $actor, PreparedAction $action, array $entities): array
    {
        $page = $entities['page'];
        \assert($page instanceof Page);
        $this->rememberWorkingDraft($page);
        $page->liveTitle = $action->effect['title'];
        $page->liveBody = $action->effect['body'];
        $page->liveRevision = $this->nextRevision($action);
        $page->liveRequestId = $this->approvalId();

        return ['page_id' => $page->id, 'status' => Page::PUBLISHED, 'revision' => $page->liveRevision];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $this->assertLiveResult($action, $result);
    }
}
