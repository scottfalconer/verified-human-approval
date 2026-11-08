<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Action;

use ExactApproval\Core\ActionAdapter;
use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\PreparedAction;
use ExactApproval\Demo\DemoDatabase;

final readonly class PublishAnnouncement implements ActionAdapter
{
    public function __construct(private DemoDatabase $database, private AnnouncementPublisher $publisher) {}
    public function id(): string { return 'announcement.publish'; }
    /** The public page's template and stylesheet are part of what the person approves: changing either after review invalidates the approval. */
    public function version(): string
    {
        $site = \dirname(__DIR__, 2);

        return '2:'.substr(hash('sha256', (string) file_get_contents($site.'/templates/public/_page.html.twig').(string) file_get_contents($site.'/assets/riverside.css')), 0, 16);
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        if (array_keys($input) !== ['announcement_id'] || !\is_string($input['announcement_id']) || \strlen($input['announcement_id']) > 128) {
            throw new ApprovalException('invalid_input', 'Provide exactly one announcement_id.');
        }
        $row = $this->database->announcement($input['announcement_id']);
        if ($row === null || $row['tenant_id'] !== $actor->tenantId) throw new ApprovalException('not_found', 'Announcement unavailable for this account.');

        return new PreparedAction(
            $this->id(), $this->version(),
            ['application' => 'Riverside Council website', 'environment' => 'Local demonstration', 'tenant_id' => $actor->tenantId, 'resource' => $row['id']],
            ['title' => $row['title'], 'body' => $row['body'], 'state' => 'published'],
            ['revision' => (int) $row['revision'], 'published_revision' => $row['published_revision'] === null ? null : (int) $row['published_revision']],
            ['title' => 'Publish “'.$row['title'].'”', 'consequence' => 'The approved title and body will become the published announcement immediately.'],
            ['title' => $row['title'], 'revision' => (string) $row['revision'], 'application' => 'Riverside Council website', 'environment' => 'Local demonstration'],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $row = $this->database->announcement($action->target['resource']);
        if ($row === null || $row['tenant_id'] !== $actor->tenantId || (int) $row['revision'] !== $action->preconditions['revision']
            || $row['title'] !== $action->effect['title'] || $row['body'] !== $action->effect['body']
            || ($row['published_revision'] === null ? null : (int) $row['published_revision']) !== $action->preconditions['published_revision']) {
            throw new ApprovalException('stale_resource', 'The announcement changed after this request was created. Review a new proposal.');
        }
    }

    public function execute(Actor $actor, PreparedAction $action): array { return $this->publisher->publish($actor, $action); }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $row = $this->database->announcement($action->target['resource']);
        if ($row === null || $row['published_title'] !== $action->effect['title'] || $row['published_body'] !== $action->effect['body']
            || (int) $row['published_revision'] !== $action->preconditions['revision']) {
            throw new ApprovalException('unexpected_effect', 'Publication did not preserve the approved content; the transaction was rolled back.');
        }
    }
}
