<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine\Fixture;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Doctrine\Orm\EntityAction;

/** Policy-exempt draft creation still enters the execution guard and cannot accept a publication state. */
final class CreateLegacyDraft extends EntityAction
{
    public function id(): string
    {
        return 'legacy.create_draft';
    }

    public function version(): string
    {
        return '1';
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $keys = array_keys($input);
        sort($keys);
        if (['body', 'id'] !== $keys || !\is_int($input['id']) || !\is_string($input['body']) || '' === trim($input['body'])) {
            throw new ApprovalException('invalid_input', 'Provide only an integer id and a non-empty body. Status is set by this action.');
        }

        return new PreparedAction($this->id(), $this->version(), ['tenant' => $actor->tenantId, 'resource' => $input['id']],
            ['body' => $input['body'], 'status' => 'draft'], [], ['title' => 'Create a private draft'], []);
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        if (null !== $this->current(LegacyPage::class, $action->target['resource'])) {
            throw new ApprovalException('already_exists', 'This draft already exists.');
        }
    }

    protected function targets(Actor $actor, PreparedAction $action): array
    {
        return [];
    }

    protected function apply(Actor $actor, PreparedAction $action, array $entities): array
    {
        $page = new LegacyPage($action->target['resource'], $actor->tenantId, $action->effect['body']);
        $this->persist($page);

        return ['id' => $page->id, 'status' => $page->status, 'revision' => $page->revision];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $page = $this->current(LegacyPage::class, $action->target['resource']);
        if (null === $page || $page->tenant !== $actor->tenantId || 'draft' !== $page->status || 0 !== $page->revision || $page->body !== $action->effect['body']) {
            throw new ApprovalException('unexpected_effect', 'Draft creation must produce only the constrained draft.');
        }
    }
}
