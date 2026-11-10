<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine\Fixture;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Doctrine\Orm\EntityAction;

/** Stable semantic effects; generated revision metadata derives from the frozen starting revision. */
final class PublishLegacyDraft extends EntityAction
{
    public int $executions = 0;

    public function id(): string
    {
        return 'legacy.publish';
    }

    public function version(): string
    {
        return '1';
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        if (['id'] !== array_keys($input) || !\is_int($input['id'])) {
            throw new ApprovalException('invalid_input', 'Provide an integer id.');
        }
        $page = $this->page($actor, $input['id']);

        return new PreparedAction($this->id(), $this->version(), ['tenant' => $actor->tenantId, 'resource' => $page->id],
            ['body' => $page->body, 'status' => 'published'],
            ['revision' => $page->revision, 'status' => $page->status],
            ['title' => 'Publish this page', 'body' => $page->body, 'revision' => $page->revision + 1], []);
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $page = $this->page($actor, $action->target['resource']);
        if ($page->revision !== $action->preconditions['revision'] || $page->status !== $action->preconditions['status'] || $page->body !== $action->effect['body']) {
            throw new ApprovalException('stale_resource', 'The page changed after review.');
        }
    }

    protected function targets(Actor $actor, PreparedAction $action): array
    {
        return ['page' => [LegacyPage::class, $action->target['resource']]];
    }

    protected function apply(Actor $actor, PreparedAction $action, array $entities): array
    {
        ++$this->executions;
        $page = $entities['page'];
        \assert($page instanceof LegacyPage);
        $page->status = 'published';
        $page->revision = $action->preconditions['revision'] + 1;

        return ['id' => $page->id, 'status' => $page->status, 'revision' => $page->revision];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $page = $this->page($actor, $action->target['resource']);
        if ('published' !== $page->status || $action->preconditions['revision'] + 1 !== $page->revision || $page->body !== $action->effect['body']) {
            throw new ApprovalException('unexpected_effect', 'The exact reviewed publication was not written.');
        }
    }

    private function page(Actor $actor, int $id): LegacyPage
    {
        $page = $this->current(LegacyPage::class, $id);
        if (null === $page || $page->tenant !== $actor->tenantId) {
            throw new ApprovalException('not_found', 'This page is unavailable for this account.');
        }

        return $page;
    }
}
