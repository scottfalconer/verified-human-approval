<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Action;

use Drupal\Core\Database\Connection;
use Drupal\node\NodeInterface;
use Drupal\verified_human_approval\ModuleSettings;
use Drupal\verified_human_approval\Storage\DrupalTransaction;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;

/** Entity API publishing on protected bundles must enter the approval guard. */
final class PublicationGate {

  private ?NodeInterface $allowedNode = NULL;

  public function __construct(
    private readonly ExecutionGuard $guard,
    private readonly ModuleSettings $settings,
    private readonly DrupalTransaction $transactions,
    private readonly Connection $database,
  ) {}

  public function save(Actor $actor, PreparedAction $action, NodeInterface $node): void {
    $this->transactions->assertActive();
    $this->guard->assertExecuting($actor, $action);
    if ($this->allowedNode !== NULL || (string) $node->id() !== $action->target['node_id'] || $node->uuid() !== $action->target['node_uuid']) {
      throw new ApprovalException('approval_required', 'No valid publication context exists for this node.');
    }
    $this->allowedNode = $node;
    try {
      $node->save();
    }
    finally {
      $this->allowedNode = NULL;
    }
  }

  public function assertSaveAllowed(NodeInterface $node): void {
    if (!$node->isPublished() || !in_array($node->bundle(), $this->settings->protectedBundles(), TRUE)) {
      return;
    }
    if ($this->allowedNode !== $node || !$this->transactions->isActive()) {
      throw new ApprovalException('approval_required', 'This content type requires verified publication. Save an unpublished draft, then request approval.');
    }
  }

  /** Catch later generic presave hooks before Drupal commits the entity save. */
  public function assertStoredSaveAllowed(NodeInterface $node): void {
    $protected = $this->settings->protectedBundles();
    $original = $node->getOriginal();
    if (!in_array($node->bundle(), $protected, TRUE) && !($original instanceof NodeInterface && in_array($original->bundle(), $protected, TRUE))) {
      return;
    }
    $rows = $this->database->select('node_field_data', 'n')->fields('n', ['type', 'status'])->condition('nid', $node->id())->execute()->fetchAll();
    // Saving a non-default revision does not update node_field_data. Inspect
    // its persisted translation statuses too, while the save can roll back.
    $revision = $this->database->select('node_field_revision', 'r');
    $revision->join('node', 'n', 'n.nid = r.nid');
    $revision->fields('r', ['status'])->fields('n', ['type']);
    $revision->condition('r.nid', $node->id())->condition('r.vid', $node->getRevisionId());
    $rows = array_merge($rows, $revision->execute()->fetchAll());
    $originalProtected = $original instanceof NodeInterface && in_array($original->bundle(), $protected, TRUE);
    foreach ($rows as $row) {
      if ((bool) $row->status && ($originalProtected || in_array($row->type, $protected, TRUE)) && ($this->allowedNode !== $node || !$this->transactions->isActive())) {
        throw new ApprovalException('approval_required', 'This content type requires verified publication. A later save hook cannot bypass approval.');
      }
    }
  }

}

