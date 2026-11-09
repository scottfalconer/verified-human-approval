<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Action;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorage;
use Drupal\node\NodeStorageInterface;
use Drupal\verified_human_approval\ActorProviderInterface;
use Drupal\verified_human_approval\Clock;
use Drupal\verified_human_approval\ModuleSettings;
use Drupal\verified_human_approval\Storage\DrupalTransaction;
use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\PreparedAction;

/** Publishes a fresh revision copied from an exact, supported draft revision. */
final class PublishNodeRevision implements ActionAdapter {

  public const ID = 'drupal.node.publish_revision';

  private const FIELDS = [
    'nid', 'vid', 'uuid', 'type', 'langcode', 'title', 'uid', 'status',
    'created', 'changed', 'promote', 'sticky', 'revision_timestamp',
    'revision_uid', 'revision_log', 'revision_default',
    'revision_translation_affected', 'default_langcode', 'body',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly EntityFieldManagerInterface $fields,
    private readonly ModuleSettings $settings,
    private readonly ActorProviderInterface $actors,
    private readonly PublicationGate $gate,
    private readonly DrupalTransaction $transactions,
    private readonly Clock $clock,
  ) {}

  public function id(): string {
    return self::ID;
  }

  public function version(): string {
    return '1';
  }

  public function prepare(Actor $actor, array $input): PreparedAction {
    $this->transactions->assertActive();
    $keys = array_keys($input);
    sort($keys);
    if ($keys !== ['langcode', 'node_id', 'revision_id'] || !is_int($input['node_id']) || !is_int($input['revision_id']) || $input['node_id'] < 1 || $input['revision_id'] < 1 || !is_string($input['langcode'])) {
      throw new ApprovalException('invalid_input', 'Provide node_id, revision_id, and langcode only.');
    }
    $node = $this->source($input['node_id'], $input['revision_id'], $input['langcode']);
    $content = $this->content($node);
    $canonical = $this->snapshot($node);
    $digest = hash('sha256', $canonical);
    return new PreparedAction(
      self::ID,
      $this->version(),
      [
        'site_id' => $actor->tenantId,
        'node_id' => (string) $node->id(),
        'node_uuid' => $node->uuid(),
        'bundle' => $node->bundle(),
        'langcode' => $node->language()->getId(),
      ],
      ['published' => TRUE] + $content,
      ['source_revision' => (int) $node->getRevisionId(), 'snapshot' => $canonical, 'content_digest' => $digest],
      [
        'title' => $content['title'],
        'body' => $content['body'],
        'summary' => $content['summary'],
        'language' => $node->language()->getId(),
        'source_revision' => (string) $node->getRevisionId(),
        'owner_uid' => $content['owner_id'],
        'promoted_to_front_page' => $content['promoted'] ? 'Yes' : 'No',
        'sticky' => $content['sticky'] ? 'Yes' : 'No',
        'resulting_change' => 'Create a new published default revision with exactly this stored content.',
        'content_digest' => $digest,
      ],
      ['title' => $content['title'], 'revision' => (string) $node->getRevisionId(), 'language' => $node->language()->getId()],
    );
  }

  public function assertPreconditions(Actor $actor, PreparedAction $action): void {
    $this->transactions->assertActive();
    if ($actor->tenantId !== $action->target['site_id']) {
      throw new ApprovalException('permission_denied', 'The proposal belongs to a different site.');
    }
    $node = $this->source((int) $action->target['node_id'], (int) $action->preconditions['source_revision'], $action->target['langcode']);
    if ($node->uuid() !== $action->target['node_uuid'] || $node->bundle() !== $action->target['bundle'] || !hash_equals($action->preconditions['snapshot'], $this->snapshot($node))) {
      throw new ApprovalException('stale_resource', 'This draft changed after review. Request a new approval.');
    }
  }

  public function execute(Actor $actor, PreparedAction $action): array {
    $this->assertPreconditions($actor, $action);
    $node = $this->source((int) $action->target['node_id'], (int) $action->preconditions['source_revision'], $action->target['langcode']);
    $account = $this->actors->accountFor($actor);
    $node->setNewRevision(TRUE);
    $node->isDefaultRevision(TRUE);
    $node->setPublished();
    $node->setRevisionUserId((int) $account->id());
    $node->setRevisionCreationTime($this->clock->now()->getTimestamp());
    $node->setRevisionLogMessage('Published with verified human approval.');
    $node->setChangedTime($this->clock->now()->getTimestamp());
    $this->gate->save($actor, $action, $node);
    return [
      'disposition' => 'published',
      'node_id' => (string) $node->id(),
      'node_uuid' => $node->uuid(),
      'source_revision' => (int) $action->preconditions['source_revision'],
      'published_revision' => (int) $node->getRevisionId(),
      'langcode' => $node->language()->getId(),
      'content_digest' => hash('sha256', CanonicalJson::encode((object) $this->content($node))),
    ];
  }

  public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void {
    $storage = $this->storage();
    $storage->resetCache([(int) $action->target['node_id']]);
    $node = $storage->load((int) $action->target['node_id']);
    if ($node instanceof NodeInterface) {
      $this->assertProfile($node);
    }
    $effect = $action->effect;
    unset($effect['published']);
    if (!$node instanceof NodeInterface || !$node->isPublished() || $node->uuid() !== $action->target['node_uuid'] || $node->language()->getId() !== $action->target['langcode']
      || $node->bundle() !== $action->target['bundle'] || count($node->getTranslationLanguages()) !== 1
      || (int) $node->getRevisionId() !== $result['published_revision']
      || (int) $storage->getLatestRevisionId($node->id()) !== $result['published_revision']
      || (int) $node->getRevisionId() === (int) $action->preconditions['source_revision']
      || CanonicalJson::encode((object) $this->content($node)) !== CanonicalJson::encode((object) $effect)) {
      throw new ApprovalException('unexpected_effect', 'A save hook changed the approved effect. Publication was rolled back.');
    }
  }

  private function storage(): NodeStorageInterface {
    $storage = $this->entities->getStorage('node');
    if (!$storage instanceof NodeStorageInterface || $storage::class !== NodeStorage::class) {
      throw new ApprovalException('unsupported_storage', 'This action requires Drupal SQL node storage.');
    }
    return $storage;
  }

  private function source(int $nodeId, int $revisionId, string $langcode): NodeInterface {
    $storage = $this->storage();
    $storage->resetCache([$nodeId]);
    $node = $storage->load($nodeId);
    if (!$node instanceof NodeInterface || (int) $node->getRevisionId() !== $revisionId || (int) $storage->getLatestRevisionId($nodeId) !== $revisionId) {
      throw new ApprovalException('stale_resource', 'Only the current latest default draft revision can be published. Request a new approval.');
    }
    if (!in_array($node->bundle(), $this->settings->protectedBundles(), TRUE)) {
      throw new ApprovalException('unsupported_bundle', 'Enable this content type in verified_human_approval protected_bundles.');
    }
    if ($node->isPublished() || $node->language()->getId() !== $langcode || count($node->getTranslationLanguages()) !== 1) {
      throw new ApprovalException('unsupported_revision', 'This release publishes one untranslated, unpublished default revision.');
    }
    $this->assertProfile($node);
    return $node;
  }

  private function assertProfile(NodeInterface $node): void {
    if ($node->hasField('moderation_state')) {
      throw new ApprovalException('unsupported_moderation', 'Content Moderation transitions require a dedicated action adapter.');
    }
    foreach ($this->fields->getFieldDefinitions('node', $node->bundle()) as $name => $definition) {
      if ($name === 'body' && ($definition->getType() !== 'text_with_summary' || $definition->getFieldStorageDefinition()->getCardinality() !== 1)) {
        throw new ApprovalException('unsupported_field', 'The plain-content adapter requires a single text_with_summary body field.');
      }
      if (!$definition->isComputed() && !in_array($name, self::FIELDS, TRUE)) {
        throw new ApprovalException('unsupported_field', "The field '$name' is not supported by the plain-content publication adapter.");
      }
    }
    if ($node->hasField('body') && count($node->get('body')) > 1) {
      throw new ApprovalException('unsupported_field', 'The review must contain every body item; this adapter supports one item only.');
    }
    if ($node->hasField('body') && !$node->get('body')->isEmpty() && $node->get('body')->format !== 'plain_text') {
      throw new ApprovalException('unsupported_text_format', 'This release requires the plain_text body format.');
    }
  }

  /** Fields the new revision must preserve exactly. */
  private function content(NodeInterface $node): array {
    return [
      'title' => $node->getTitle(),
      'body' => $node->hasField('body') ? (string) ($node->get('body')->value ?? '') : '',
      'summary' => $node->hasField('body') ? (string) ($node->get('body')->summary ?? '') : '',
      'body_format' => $node->hasField('body') ? (string) ($node->get('body')->format ?? '') : '',
      'owner_id' => (string) $node->getOwnerId(),
      'created' => (int) $node->getCreatedTime(),
      'promoted' => $node->isPromoted(),
      'sticky' => $node->isSticky(),
    ];
  }

  private function snapshot(NodeInterface $node): string {
    return CanonicalJson::encode([
      'uuid' => $node->uuid(),
      'revision' => (int) $node->getRevisionId(),
      'language' => $node->language()->getId(),
      'changed' => (int) $node->getChangedTime(),
      'published' => $node->isPublished(),
      'content' => (object) $this->content($node),
    ]);
  }

}

