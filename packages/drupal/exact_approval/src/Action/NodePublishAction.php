<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Action;

use Drupal\node\NodeInterface;
use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\PreparedAction;

/** Publishes the exact saved latest revision; accepts no field edits. */
final class NodePublishAction extends NodeActionBase
{
    public function id(): string { return 'node.publish'; }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $input = $this->input($input, ['node_id', 'revision_id', 'transition_id']);
        $this->lock((string) $input['node_id']);
        $node = $this->load((string) $input['node_id'], isset($input['revision_id']) ? (string) $input['revision_id'] : null);
        if ((string) $this->storage()->getLatestRevisionId($node->id()) !== (string) $node->getRevisionId()) {
            throw new ApprovalException('stale_revision', 'Only the latest saved revision can be published by this action.');
        }
        $workflow = $this->access->assertPublish($actor, $node, $input['transition_id'] ?? null);
        $source = $this->source($node);
        $expected = clone $node;
        $this->applyPublication($expected, $workflow['state']);
        return new PreparedAction(
            $this->id(), $this->version(), $this->target($node),
            ['operation' => 'publish', 'new_revision' => true, 'published' => true,
                'workflow' => (object) $workflow, 'content' => (object) $this->snapshots->content($expected)],
            ['source' => (object) $source],
            ['summary' => 'Publish this exact saved content as a new default revision.',
                'title' => (string) $node->label(), 'nodeId' => (string) $node->id(),
                'revisionId' => (string) $node->getRevisionId(), 'transition' => $workflow['transition'],
                'content' => (object) $this->snapshots->content($expected)],
            $this->placeholders($node, 'publish', $workflow['transition']),
        );
    }

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        $this->assertIdentity($actor, $action);
        $workflow = $this->snapshots->map($action->effect['workflow']);
        $current = $this->access->assertPublish($actor, $this->sourceNode($action), $workflow['transition']);
        if (!$this->snapshots->same($current, $workflow)) {
            throw new ApprovalException('workflow_changed', 'The publishing workflow or transition changed.');
        }
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $this->assertSource($actor, $action);
        $this->assertAllowed($actor, $action);
    }

    public function execute(Actor $actor, PreparedAction $action): array
    {
        $node = $this->assertSource($actor, $action);
        $this->assertAllowed($actor, $action);
        $workflow = $this->snapshots->map($action->effect['workflow']);
        $this->applyPublication($node, $workflow['state']);
        $node->setRevisionUserId((int) $actor->userId);
        $node->setRevisionLogMessage('Published after exact action approval.');
        $node->setRevisionCreationTime(\Drupal::time()->getCurrentTime());
        $violations = $node->validate();
        if (count($violations) !== 0) {
            throw new ApprovalException('validation_failed', 'Drupal validation rejected the exact proposed publication.');
        }
        $this->guard->within($actor, $action, $node, fn () => $node->save());
        return [
            'node_id' => (string) $node->id(), 'uuid' => $node->uuid(),
            'revision_id' => (string) $node->getRevisionId(), 'published' => true,
            'moderation_state' => $workflow['state'],
            'content_digest' => $this->snapshots->digest($action->effect['content']),
        ];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $this->assertIdentity($actor, $action);
        $node = $this->load($action->target['node_id'], (string) $result['revision_id']);
        $default = $this->load($action->target['node_id'], null, false);
        if (!$node->isPublished() || !$node->isDefaultRevision()
            || (string) $default->getRevisionId() !== (string) $result['revision_id']
            || (string) $result['revision_id'] === $action->target['revision_id']
            || !$this->snapshots->same($this->snapshots->content($node), $action->effect['content'])
            || !$this->snapshots->same($this->snapshots->content($default), $action->effect['content'])
            || (string) $node->getRevisionUserId() !== $actor->userId) {
            throw new ApprovalException('postcondition_failed', 'Drupal did not persist exactly the approved publication.');
        }
    }

    private function applyPublication(NodeInterface $node, ?string $state): void
    {
        $node->setPublished();
        if ($state !== null) {
            $node->set('moderation_state', $state);
        }
        $node->setNewRevision(true);
        $node->isDefaultRevision(true);
    }
}
