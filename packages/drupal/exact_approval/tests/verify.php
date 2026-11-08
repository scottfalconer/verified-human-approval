<?php

declare(strict_types=1);

/**
 * Real installed-Drupal acceptance tests. This is intentionally not a mock kernel.
 *
 * Run from the repository with:
 *   php packages/drupal/exact_approval/tests/verify.php
 *
 * Requires the disposable example installed by examples/drupal/setup.php.
 * The marker in settings.php prevents use against an ordinary Drupal site.
 * Test users, nodes, registrations, and audit rows are retained for inspection.
 */

use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\workflows\Entity\Workflow;
use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ApprovalPolicy;
use ExactApproval\Core\ApprovalService;
use ExactApproval\Core\ApprovalState;
use ExactApproval\Core\CanonicalJson;
use ExactApproval\Core\ChallengeBinding;
use ExactApproval\Core\Evidence\ReceiptVerifier;
use ExactApproval\Tests\Support\VirtualAuthenticator;
use ExactApproval\WebAuthn\Credential;
use Symfony\Component\HttpFoundation\Request;

$project = dirname(__DIR__, 4);
$example = $project.'/examples/drupal';
$origin = 'http://localhost:8090';
$loader = require $example.'/vendor/autoload.php';
require_once $project.'/tests/Support/VirtualAuthenticator.php';

if (!\Drupal::hasContainer()) {
  chdir($example.'/web');
  $request = Request::create($origin);
  $kernel = DrupalKernel::createFromRequest($request, $loader, 'prod');
  $kernel->boot();
  $kernel->preHandle($request);
}

if (Settings::get('exact_approval_acceptance_test') !== TRUE) {
  fwrite(STDERR, "Refusing acceptance mutations: this Drupal site lacks the exact_approval_acceptance_test marker.\n");
  exit(2);
}

/** Exercises the production Drupal services and database with genuine signatures. */
final class ExactApprovalDrupalAcceptance {

  private ApprovalService $approvals;
  private object $credentials;
  private object $webauthn;
  private object $evidence;
  private array $trustedApplication;
  private string $prefix;
  private string $plain;
  private string $moderated;
  private string $workflow;
  private UserInterface $editor;
  private UserInterface $other;
  private UserInterface $limited;
  private Actor $actor;
  private Actor $otherActor;
  private Actor $limitedActor;
  private VirtualAuthenticator $authenticator;
  private VirtualAuthenticator $otherAuthenticator;
  private Credential $trustedCredential;
  private array $originalSettings;
  private array $outcomes = [];
  private int $assertions = 0;

  public function __construct(private readonly string $origin) {}

  public function run(): int {
    $this->setUp();
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($this->editor);
    try {
      $this->scenario('Native permissions remain mandatory before approval', fn () => $this->nativePermissions());
      $this->scenario('Hidden fields cannot leak through approval review or evidence', fn () => $this->hiddenField());
      $this->scenario('An authorized account still needs its own registered key', fn () => $this->keyRequired());
      $this->registerKeys();
      $this->scenario('Real ES256 approval publishes exactly the stored Drupal draft', fn () => $this->publish());
      $this->scenario('Direct and bulk entity mutation cannot bypass required approval', fn () => $this->directBypasses());
      $this->scenario('Another Drupal UID or passkey cannot approve the request', fn () => $this->wrongIdentity());
      $this->scenario('User verification and exact challenge are mandatory', fn () => $this->invalidAssertions());
      $this->scenario('Changed saved content invalidates pending approval', fn () => $this->staleContent());
      $this->scenario('A replacement revision invalidates pending approval', fn () => $this->staleRevision());
      $this->scenario('Changed administrator policy invalidates pending approval', fn () => $this->stalePolicy());
      $this->scenario('Revoked native permissions are rechecked at execution', fn () => $this->revokedPermission());
      $this->scenario('Removed role membership is rechecked in the same user session', fn () => $this->revokedMembership());
      $this->scenario('Drupal moderation transition permission governs publication', fn () => $this->moderation());
      $this->scenario('Publish failure rolls back node, hook effect, key counter, and evidence', fn () => $this->rollback('publish'));
      $this->scenario('Late hook content alteration fails exact persisted postconditions', fn () => $this->rollback('publish', 'alter'));
      $this->scenario('Delete failure rolls back node, hook effect, key counter, and evidence', fn () => $this->rollback('delete'));
      $this->scenario('Concurrent completions in two Drupal processes commit one publication', fn () => $this->concurrentCompletion());
      $this->scenario('Approved deletion retains independently verifiable evidence', fn () => $this->deleteAndVerify());
      $this->scenario('Revoked passkey cannot approve a newly requested operation', fn () => $this->revokedCredential());
    }
    finally {
      \Drupal::state()->delete('exact_approval_test.failure');
      \Drupal::state()->delete('exact_approval_test.hidden_field');
      \Drupal::configFactory()->getEditable('exact_approval.settings')->setData($this->originalSettings)->save();
      $switcher->switchBack();
    }
    $failures = count(array_filter($this->outcomes, fn (array $outcome): bool => $outcome['status'] === 'failed'));
    $report = [
      'suite' => 'exact-approval/real-drupal-acceptance/v1',
      'drupal' => \Drupal::VERSION,
      'database' => \Drupal::database()->driver(),
      'run' => $this->prefix,
      'tests' => count($this->outcomes),
      'assertions' => $this->assertions,
      'failures' => $failures,
      'authenticator' => 'Synthetic ES256 signatures checked by the production WebAuthn verifier; no physical biometrics tested.',
      'outcomes' => $this->outcomes,
    ];
    $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    file_put_contents(dirname(__DIR__, 4).'/examples/drupal/var/verification.json', $json);
    fwrite(STDOUT, $json);
    return $failures === 0 ? 0 : 1;
  }

  private function setUp(): void {
    foreach (['exact_approval', 'node', 'content_moderation', 'workflows', 'exact_approval_test'] as $module) {
      if (!\Drupal::moduleHandler()->moduleExists($module)) {
        \Drupal::service('module_installer')->install([$module]);
      }
    }
    $this->originalSettings = \Drupal::config('exact_approval.settings')->getRawData();
    $this->prefix = 'ea_'.bin2hex(random_bytes(4));
    $this->plain = $this->prefix.'_plain';
    $this->moderated = $this->prefix.'_mod';
    $this->workflow = $this->prefix.'_workflow';
    foreach ([$this->plain, $this->moderated] as $bundle) {
      NodeType::create(['type' => $bundle, 'name' => 'Approval acceptance '.$bundle, 'new_revision' => TRUE])->save();
    }
    Workflow::create([
      'id' => $this->workflow,
      'label' => 'Acceptance editorial workflow',
      'type' => 'content_moderation',
      'type_settings' => [
        'states' => [
          'draft' => ['label' => 'Draft', 'weight' => 0, 'published' => FALSE, 'default_revision' => FALSE],
          'published' => ['label' => 'Published', 'weight' => 1, 'published' => TRUE, 'default_revision' => TRUE],
        ],
        'transitions' => [
          'draft' => ['label' => 'Keep draft', 'from' => ['draft', 'published'], 'to' => 'draft', 'weight' => 0],
          'publish' => ['label' => 'Publish', 'from' => ['draft', 'published'], 'to' => 'published', 'weight' => 1],
        ],
        'entity_types' => ['node' => [$this->moderated]],
        'default_moderation_state' => 'draft',
      ],
    ])->save();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    $permissions = [
      'access content', 'access user profiles', 'view own unpublished content',
      'administer node published status',
      'create '.$this->plain.' content', 'edit any '.$this->plain.' content', 'delete any '.$this->plain.' content',
      'create '.$this->moderated.' content', 'edit any '.$this->moderated.' content', 'delete any '.$this->moderated.' content',
      'use '.$this->workflow.' transition draft', 'use '.$this->workflow.' transition publish',
    ];
    // Grant module access permissions if present, without assuming a permission
    // alias is a substitute for Drupal's real operation-level authorization.
    $declared = \Drupal::service('user.permissions')->getPermissions();
    foreach ($declared as $name => $definition) {
      if (($definition['provider'] ?? '') === 'exact_approval' && !str_starts_with($name, 'administer ')) {
        $permissions[] = $name;
      }
    }
    $role = Role::create(['id' => $this->prefix.'_editor', 'label' => 'Approval acceptance editor']);
    foreach ($permissions as $permission) {
      if (!isset($declared[$permission])) {
        throw new \RuntimeException('The expected real Drupal permission is missing: '.$permission);
      }
      $role->grantPermission($permission);
    }
    $role->save();
    $this->editor = $this->user('editor', [$role->id()]);
    $this->other = $this->user('other', [$role->id()]);
    $limitedRole = Role::create(['id' => $this->prefix.'_limited', 'label' => 'Acceptance account without publish/delete rights']);
    $limitedRole->grantPermission('access content')->grantPermission('edit any '.$this->plain.' content')->save();
    $this->limited = $this->user('limited', [$limitedRole->id()]);
    $provider = \Drupal::service('exact_approval.actor_provider');
    $tenant = $provider->tenantId();
    $this->actor = new Actor((string) $this->editor->id(), $tenant);
    $this->otherActor = new Actor((string) $this->other->id(), $tenant);
    $this->limitedActor = new Actor((string) $this->limited->id(), $tenant);

    $rules = [];
    foreach ([$this->plain, $this->moderated] as $bundle) {
      $rules[] = ['operation' => 'publish', 'bundle' => $bundle, 'permissions' => [$bundle === $this->plain ? 'administer node published status' : 'use '.$this->workflow.' transition publish']];
      $rules[] = ['operation' => 'delete', 'bundle' => $bundle, 'permissions' => ['delete any '.$bundle.' content']];
    }
    \Drupal::configFactory()->getEditable('exact_approval.settings')
      ->set('rules', $rules)->set('origin', $this->origin)->set('rp_id', 'localhost')->set('lifetime', 600)
      ->set('statements.publish', 'I approve publishing exactly the saved content displayed for {{title}}.')
      ->set('statements.delete', 'I approve permanently deleting exactly the displayed content {{title}}.')
      ->save();
    $this->approvals = \Drupal::service('exact_approval.approvals');
    $this->credentials = \Drupal::service('exact_approval.credentials');
    $this->webauthn = \Drupal::service('exact_approval.webauthn');
    $this->evidence = \Drupal::service('exact_approval.evidence');
    $this->trustedApplication = $this->evidence->trust();
    $this->authenticator = new VirtualAuthenticator($this->origin);
    $this->otherAuthenticator = new VirtualAuthenticator($this->origin);
  }

  private function nativePermissions(): void {
    $node = $this->node();
    $this->check(!$node->access('delete', $this->limited), 'Fixture must lack native delete access.');
    foreach (['node.publish', 'node.delete'] as $action) {
      $this->expectApprovalFailure(fn () => $this->approvals->request($this->limitedActor, $action, ['node_id' => (int) $node->id()]));
    }
    $this->check(!$this->fresh($node)->isPublished(), 'Denied requests must not publish.');
    $this->check($this->fresh($node) !== NULL, 'Denied requests must not delete.');
  }

  private function keyRequired(): void {
    $node = $this->node();
    $state = $this->approvals->request($this->actor, 'node.publish', ['node_id' => (int) $node->id()]);
    $this->same('pending', $state->status, 'Authorized request must await key approval.');
    foreach (['publish', 'delete'] as $operation) {
      $defaultPolicy = new ApprovalPolicy('node.'.$operation, 'test-installed-default', $this->originalSettings['statements'][$operation]);
      $this->check($defaultPolicy->statement($state->request->prepared()) !== '', 'Installed default statement placeholders must all resolve.');
    }
    $this->expectApprovalFailure(fn () => $this->approvals->begin($this->actor, $state->request->id), ['credential_required']);
    $this->check(!$this->fresh($node)->isPublished(), 'No key must mean no mutation.');
  }

  private function hiddenField(): void {
    $node = $this->node();
    $count = fn (): int => (int) \Drupal::database()->select('exact_approval_requests', 'r')->condition('actor_user', $this->actor->userId)->countQuery()->execute()->fetchField();
    $before = $count();
    \Drupal::state()->set('exact_approval_test.hidden_field', ['field' => 'title', 'uuid' => $node->uuid(), 'uid' => $this->actor->userId]);
    try {
      $this->check($node->access('update', $this->editor), 'Fixture still has native entity update permission.');
      $this->check($node->access('delete', $this->editor), 'Fixture still has native entity delete permission.');
      $this->check(!$node->get('title')->access('view', $this->editor), 'Fixture must really deny field view access.');
      foreach (['node.publish', 'node.delete'] as $action) {
        $this->expectApprovalFailure(fn () => $this->approvals->request($this->actor, $action, ['node_id' => (int) $node->id()]), ['access_denied']);
      }
      $this->same($before, $count(), 'No proposal containing the hidden value is persisted.');
    }
    finally {
      \Drupal::state()->delete('exact_approval_test.hidden_field');
    }
  }

  private function registerKeys(): void {
    $options = $this->webauthn->registrationOptions($this->actor, random_bytes(32), $this->editor->getAccountName());
    $this->trustedCredential = $this->webauthn->register($this->actor, $options, $this->authenticator->registration($options), 'Acceptance ES256 key');
    $otherOptions = $this->webauthn->registrationOptions($this->otherActor, random_bytes(32), $this->other->getAccountName());
    $this->webauthn->register($this->otherActor, $otherOptions, $this->otherAuthenticator->registration($otherOptions), 'Different account ES256 key');
  }

  private function publish(): void {
    $node = $this->node();
    $title = $node->label();
    $pending = $this->approvals->request($this->actor, 'node.publish', ['node_id' => (int) $node->id()]);
    $ceremony = $this->approvals->begin($this->actor, $pending->request->id);
    $assertion = $this->authenticator->assertion($ceremony->options);
    $state = $this->approvals->complete($this->actor, $ceremony->id, $assertion);
    $this->same('completed', $state->status, 'Valid WebAuthn approval must execute.');
    $this->check($this->fresh($node)->isPublished(), 'Drupal persisted published status.');
    $this->same($title, $this->fresh($node)->label(), 'Content must not silently change while publishing.');
    $this->same('user_verified', $state->execution['approval'], 'Execution must retain approval provenance.');
    $this->same($this->authenticator->id(), $state->proof['credentialId'], 'Exact public credential reference must be retained.');
    $persisted = $this->approvals->get($this->actor, $state->request->id);
    $this->same($state->request->canonical, $persisted->request->canonical, 'Exact proposal bytes survive persistence.');
    $this->same($state->proof, $persisted->proof, 'Signature evidence survives persistence.');
    $this->expectApprovalFailure(fn () => $this->approvals->get($this->otherActor, $state->request->id), ['not_found']);
    $resultRevision = $this->fresh($node)->getRevisionId();
    $replay = $this->approvals->complete($this->actor, $ceremony->id, $assertion);
    $this->same($state->result, $replay->result, 'Completed ceremony replay returns the same result.');
    $this->same($resultRevision, $this->fresh($node)->getRevisionId(), 'Replay does not publish an extra revision.');

    // Saving an already live node must also be protected: otherwise an attacker
    // could publish a harmless draft once, then replace its body without approval.
    $this->asUser($this->editor, function () use ($node): void {
      $live = $this->fresh($node);
      $live->setTitle('Unapproved replacement of published content');
      $this->expectApprovalFailure(fn () => $live->save());
    });
    $this->same($title, $this->fresh($node)->label(), 'Published-content edits cannot evade the publish policy.');
  }

  private function directBypasses(): void {
    $node = $this->node();
    $second = $this->node();
    $unprotected = $this->prefix.'_unprotected';
    NodeType::create(['type' => $unprotected, 'name' => 'Unprotected acceptance content type'])->save();
    $this->asUser($this->editor, function () use ($node, $second, $unprotected): void {
      $draft = $this->fresh($node);
      $draft->setTitle('Ordinary saved draft remains editable');
      $draft->save();
      $draft = $this->fresh($node);
      $draft->setPublished();
      $this->expectApprovalFailure(fn () => $draft->save());
      $this->expectApprovalFailure(fn () => $this->fresh($node)->delete());
      $this->expectApprovalFailure(fn () => \Drupal::entityTypeManager()->getStorage('node')->delete([$this->fresh($node), $this->fresh($second)]));

      $values = $this->fresh($node)->toArray();
      $values['type'] = [['target_id' => $unprotected]];
      $disguised = Node::create($values);
      $disguised->enforceIsNew(FALSE);
      $disguised->setNewRevision(FALSE);
      $this->same($unprotected, $disguised->bundle(), 'Fixture must actually disguise the incoming bundle.');
      $this->expectApprovalFailure(fn () => $disguised->delete(), ['approval_required']);
      $disguised = Node::create($values);
      $disguised->enforceIsNew(FALSE);
      $disguised->setNewRevision(FALSE);
      $disguised->setPublished();
      $this->expectApprovalFailure(fn () => $disguised->save(), ['unsupported_action']);
      $this->same($this->plain, $this->fresh($node)->bundle(), 'Stored bundle remains authoritative for approval protection.');

      \Drupal::state()->set('exact_approval_test.failure', ['uuid' => $node->uuid(), 'operation' => 'update', 'mode' => 'publish']);
      try {
        $this->expectApprovalFailure(fn () => $this->fresh($node)->save(), ['approval_required']);
      }
      finally {
        \Drupal::state()->delete('exact_approval_test.failure');
      }

      $revision = $this->fresh($node)->getRevisionId();
      $draft = $this->fresh($node);
      $draft->setNewRevision(TRUE);
      $draft->save();
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $this->expectApprovalFailure(fn () => $storage->deleteRevision($revision), ['unsupported_action']);
      $storage->resetRevisionCache([$revision]);
      $this->check($storage->loadRevision($revision) !== NULL, 'Individual revision deletion cannot bypass a protected delete policy.');
    });
    $this->check(!$this->fresh($node)->isPublished(), 'Direct save did not publish.');
    $this->check($this->fresh($node) !== NULL && $this->fresh($second) !== NULL, 'Direct/bulk deletes retained both nodes.');
    $root = User::load(1);
    if ($root !== NULL) {
      $this->asUser($root, function () use ($node): void {
        $draft = $this->fresh($node);
        $draft->setPublished();
        $this->expectApprovalFailure(fn () => $draft->save());
        $this->expectApprovalFailure(fn () => $this->fresh($node)->delete());
      });
      $this->check(!$this->fresh($node)->isPublished(), 'UID 1 did not bypass required key approval.');
    }
  }

  private function wrongIdentity(): void {
    $node = $this->node();
    $state = $this->approvals->request($this->actor, 'node.publish', ['node_id' => (int) $node->id()]);
    $this->expectApprovalFailure(fn () => $this->approvals->begin($this->otherActor, $state->request->id), ['not_found']);
    $ceremony = $this->approvals->begin($this->actor, $state->request->id);
    $this->expectApprovalFailure(fn () => $this->approvals->complete($this->otherActor, $ceremony->id, $this->authenticator->assertion($ceremony->options)), ['not_found']);
    $this->expectApprovalFailure(fn () => $this->approvals->complete($this->actor, $ceremony->id, $this->otherAuthenticator->assertion($ceremony->options)));
    $this->check(!$this->fresh($node)->isPublished(), 'A different caller or key must not publish.');
  }

  private function invalidAssertions(): void {
    $node = $this->node();
    $state = $this->approvals->request($this->actor, 'node.publish', ['node_id' => (int) $node->id()]);
    $ceremony = $this->approvals->begin($this->actor, $state->request->id);
    foreach ([['flags' => 0x01], ['challenge' => ChallengeBinding::encode(random_bytes(32))], ['origin' => 'https://attacker.invalid']] as $change) {
      $before = $this->credentials->find($this->authenticator->id())->sourceJson;
      $this->expectApprovalFailure(fn () => $this->approvals->complete($this->actor, $ceremony->id, $this->authenticator->assertion($ceremony->options, $change)));
      $this->same($before, $this->credentials->find($this->authenticator->id())->sourceJson, 'Rejected signature must not advance stored counter.');
    }
    $this->check(!$this->fresh($node)->isPublished(), 'Missing UV, wrong challenge, and wrong origin must not publish.');
  }

  private function staleContent(): void {
    $node = $this->node();
    $state = $this->approvals->request($this->actor, 'node.publish', ['node_id' => (int) $node->id()]);
    $ceremony = $this->approvals->begin($this->actor, $state->request->id);
    $this->asUser($this->editor, function () use ($node): void {
      $draft = $this->fresh($node);
      $draft->setNewRevision(FALSE);
      $draft->setTitle('An editor changed the saved content after review');
      $draft->save();
    });
    $result = $this->approvals->complete($this->actor, $ceremony->id, $this->authenticator->assertion($ceremony->options));
    $this->same('invalidated', $result->status, 'Saved field changes invalidate the proposal.');
    $this->check(!$this->fresh($node)->isPublished(), 'Stale content must not publish.');
    $this->same(NULL, $result->proof, 'Invalidated proposal must not acquire successful approval evidence.');
  }

  private function staleRevision(): void {
    $node = $this->node();
    $revision = $node->getRevisionId();
    $state = $this->approvals->request($this->actor, 'node.delete', ['node_id' => (int) $node->id()]);
    $ceremony = $this->approvals->begin($this->actor, $state->request->id);
    $draft = $this->fresh($node);
    $draft->setNewRevision(TRUE);
    $draft->save();
    $this->check((string) $revision !== (string) $this->fresh($node)->getRevisionId(), 'Fixture must create a genuinely different revision.');
    $result = $this->approvals->complete($this->actor, $ceremony->id, $this->authenticator->assertion($ceremony->options));
    $this->same('invalidated', $result->status, 'Even unchanged content in a replacement revision invalidates the exact target.');
    $this->check($this->fresh($node) !== NULL, 'Stale deletion must not remove the node.');
  }

  private function stalePolicy(): void {
    $node = $this->node();
    $state = $this->approvals->request($this->actor, 'node.publish', ['node_id' => (int) $node->id()]);
    $ceremony = $this->approvals->begin($this->actor, $state->request->id);
    $config = \Drupal::configFactory()->getEditable('exact_approval.settings');
    $previous = $config->get('statements.publish');
    try {
      $config->set('statements.publish', $previous.' Additional administrator requirement.')->save();
      $result = $this->approvals->complete($this->actor, $ceremony->id, $this->authenticator->assertion($ceremony->options));
      $this->same('invalidated', $result->status, 'Changed policy invalidates rather than silently proceeding.');
      $this->same('policy_changed', $result->reason, 'Policy change has a precise reason.');
      $this->check(!$this->fresh($node)->isPublished(), 'Changed policy must not publish.');
    }
    finally {
      $config->set('statements.publish', $previous)->save();
    }
  }

  private function revokedPermission(): void {
    $node = $this->node();
    $state = $this->approvals->request($this->actor, 'node.delete', ['node_id' => (int) $node->id()]);
    $ceremony = $this->approvals->begin($this->actor, $state->request->id);
    $role = Role::load($this->prefix.'_editor');
    $permission = 'delete any '.$this->plain.' content';
    try {
      $role->revokePermission($permission)->save();
      $result = $this->approvals->complete($this->actor, $ceremony->id, $this->authenticator->assertion($ceremony->options));
      $this->same('denied', $result->status, 'Revoked native authority is rechecked after signature was requested.');
      $this->check($this->fresh($node) !== NULL, 'A valid key cannot replace current Drupal permission.');
    }
    finally {
      $role->grantPermission($permission)->save();
    }
  }

  private function moderation(): void {
    $node = $this->node(TRUE);
    $state = $this->approve('node.publish', $node, ['transition_id' => 'publish']);
    $fresh = $this->fresh($node);
    $this->same('completed', $state->status, 'Moderated publish succeeds through its actual transition.');
    $this->check($fresh->isPublished(), 'Moderation persisted published status.');
    $this->same('published', $fresh->get('moderation_state')->value, 'Moderation state matches approved effect.');

    $liveTitle = $fresh->label();
    $this->asUser($this->editor, function () use ($fresh): void {
      $fresh->setNewRevision(TRUE);
      $fresh->set('moderation_state', 'draft');
      $fresh->setTitle('A new unpublished editorial draft after publication');
      $fresh->save();
    });
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $latestId = $storage->getLatestRevisionId($node->id());
    $storage->resetRevisionCache([$latestId]);
    $latest = $storage->loadRevision($latestId);
    $this->check(!$latest->isPublished(), 'An editor can still save a new unpublished moderation draft.');
    $this->same('draft', $latest->get('moderation_state')->value, 'The new draft keeps the ordinary editorial workflow.');
    $this->same($liveTitle, $this->fresh($node)->label(), 'Saving a draft does not change the approved live revision.');

    $second = $this->node(TRUE);
    $pending = $this->approvals->request($this->actor, 'node.publish', ['node_id' => (int) $second->id(), 'transition_id' => 'publish']);
    $ceremony = $this->approvals->begin($this->actor, $pending->request->id);
    $role = Role::load($this->prefix.'_editor');
    $permission = 'use '.$this->workflow.' transition publish';
    try {
      $role->revokePermission($permission)->save();
      $result = $this->approvals->complete($this->actor, $ceremony->id, $this->authenticator->assertion($ceremony->options));
      $this->same('denied', $result->status, 'Revoked workflow transition permission is authoritative.');
      $this->check(!$this->fresh($second)->isPublished(), 'General edit/status permission cannot bypass workflow permission.');
    }
    finally {
      $role->grantPermission($permission)->save();
    }
  }

  private function revokedMembership(): void {
    $node = $this->node();
    $pending = $this->approvals->request($this->actor, 'node.publish', ['node_id' => (int) $node->id()]);
    $ceremony = $this->approvals->begin($this->actor, $pending->request->id);
    $counterBefore = $this->credentials->find($this->authenticator->id())->sourceJson;
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $storage->resetCache([$this->editor->id()]);
    $account = $storage->load($this->editor->id());
    $roleId = $this->prefix.'_editor';
    try {
      $account->removeRole($roleId)->save();
      $result = $this->approvals->complete($this->actor, $ceremony->id, $this->authenticator->assertion($ceremony->options));
      $this->same('denied', $result->status, 'Current UID does not retain authority from stale role membership.');
      $this->check(!$this->fresh($node)->isPublished(), 'Role removal prevents publication.');
      $this->same($counterBefore, $this->credentials->find($this->authenticator->id())->sourceJson, 'Denied role membership does not consume a successful signature.');
      $this->expectApprovalFailure(fn () => $this->evidence->exportForActor($this->actor, $pending->request->id), ['not_completed']);
    }
    finally {
      $storage->resetCache([$this->editor->id()]);
      $account = $storage->load($this->editor->id());
      $account->addRole($roleId)->save();
      \Drupal::service('exact_approval.actor_provider')->account($this->actor);
    }
  }

  private function rollback(string $operation, string $mode = 'throw'): void {
    $node = $this->node();
    $title = $node->label();
    $state = $this->approvals->request($this->actor, 'node.'.$operation, ['node_id' => (int) $node->id()]);
    $ceremony = $this->approvals->begin($this->actor, $state->request->id);
    $counterBefore = $this->credentials->find($this->authenticator->id())->sourceJson;
    $database = \Drupal::database();
    $database->delete('key_value')->condition('collection', 'exact_approval_test')->condition('name', 'mutation_side_effect')->execute();
    \Drupal::state()->set('exact_approval_test.failure', ['uuid' => $node->uuid(), 'operation' => $operation === 'publish' ? 'update' : 'delete', 'mode' => $mode]);
    try {
      $this->expectApprovalFailure(fn () => $this->approvals->complete($this->actor, $ceremony->id, $this->authenticator->assertion($ceremony->options)), ['execution_failed']);
    }
    finally {
      \Drupal::state()->delete('exact_approval_test.failure');
    }
    $fresh = $this->fresh($node);
    $this->check($fresh !== NULL && !$fresh->isPublished(), 'Failed local mutation rolled back the node.');
    $this->same($title, $fresh->label(), 'Late changes to saved fields also roll back.');
    $this->same($counterBefore, $this->credentials->find($this->authenticator->id())->sourceJson, 'WebAuthn counter and mutation share one transaction.');
    $this->same(FALSE, $database->select('key_value', 'k')->fields('k', ['name'])->condition('collection', 'exact_approval_test')->condition('name', 'mutation_side_effect')->execute()->fetchField(), 'Hook database side effect rolled back.');
    $after = $this->approvals->get($this->actor, $state->request->id);
    $this->same('pending', $after->status, 'Failed execution leaves no success status.');
    $this->same(NULL, $after->execution, 'No execution receipt survives failed transaction.');
    $this->same(NULL, $after->proof, 'No approval proof is recorded as successful after rollback.');
    $this->expectApprovalFailure(fn () => $this->evidence->exportForActor($this->actor, $state->request->id), ['not_completed']);
    $this->expectApprovalFailure(fn () => $this->approvals->complete($this->actor, $ceremony->id, $this->authenticator->assertion($ceremony->options)), ['ceremony_consumed']);
    $retry = $this->approvals->begin($this->actor, $state->request->id);
    $completed = $this->approvals->complete($this->actor, $retry->id, $this->authenticator->assertion($retry->options));
    $this->same('completed', $completed->status, 'A new exact approval can retry after an uncommitted failure.');
  }

  private function deleteAndVerify(): void {
    $node = $this->node();
    $uuid = $node->uuid();
    $state = $this->approve('node.delete', $node);
    $this->same('completed', $state->status, 'Approved deletion succeeds.');
    $this->same(NULL, $this->fresh($node), 'Node is actually absent from Drupal storage.');
    $stored = $this->approvals->get($this->actor, $state->request->id);
    $this->same($state->request->canonical, $stored->request->canonical, 'Proposal remains after its target is deleted.');
    $this->same($this->actor->userId, $stored->request->actor()->userId, 'Drupal UID remains bound to deletion evidence.');
    $this->check(str_contains($stored->request->canonical, $uuid), 'Deleted entity UUID remains in the immutable proposal.');
    $this->same($this->trustedCredential->id, $stored->proof['credentialId'], 'Registered key reference survives deletion.');
    $this->check($this->credentials->find($this->trustedCredential->id) !== NULL, 'Public credential material is retained independently of content.');

    // Verify the exact persisted human proof, independently of live counter state.
    $proof = $stored->proof;
    $verified = $this->webauthn->verifyEvidenceAssertion($stored->request->actor(), ChallengeBinding::decode($proof['challenge']), CanonicalJson::map($proof['assertion']), $this->trustedCredential);
    $this->same($this->trustedCredential->id, $verified->credentialId, 'Human signature verifies after target deletion.');

    // These are the actual receipt and public-key snapshot persisted by Drupal
    // in the content transaction, checked against a key read before the action.
    $bundle = $this->evidence->exportForActor($this->actor, $state->request->id);
    $trust = $this->evidence->trustForActor($this->actor, $state->request->id);
    $credential = $this->evidence->credentialForActor($this->actor, $state->request->id);
    $this->same($this->trustedApplication, $trust, 'Execution retains the trusted application key and RP context.');
    $this->same($this->trustedCredential->id, $credential['id'], 'Evidence includes the exact public credential snapshot.');
    $receipt = (new ReceiptVerifier())->verify($bundle, $this->trustedApplication['keyId'], $this->trustedApplication['publicKey']);
    $this->same(TRUE, $receipt['applicationSignatureValid'], 'Drupal persisted a verifiable application execution signature.');
    $evidenceCredential = new Credential($credential['id'], $credential['userId'], $credential['tenantId'], $credential['sourceJson']);
    $historical = $this->webauthn->verifyEvidenceAssertion($stored->request->actor(), ChallengeBinding::decode($proof['challenge']), CanonicalJson::map($proof['assertion']), $evidenceCredential);
    $this->same($this->trustedCredential->id, $historical->credentialId, 'Retained public key snapshot verifies the human signature.');
    $this->expectApprovalFailure(fn () => $this->evidence->exportForActor($this->otherActor, $state->request->id), ['not_found']);

    $tampered = CanonicalJson::map(CanonicalJson::decode(CanonicalJson::encode((object) $bundle)));
    $tampered['execution']->result->deleted = FALSE;
    $this->expectApprovalFailure(fn () => (new ReceiptVerifier())->verify($tampered, $this->trustedApplication['keyId'], $this->trustedApplication['publicKey']), ['invalid_evidence']);
    $this->same(CanonicalJson::encode((object) $bundle), CanonicalJson::encode((object) $this->evidence->exportForActor($this->actor, $state->request->id)), 'Exports read immutable stored evidence without re-signing or repeating the action.');

    // The completed ceremony is a receipt lookup, never a second deletion.
    $this->same('completed', $this->approvals->get($this->actor, $state->request->id)->status, 'Completed state remains stable.');
  }

  private function concurrentCompletion(): void {
    $node = $this->node();
    $pending = $this->approvals->request($this->actor, 'node.publish', ['node_id' => (int) $node->id()]);
    $ceremony = $this->approvals->begin($this->actor, $pending->request->id);
    $assertion = $this->authenticator->assertion($ceremony->options);
    $countRevisions = fn (): int => (int) \Drupal::database()->select('node_revision', 'r')->condition('nid', $node->id())->countQuery()->execute()->fetchField();
    $before = $countRevisions();
    $directory = sys_get_temp_dir().'/exact-approval-drupal-race-'.bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
      throw new \RuntimeException('Could not create the isolated race barrier.');
    }
    $payload = $directory.'/payload.json';
    file_put_contents($payload, CanonicalJson::encode((object) [
      'userId' => $this->actor->userId, 'tenantId' => $this->actor->tenantId,
      'ceremonyId' => $ceremony->id, 'assertion' => (object) $assertion,
    ]));
    $processes = [];
    try {
      foreach (['a', 'b'] as $id) {
        $process = proc_open([PHP_BINARY, __DIR__.'/race-complete.php', $payload, $directory, $id], [
          0 => ['file', '/dev/null', 'r'],
          1 => ['file', $directory.'/stdout-'.$id, 'w'],
          2 => ['file', $directory.'/stderr-'.$id, 'w'],
        ], $pipes);
        if (!is_resource($process)) {
          throw new \RuntimeException('Could not launch concurrent Drupal worker.');
        }
        $processes[$id] = $process;
      }
      $deadline = microtime(TRUE) + 10;
      while (!is_file($directory.'/ready-a') || !is_file($directory.'/ready-b')) {
        if (microtime(TRUE) > $deadline) {
          throw new \RuntimeException('Concurrent Drupal boot failed: '.file_get_contents($directory.'/stderr-a').file_get_contents($directory.'/stderr-b'));
        }
        usleep(10_000);
        clearstatcache();
      }
      file_put_contents($directory.'/go', 'go');
      $results = [];
      foreach ($processes as $id => $process) {
        $exit = proc_close($process);
        unset($processes[$id]);
        $this->same(0, $exit, 'Concurrent worker '.$id.' must finish successfully: '.file_get_contents($directory.'/stderr-'.$id));
        $results[$id] = json_decode(file_get_contents($directory.'/stdout-'.$id), TRUE, flags: JSON_THROW_ON_ERROR);
        $this->same('completed', $results[$id]['status'], 'Both callers observe the same committed completion.');
      }
      $this->same($results['a'], $results['b'], 'Racing callers receive an identical result, not separate executions.');
    }
    finally {
      foreach ($processes as $process) {
        proc_terminate($process);
        proc_close($process);
      }
      foreach (glob($directory.'/*') as $file) {
        unlink($file);
      }
      rmdir($directory);
    }
    $this->same($before + 1, $countRevisions(), 'Concurrent completions create exactly one publication revision.');
    foreach (['exact_approval_executions', 'exact_approval_evidence'] as $table) {
      $count = (int) \Drupal::database()->select($table, 'e')->condition('request_id', $pending->request->id)->countQuery()->execute()->fetchField();
      $this->same(1, $count, 'Concurrent completion commits one '.$table.' row.');
    }
    $this->check($this->fresh($node)->isPublished(), 'The one committed result is visible in Drupal.');
  }

  private function revokedCredential(): void {
    $node = $this->node();
    $state = $this->approvals->request($this->actor, 'node.publish', ['node_id' => (int) $node->id()]);
    $ceremony = $this->approvals->begin($this->actor, $state->request->id);
    $this->credentials->revokeForActor($this->actor, $this->authenticator->id());
    $this->expectApprovalFailure(fn () => $this->approvals->complete($this->actor, $ceremony->id, $this->authenticator->assertion($ceremony->options)));
    $this->check(!$this->fresh($node)->isPublished(), 'Revoked key does not publish.');
    $this->check($this->credentials->find($this->authenticator->id())->revoked, 'Revocation retains the historical public credential.');
  }

  private function approve(string $action, NodeInterface $node, array $extra = []): ApprovalState {
    $state = $this->approvals->request($this->actor, $action, ['node_id' => (int) $node->id()] + $extra);
    $ceremony = $this->approvals->begin($this->actor, $state->request->id);
    return $this->approvals->complete($this->actor, $ceremony->id, $this->authenticator->assertion($ceremony->options));
  }

  private function user(string $suffix, array $roles): UserInterface {
    $user = User::create(['name' => $this->prefix.'_'.$suffix, 'mail' => $this->prefix.'.'.$suffix.'@example.invalid', 'status' => 1, 'roles' => $roles]);
    $user->setPassword(bin2hex(random_bytes(32)));
    $user->save();
    return $user;
  }

  private function node(bool $moderated = FALSE): NodeInterface {
    return $this->asUser($this->editor, function () use ($moderated): NodeInterface {
      $node = Node::create(['type' => $moderated ? $this->moderated : $this->plain, 'title' => 'Fictional approval test '.bin2hex(random_bytes(5)), 'uid' => $this->editor->id(), 'status' => FALSE]);
      if ($moderated) {
        $node->set('moderation_state', 'draft');
      }
      $node->save();
      return $node;
    });
  }

  private function fresh(NodeInterface $node): ?NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
  }

  private function asUser(UserInterface $user, callable $work): mixed {
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($user);
    try {
      return $work();
    }
    finally {
      $switcher->switchBack();
    }
  }

  private function scenario(string $name, callable $work): void {
    $before = $this->assertions;
    try {
      $work();
      $this->outcomes[] = ['name' => $name, 'status' => 'passed', 'assertions' => $this->assertions - $before];
      fwrite(STDERR, 'PASS '.$name."\n");
    }
    catch (\Throwable $error) {
      $chain = [];
      for ($cause = $error; $cause !== NULL; $cause = $cause->getPrevious()) {
        $chain[] = get_class($cause).': '.$cause->getMessage();
      }
      $this->outcomes[] = ['name' => $name, 'status' => 'failed', 'assertions' => $this->assertions - $before, 'error' => $chain, 'location' => $error->getFile().':'.$error->getLine()];
      fwrite(STDERR, 'FAIL '.$name.': '.implode(' <- ', $chain)."\n");
    }
  }

  private function expectApprovalFailure(callable $work, array $reasons = []): ApprovalException {
    try {
      $work();
    }
    catch (\Throwable $error) {
      for ($cause = $error; $cause !== NULL; $cause = $cause->getPrevious()) {
        if ($cause instanceof ApprovalException) {
          $this->check($reasons === [] || in_array($cause->reason, $reasons, TRUE), 'Expected reason '.implode('|', $reasons).', received '.$cause->reason.'.');
          return $cause;
        }
      }
      throw new \RuntimeException('Failure was unrelated to approval enforcement: '.$error->getMessage(), 0, $error);
    }
    throw new \RuntimeException('Expected approval failure, but the operation succeeded.');
  }

  private function same(mixed $expected, mixed $actual, string $message): void {
    // Proof persistence intentionally reconstructs object-valued JSON maps.
    $this->check($expected === $actual || (is_array($expected) && is_array($actual) && CanonicalJson::encode((object) $expected) === CanonicalJson::encode((object) $actual)), $message.' Expected '.json_encode($expected).'; got '.json_encode($actual).'.');
  }

  private function check(bool $condition, string $message): void {
    ++$this->assertions;
    if (!$condition) {
      throw new \RuntimeException($message);
    }
  }
}

exit((new ExactApprovalDrupalAcceptance($origin))->run());
