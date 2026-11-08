<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Action;

use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Demo\DemoDatabase;

final readonly class PublishAnnouncement implements ActionAdapter
{
    public function __construct(private DemoDatabase $database, private AnnouncementPublisher $publisher) {}
    public const ID = 'announcement.publish';

    public function id(): string { return self::ID; }
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
        if ($row === null || $row['tenant_id'] !== $actor->tenantId) throw new ApprovalException('not_found', 'This alert is unavailable for this account.');

        return new PreparedAction(
            $this->id(), $this->version(),
            ['application' => 'Riverside Council website', 'environment' => 'Local demonstration', 'tenant_id' => $actor->tenantId, 'resource' => $row['id']],
            ['title' => $row['title'], 'body' => $row['body'], 'state' => 'published'],
            ['revision' => (int) $row['revision'], 'published_revision' => $row['published_revision'] === null ? null : (int) $row['published_revision']],
            ['title' => 'Issue the public alert “'.$row['title'].'”', 'consequence' => 'As soon as this is approved, every resident sees the alert on the Riverside Council website.'],
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
