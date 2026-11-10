<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Page;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\PreparedAction;

/** Removes the published snapshot from public view while preserving its content and working draft. */
final class ArchivePage extends PageAction
{
    public const ID = 'page.archive';

    public function id(): string
    {
        return self::ID;
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $this->exactInput($input, ['page_id']);
        $page = $this->page($input['page_id']);
        if (Page::PUBLISHED !== $page->status) {
            throw new ApprovalException('not_published', 'Only a published page can be archived.');
        }
        $revision = (int) $page->liveRevision + 1;

        return new PreparedAction(
            self::ID, $this->version(), $this->target($actor, $page),
            // Once archived, prepare() refuses the state, so a terminal retry returns the recorded outcome.
            // If the page is later republished, this revision also distinguishes an old archive request key.
            ['status' => Page::ARCHIVED, 'revision' => $revision],
            $this->liveSnapshot($page),
            [
                'title' => 'Archive published page: '.$page->liveTitle,
                'operation' => 'archive',
                'before' => ['title' => $page->liveTitle, 'body' => $page->liveBody, 'status' => Page::PUBLISHED, 'revision' => $page->liveRevision],
                'after' => ['status' => Page::ARCHIVED, 'revision' => $revision],
                'consequence' => 'Visitors stop seeing this page. Its content and working draft remain available to editors.',
            ],
            ['operation' => 'archiving', 'title' => (string) $page->liveTitle],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        if (!$this->sameSnapshot($this->liveSnapshot($this->page($action->target['resource'], true)), $action->preconditions)) {
            throw new ApprovalException('stale_resource', 'The published page changed after the archive request. Request approval again.');
        }
    }

    protected function apply(Actor $actor, PreparedAction $action, array $entities): array
    {
        $page = $entities['page'];
        \assert($page instanceof Page);
        $this->rememberWorkingDraft($page);
        $page->status = Page::ARCHIVED;
        $page->liveRevision = $this->nextRevision($action);
        $page->liveRequestId = $this->approvalId();

        return ['page_id' => $page->id, 'status' => Page::ARCHIVED, 'revision' => $page->liveRevision];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $id = (string) $action->target['resource'];
        $row = $this->stored($id);
        $revision = $this->nextRevision($action);
        if (false === $row || Page::ARCHIVED !== $row['status'] || (int) $row['live_revision'] !== $revision
            || $row['live_title'] !== $action->preconditions['live_title'] || $row['live_body'] !== $action->preconditions['live_body']
            || $row['live_request_id'] !== $this->approvalId()
            || $result !== ['page_id' => $id, 'status' => Page::ARCHIVED, 'revision' => $revision]) {
            throw new ApprovalException('unexpected_effect', 'The archived snapshot does not match the approved action.');
        }
        $this->assertWorkingDraftPreserved($row);
    }
}
