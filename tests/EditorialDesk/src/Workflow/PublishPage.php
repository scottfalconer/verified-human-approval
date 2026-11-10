<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Page;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\PreparedAction;

/** Promotes a saved working draft, including a forward draft of an already-published item. */
final class PublishPage extends PageAction
{
    public const ID = 'page.publish';

    public function id(): string
    {
        return self::ID;
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $this->exactInput($input, ['page_id']);
        $page = $this->page($input['page_id']);
        $revision = ($page->liveRevision ?? 0) + 1;

        return new PreparedAction(
            self::ID, $this->version(), $this->target($actor, $page),
            ['title' => $page->draftTitle, 'body' => $page->draftBody, 'status' => Page::PUBLISHED],
            $this->fullSnapshot($page),
            [
                'title' => 'Publish working draft: '.$page->draftTitle,
                'operation' => 'publish',
                'before' => ['title' => $page->liveTitle, 'body' => $page->liveBody, 'status' => $page->status ?? Page::DRAFT, 'revision' => $page->liveRevision],
                'after' => ['title' => $page->draftTitle, 'body' => $page->draftBody, 'status' => Page::PUBLISHED, 'revision' => $revision],
                'consequence' => 'The reviewed working draft becomes public. Both the working draft and the current public revision must remain unchanged until approval.',
            ],
            ['operation' => 'publishing the saved working draft of', 'title' => $page->draftTitle],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        if (!$this->sameSnapshot($this->fullSnapshot($this->page($action->target['resource'], true)), $action->preconditions)) {
            throw new ApprovalException('stale_resource', 'The working draft or published revision changed. Request approval for the current version.');
        }
    }

    protected function apply(Actor $actor, PreparedAction $action, array $entities): array
    {
        $page = $entities['page'];
        \assert($page instanceof Page);
        $this->rememberWorkingDraft($page);
        $page->status = Page::PUBLISHED;
        $page->liveTitle = $action->effect['title'];
        $page->liveBody = $action->effect['body'];
        $page->liveRevision = $this->nextRevision($action);
        $page->liveRequestId = $this->approvalId();

        return ['page_id' => $page->id, 'status' => Page::PUBLISHED, 'revision' => $page->liveRevision];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $this->assertLiveResult($action, $result);
        $row = $this->stored((string) $action->target['resource']);
        if (false === $row || $row['draft_title'] !== $action->preconditions['draft_title']
            || $row['draft_body'] !== $action->preconditions['draft_body'] || (int) $row['draft_revision'] !== $action->preconditions['draft_revision']) {
            throw new ApprovalException('unexpected_effect', 'Publishing unexpectedly changed the saved working draft.');
        }
    }
}
