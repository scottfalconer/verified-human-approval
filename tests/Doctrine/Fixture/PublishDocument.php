<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine\Fixture;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Doctrine\Orm\EntityAction;

/** Publishes a document's draft through the ORM: the example the Doctrine guide describes. */
final class PublishDocument extends EntityAction
{
    /** Also changes another document's published copy, which nothing approved. */
    public ?int $alsoPublish = null;
    /** Breaks the postcondition after the flush, so the transaction rolls back after the write. */
    public bool $breakPostcondition = false;
    public int $executions = 0;

    public function id(): string
    {
        return 'document.publish';
    }

    public function version(): string
    {
        return '1';
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $document = $this->document($actor, $input['id'] ?? null);

        return new PreparedAction(
            $this->id(),
            $this->version(),
            ['application' => 'orm-fixture', 'tenant' => $actor->tenantId, 'resource' => $document->id],
            ['title' => $document->title, 'body' => $document->body],
            ['revision' => $document->revision, 'published_revision' => $document->publishedRevision],
            ['title' => 'Publish "'.$document->title.'"', 'body' => $document->body],
            ['title' => $document->title, 'revision' => (string) $document->revision],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $document = $this->document($actor, $action->target['resource']);
        if ($document->revision !== $action->preconditions['revision'] || $document->title !== $action->effect['title']
            || $document->body !== $action->effect['body'] || $document->publishedRevision !== $action->preconditions['published_revision']) {
            throw new ApprovalException('stale_resource', 'The document changed. Request a new review.');
        }
    }

    protected function targets(Actor $actor, PreparedAction $action): array
    {
        return ['document' => [Document::class, $action->target['resource']]];
    }

    protected function apply(Actor $actor, PreparedAction $action, array $entities): array
    {
        ++$this->executions;
        $document = $entities['document'];
        \assert($document instanceof Document);
        $document->publishedTitle = $action->effect['title'];
        $document->publishedBody = $action->effect['body'];
        $document->publishedRevision = $action->preconditions['revision'];
        $document->approvalRequestId = $this->currentApproval()->requestId;
        if (null !== $this->alsoPublish) {
            // Loaded outside the targets, so nothing permits a protected change to it.
            $other = $this->manager()->find(Document::class, $this->alsoPublish);
            \assert($other instanceof Document);
            $other->publishedTitle = 'Never approved';
        }

        return ['document' => $document->id, 'published_revision' => $document->publishedRevision];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $document = $this->document($actor, $action->target['resource']);
        if ($this->breakPostcondition || $document->publishedTitle !== $action->effect['title'] || $document->publishedBody !== $action->effect['body']
            || $document->publishedRevision !== $action->preconditions['revision']) {
            throw new ApprovalException('postcondition_failed', 'The document was not published as approved.');
        }
    }

    private function document(Actor $actor, mixed $id): Document
    {
        $document = \is_int($id) ? $this->current(Document::class, $id) : null;
        if (null === $document || $document->tenant !== $actor->tenantId) {
            throw new ApprovalException('not_found', 'This document is unavailable for this account.');
        }

        return $document;
    }
}
