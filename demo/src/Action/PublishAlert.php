<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Action;

use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Demo\DemoDatabase;

final readonly class PublishAlert implements ActionAdapter
{
    /** @param list<string>|null $layoutFiles the public page's template and stylesheet; the demo's own when null */
    public function __construct(private DemoDatabase $database, private AlertPublisher $publisher, private ?array $layoutFiles = null)
    {
    }
    public const ID = 'alert.publish';

    public function id(): string
    {
        return self::ID;
    }

    /** The public page's template and stylesheet are part of what the person approves: changing either after review invalidates the approval. */
    public function version(): string
    {
        $site = \dirname(__DIR__, 2);
        $content = '';
        foreach ($this->layoutFiles ?? [$site.'/templates/public/_page.html.twig', $site.'/assets/riverside.css'] as $file) {
            $content .= (string) file_get_contents($file);
        }

        return '2:'.substr(hash('sha256', $content), 0, 16);
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        if (array_keys($input) !== ['alert_id'] || !\is_string($input['alert_id']) || \strlen($input['alert_id']) > 128) {
            throw new ApprovalException('invalid_input', 'Provide exactly one alert_id.');
        }
        $row = $this->database->alert($input['alert_id']);
        if (null === $row || $row['tenant_id'] !== $actor->tenantId) {
            throw new ApprovalException('not_found', 'This alert is unavailable for this account.');
        }

        return new PreparedAction(
            $this->id(), $this->version(),
            ['application' => 'Riverside Council website', 'environment' => 'Local demonstration', 'tenant_id' => $actor->tenantId, 'resource' => $row['id']],
            ['title' => $row['title'], 'body' => $row['body'], 'state' => 'published'],
            ['revision' => (int) $row['revision'], 'published_revision' => null === $row['published_revision'] ? null : (int) $row['published_revision']],
            ['title' => 'Issue the public alert “'.$row['title'].'”', 'consequence' => 'As soon as this is approved, every resident sees the alert on the Riverside Council website.'],
            ['title' => $row['title'], 'revision' => (string) $row['revision'], 'application' => 'Riverside Council website', 'environment' => 'Local demonstration'],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $row = $this->database->alert($action->target['resource']);
        if (null === $row || $row['tenant_id'] !== $actor->tenantId || (int) $row['revision'] !== $action->preconditions['revision']
            || $row['title'] !== $action->effect['title'] || $row['body'] !== $action->effect['body']
            || (null === $row['published_revision'] ? null : (int) $row['published_revision']) !== $action->preconditions['published_revision']) {
            throw new ApprovalException('stale_resource', 'The alert changed after this request was created. Review a new proposal.');
        }
    }

    public function execute(Actor $actor, PreparedAction $action): array
    {
        return $this->publisher->publish($actor, $action);
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $row = $this->database->alert($action->target['resource']);
        if (null === $row || $row['published_title'] !== $action->effect['title'] || $row['published_body'] !== $action->effect['body']
            || (int) $row['published_revision'] !== $action->preconditions['revision']) {
            throw new ApprovalException('unexpected_effect', 'Publication did not preserve the approved content; the transaction was rolled back.');
        }
    }
}
