<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Action;

use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\PreparedAction;

/** Deletes one saved node and all its revisions, with durable evidence elsewhere. */
final class NodeDeleteAction extends NodeActionBase
{
    public function id(): string { return 'node.delete'; }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $input = $this->input($input, ['node_id']);
        $this->lock((string) $input['node_id']);
        $node = $this->load((string) $input['node_id'], null, false);
        $this->access->assertDelete($actor, $node);
        $source = $this->source($node, true);
        return new PreparedAction(
            $this->id(), $this->version(), $this->target($node),
            ['operation' => 'delete', 'all_revisions' => true, 'all_translations' => true,
                'revision_count' => count($this->snapshots->map($source['revisions']))],
            ['source' => (object) $source],
            ['summary' => 'Permanently delete this content, including every saved revision and translation.',
                'title' => (string) $node->label(), 'nodeId' => (string) $node->id(),
                'revisionCount' => count($this->snapshots->map($source['revisions'])),
                'content' => (object) $this->snapshots->content($node)],
            $this->placeholders($node, 'delete'),
        );
    }

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        $this->assertIdentity($actor, $action);
        $this->access->assertDelete($actor, $this->sourceNode($action));
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $this->assertSource($actor, $action, true);
        $this->assertAllowed($actor, $action);
    }

    public function execute(Actor $actor, PreparedAction $action): array
    {
        $node = $this->assertSource($actor, $action, true);
        $this->assertAllowed($actor, $action);
        $this->guard->within($actor, $action, $node, fn () => $node->delete());
        return [
            'node_id' => $action->target['node_id'], 'uuid' => $action->target['uuid'],
            'deleted' => true, 'all_revisions' => true, 'all_translations' => true,
            'source_digest' => $this->snapshots->digest($action->preconditions['source']),
        ];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $this->assertIdentity($actor, $action);
        $id = $action->target['node_id'];
        $this->storage()->resetCache([$id]);
        if ($this->storage()->load($id) !== null
            || $this->database->select('node_revision', 'r')->condition('nid', $id)->countQuery()->execute()->fetchField() != 0
            || ($result['deleted'] ?? null) !== true || ($result['uuid'] ?? null) !== $action->target['uuid']) {
            throw new ApprovalException('postcondition_failed', 'Drupal did not delete exactly the approved content and revisions.');
        }
    }
}
