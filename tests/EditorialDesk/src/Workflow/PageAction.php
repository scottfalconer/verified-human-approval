<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Page;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Doctrine\Orm\EntityAction;
use VerifiedHumanApproval\Doctrine\Orm\ProtectedEntityListener;

/** One transport-independent application boundary, executed by the bundle inside its root transaction. */
abstract class PageAction extends EntityAction
{
    public const IDS = [PublishPage::ID, EditLivePage::ID, ArchivePage::ID, DeletePage::ID];
    public const APPLICATION = 'Editorial Desk';

    private readonly Connection $database;

    /** @var array{title: string, body: string, revision: int}|null */
    private ?array $workingDraftAtExecution = null;

    public function __construct(ManagerRegistry $doctrine, ExecutionGuard $guard, DbalTransaction $transaction, ProtectedEntityListener $protection)
    {
        parent::__construct($doctrine, $guard, $transaction, $protection);
        $this->database = $transaction->connection();
    }

    public function version(): string
    {
        return '2';
    }

    /** @return array<string, mixed> */
    protected function target(Actor $actor, Page $page): array
    {
        return ['application' => self::APPLICATION, 'tenant_id' => $actor->tenantId, 'resource' => $page->id];
    }

    protected function page(mixed $id, bool $existingProposal = false): Page
    {
        $page = \is_string($id) && 1 === preg_match('/^[a-f0-9]{32}$/D', $id) ? $this->current(Page::class, $id) : null;

        return $page ?? throw new ApprovalException($existingProposal ? 'stale_resource' : 'not_found', 'The page does not exist.');
    }

    /** @param array<string, mixed> $input @param list<string> $fields */
    protected function exactInput(array $input, array $fields): void
    {
        $given = array_keys($input);
        sort($given);
        sort($fields);
        if ($given !== $fields) {
            throw new ApprovalException('invalid_input', 'Give exactly these fields: '.implode(', ', $fields).'.');
        }
    }

    /** @return array<string, mixed> */
    protected function liveSnapshot(Page $page): array
    {
        return [
            'status' => $page->status, 'live_title' => $page->liveTitle, 'live_body' => $page->liveBody,
            'live_revision' => $page->liveRevision, 'live_request_id' => $page->liveRequestId,
        ];
    }

    /** @return array<string, mixed> */
    protected function fullSnapshot(Page $page): array
    {
        return $this->liveSnapshot($page) + [
            'draft_title' => $page->draftTitle, 'draft_body' => $page->draftBody, 'draft_revision' => $page->draftRevision,
        ];
    }

    /** @param array<string, mixed> $actual @param array<string, mixed> $expected */
    protected function sameSnapshot(array $actual, array $expected): bool
    {
        return CanonicalJson::encode($actual) === CanonicalJson::encode($expected);
    }

    protected function targets(Actor $actor, PreparedAction $action): array
    {
        return ['page' => [Page::class, $action->target['resource']]];
    }

    protected function approvalId(): string
    {
        return $this->currentApproval()->requestId;
    }

    /**
     * Revision bookkeeping derives from the signed starting revision. Keep it out of effect: the bundle
     * re-prepares keyed retries after execution and compares semantic effects, when today's revision has advanced.
     */
    protected function nextRevision(PreparedAction $action): int
    {
        return (int) ($action->preconditions['live_revision'] ?? 0) + 1;
    }

    /** A live operation may run while an editor has saved a newer working draft; it must preserve that draft. */
    protected function rememberWorkingDraft(Page $page): void
    {
        $this->workingDraftAtExecution = ['title' => $page->draftTitle, 'body' => $page->draftBody, 'revision' => $page->draftRevision];
    }

    /** @param array<string, mixed> $row */
    protected function assertWorkingDraftPreserved(array $row): void
    {
        if (null === $this->workingDraftAtExecution || $row['draft_title'] !== $this->workingDraftAtExecution['title']
            || $row['draft_body'] !== $this->workingDraftAtExecution['body']
            || (int) $row['draft_revision'] !== $this->workingDraftAtExecution['revision']) {
            throw new ApprovalException('unexpected_effect', 'The live operation unexpectedly changed the saved working draft.');
        }
    }

    /** @return array<string, mixed>|false */
    protected function stored(string $id): array|false
    {
        return $this->database->fetchAssociative('SELECT * FROM app_pages WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $result */
    protected function assertLiveResult(PreparedAction $action, array $result): void
    {
        $id = (string) $action->target['resource'];
        $row = $this->stored($id);
        $revision = $this->nextRevision($action);
        if (false === $row || Page::PUBLISHED !== $row['status'] || $row['live_title'] !== $action->effect['title']
            || $row['live_body'] !== $action->effect['body'] || (int) $row['live_revision'] !== $revision
            || $row['live_request_id'] !== $this->approvalId()
            || $result !== ['page_id' => $id, 'status' => Page::PUBLISHED, 'revision' => $revision]) {
            throw new ApprovalException('unexpected_effect', 'The written public snapshot differs from the approved proposal.');
        }
        $this->assertWorkingDraftPreserved($row);
    }
}
