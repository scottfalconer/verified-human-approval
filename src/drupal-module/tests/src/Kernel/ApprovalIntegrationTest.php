<?php

declare(strict_types=1);

namespace Drupal\Tests\verified_human_approval\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\verified_human_approval\Support\VirtualAuthenticator;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\verified_human_approval\ApprovalManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;

/** Real Drupal entities, storage and WebAuthn, using synthetic signing hardware. */
#[Group('verified_human_approval')]
#[RunTestsInSeparateProcesses]
final class ApprovalIntegrationTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'sqlite',
    'verified_human_approval', 'verified_human_approval_test',
  ];

  private UserInterface $admin;
  private UserInterface $editor;
  private NodeInterface $draft;
  private VirtualAuthenticator $authenticator;
  private string $privateDirectory;
  private Session $browserSession;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('verified_human_approval', [
      'vha_mutex', 'vha_request', 'vha_execution', 'vha_receipt',
      'vha_ceremony', 'vha_credential', 'vha_audit', 'vha_enrollment_grant',
    ]);
    $this->installConfig(['system', 'user', 'node', 'filter', 'field']);
    $this->container->get('config.factory')->getEditable('system.site')->set('uuid', 'f130ce56-71d4-43c0-b180-f600c205a591')->save();

    $this->privateDirectory = sys_get_temp_dir() . '/vha-drupal-' . bin2hex(random_bytes(8));
    mkdir($this->privateDirectory, 0700);
    $pair = sodium_crypto_sign_keypair();
    file_put_contents($this->privateDirectory . '/secret.key', ChallengeBinding::encode(sodium_crypto_sign_secretkey($pair)));
    chmod($this->privateDirectory . '/secret.key', 0600);
    file_put_contents($this->privateDirectory . '/public.json', json_encode(['keyId' => 'drupal-test', 'publicKey' => ChallengeBinding::encode(sodium_crypto_sign_publickey($pair))], JSON_THROW_ON_ERROR));
    $values = Settings::getAll();
    $values['verified_human_approval'] = [
      'origin' => 'https://approval.test', 'rp_id' => 'approval.test',
      'signing_key_file' => $this->privateDirectory . '/secret.key',
      'public_key_file' => $this->privateDirectory . '/public.json',
      'protected_bundles' => ['page'], 'lifetime_seconds' => 600,
    ];
    new Settings($values);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'type' => 'text_with_summary'])->save();
    FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => 'page', 'label' => 'Body'])->save();
    Role::create([
      'id' => 'approval_editor', 'label' => 'Approval editor',
      'permissions' => [
        'access content', 'edit any page content', 'view own unpublished content',
        'administer node published status', 'request verified human approval', 'use verified human approval',
      ],
    ])->save();
    $this->admin = User::create(['name' => 'admin', 'status' => 1]);
    $this->admin->save();
    $this->editor = User::create(['name' => 'editor', 'status' => 1, 'roles' => ['approval_editor']]);
    $this->editor->save();
    $this->browserSession = new Session(new MockArraySessionStorage());
    $request = Request::create('https://approval.test/verified-human-approval');
    $request->setSession($this->browserSession);
    $this->container->get('request_stack')->push($request);

    $this->login($this->admin);
    $grant = $this->passkeys()->issueEnrollmentGrant((int) $this->editor->id());
    $this->login($this->editor);
    $this->authenticator = new VirtualAuthenticator();
    $options = $this->passkeys()->registrationOptions($grant['token'], 'Test passkey');
    $this->passkeys()->register($options['ceremony_id'], $this->authenticator->registration($options['publicKey']));

    $this->draft = Node::create([
      'type' => 'page', 'title' => 'The reviewed draft', 'status' => 0,
      'uid' => $this->editor->id(), 'langcode' => 'en',
      'body' => ['value' => 'The exact approved body.', 'summary' => 'A plain summary.', 'format' => 'plain_text'],
    ]);
    $this->draft->save();
  }

  protected function tearDown(): void {
    if (isset($this->privateDirectory)) {
      foreach (glob($this->privateDirectory . '/*') ?: [] as $file) {
        unlink($file);
      }
      rmdir($this->privateDirectory);
    }
    parent::tearDown();
  }

  public function testPublishesOnceWithRealWebAuthnAndOriginalReceipt(): void {
    $pending = $this->request();
    self::assertSame('pending', $pending->status);
    self::assertFalse($this->freshNode()->isPublished());
    $ceremony = $this->manager()->begin($pending->request->id);
    $assertion = $this->authenticator->assertion($ceremony->options);
    $done = $this->manager()->complete($ceremony->id, $assertion);
    self::assertSame('completed', $done->status);
    self::assertTrue($this->freshNode()->isPublished());
    self::assertSame('The reviewed draft', $this->freshNode()->getTitle());
    self::assertNotSame((int) $this->draft->getRevisionId(), (int) $this->freshNode()->getRevisionId());
    self::assertSame(1, $this->countRows('vha_execution'));
    self::assertSame(1, $this->countRows('vha_receipt'));
    $again = $this->manager()->complete($ceremony->id, $assertion);
    self::assertEquals($done->result, $again->result);
    self::assertSame(1, $this->countRows('vha_execution'));
    $bundle = $this->manager()->receipt($pending->request->id);
    $trust = $this->manager()->publicKey();
    self::assertSame('approval.test', $trust['rpId']);
    self::assertSame('https://approval.test', $trust['origin']);
    $verified = (new ReceiptVerifier())->verify($bundle, $trust['keyId'], $trust['publicKey']);
    self::assertTrue($verified['applicationSignatureValid']);
    self::assertSame('commit', $verified['receiptSignedAt']);
    // Reading the original evidence needs only the public trust key.
    unlink($this->privateDirectory . '/secret.key');
    self::assertEquals($bundle, $this->manager()->receipt($pending->request->id));
    self::assertSame($trust, $this->manager()->publicKey());
  }

  public function testChangedDraftInvalidatesApproval(): void {
    $pending = $this->request();
    $node = $this->freshNode();
    $node->setTitle('Changed after review');
    $node->setNewRevision(TRUE);
    $node->save();
    try {
      $this->manager()->begin($pending->request->id);
      self::fail('A changed draft must not start approval.');
    }
    catch (ApprovalException $error) {
      self::assertSame('stale_resource', $error->reason);
    }
    self::assertFalse($this->freshNode()->isPublished());
    self::assertSame('invalidated', $this->manager()->get($pending->request->id)->status);
  }

  public function testCurrentPublicationPermissionIsRechecked(): void {
    $pending = $this->request();
    $ceremony = $this->manager()->begin($pending->request->id);
    $role = Role::load('approval_editor');
    $role->revokePermission('administer node published status')->save();
    $done = $this->manager()->complete($ceremony->id, $this->authenticator->assertion($ceremony->options));
    self::assertSame('denied', $done->status);
    self::assertFalse($this->freshNode()->isPublished());
    self::assertSame(0, $this->countRows('vha_execution'));
  }

  public function testOtherAccountCannotReadOrComplete(): void {
    $pending = $this->request();
    $ceremony = $this->manager()->begin($pending->request->id);
    $this->login($this->admin);
    foreach ([
      fn () => $this->manager()->get($pending->request->id),
      fn () => $this->manager()->complete($ceremony->id, $this->authenticator->assertion($ceremony->options)),
    ] as $attempt) {
      try {
        $attempt();
        self::fail('Another account must not access an approval.');
      }
      catch (ApprovalException $error) {
        self::assertSame('not_found', $error->reason);
      }
    }
    self::assertSame(0, $this->countRows('vha_execution'));
  }

  public function testInvalidAssertionDoesNotPublish(): void {
    $pending = $this->request();
    $ceremony = $this->manager()->begin($pending->request->id);
    try {
      $this->manager()->complete($ceremony->id, $this->authenticator->assertion($ceremony->options, ['origin' => 'https://attacker.test']));
      self::fail('An assertion for another origin must fail.');
    }
    catch (ApprovalException) {
      self::assertFalse($this->freshNode()->isPublished());
      self::assertSame(0, $this->countRows('vha_execution'));
    }
  }

  public function testOrdinaryEntitySaveCannotBypassApproval(): void {
    $this->draft->setPublished();
    try {
      $this->draft->save();
      self::fail('An unguarded save must fail.');
    }
    catch (\Throwable $error) {
      self::assertStringContainsString('requires verified publication', $error->getMessage());
    }
    self::assertFalse($this->freshNode()->isPublished());
  }

  public function testPresaveChangedEffectRollsBackCredentialAndNode(): void {
    $pending = $this->request();
    $ceremony = $this->manager()->begin($pending->request->id);
    $before = $this->container->get('verified_human_approval.raw_credentials')->find($this->authenticator->id())->sourceJson;
    $this->container->get('state')->set('vha_test.change_title', TRUE);
    try {
      $this->manager()->complete($ceremony->id, $this->authenticator->assertion($ceremony->options));
      self::fail('Changed approved content must roll back.');
    }
    catch (ApprovalException $error) {
      self::assertSame('execution_failed', $error->reason);
    }
    self::assertFalse($this->freshNode()->isPublished());
    self::assertSame('The reviewed draft', $this->freshNode()->getTitle());
    self::assertSame(0, $this->countRows('vha_execution'));
    self::assertSame(0, $this->countRows('vha_receipt'));
    self::assertSame($before, $this->container->get('verified_human_approval.raw_credentials')->find($this->authenticator->id())->sourceJson);
  }

  public function testReceiptInsertFailureRollsBackPublication(): void {
    $pending = $this->request();
    $ceremony = $this->manager()->begin($pending->request->id);
    $before = $this->container->get('verified_human_approval.raw_credentials')->find($this->authenticator->id())->sourceJson;
    // Kernel tests use an attached SQLite database. A TEMP trigger can target
    // that database while remaining local to this test's connection.
    $this->container->get('database')->query("CREATE TEMP TRIGGER vha_test_receipt_failure BEFORE INSERT ON {vha_receipt} BEGIN SELECT RAISE(ABORT, 'injected receipt failure'); END", [], ['allow_delimiter_in_query' => TRUE]);
    try {
      $this->manager()->complete($ceremony->id, $this->authenticator->assertion($ceremony->options));
      self::fail('A missing commit receipt must roll back publication.');
    }
    catch (ApprovalException $error) {
      self::assertSame('execution_failed', $error->reason);
    }
    self::assertFalse($this->freshNode()->isPublished());
    self::assertSame(0, $this->countRows('vha_execution'));
    self::assertSame(0, $this->countRows('vha_receipt'));
    self::assertSame($before, $this->container->get('verified_human_approval.raw_credentials')->find($this->authenticator->id())->sourceJson);
  }

  public function testTamperedExecutionCannotBeDownloadedAsValidEvidence(): void {
    $pending = $this->request();
    $ceremony = $this->manager()->begin($pending->request->id);
    $this->manager()->complete($ceremony->id, $this->authenticator->assertion($ceremony->options));
    $db = $this->container->get('database');
    $record = $db->select('vha_execution', 'e')->fields('e', ['record'])->execute()->fetchField();
    $db->update('vha_execution')->fields(['record' => str_replace('"published"', '"not_published"', $record)])->execute();
    $this->expectException(ApprovalException::class);
    $this->expectExceptionMessage('does not verify');
    $this->manager()->receipt($pending->request->id);
  }

  public function testRevocationIsAtomicWhenAuditSigningFails(): void {
    unlink($this->privateDirectory . '/secret.key');
    try {
      $this->passkeys()->revoke($this->authenticator->id());
      self::fail('Signing failure must roll back revocation.');
    }
    catch (ApprovalException) {
      $credential = $this->container->get('verified_human_approval.raw_credentials')->find($this->authenticator->id());
      self::assertFalse($credential->revoked);
    }
  }

  public function testAmbientTransactionIsRefused(): void {
    $transaction = $this->container->get('database')->startTransaction('host_operation');
    try {
      $this->request();
      self::fail('Approval must own its root transaction.');
    }
    catch (\LogicException $error) {
      self::assertStringContainsString('outer Drupal', $error->getMessage());
    }
    finally {
      $transaction->rollBack();
    }
    self::assertSame(0, $this->countRows('vha_request'));
  }

  public function testBearerCannotUseBrowserEnrollment(): void {
    $this->container->get('request_stack')->getCurrentRequest()->headers->set('Authorization', 'Bearer synthetic-agent-token');
    $this->expectException(ApprovalException::class);
    $this->expectExceptionMessage('browser login');
    $this->passkeys()->credentials();
  }

  public function testLateGenericPresaveCannotPublishWithoutApproval(): void {
    $this->container->get('state')->set('vha_test.late_publish', TRUE);
    $this->draft->setTitle('A normal draft edit');
    try {
      $this->draft->save();
      self::fail('A generic presave hook must not bypass approval.');
    }
    catch (\Throwable $error) {
      self::assertStringContainsString('requires verified publication', $error->getMessage());
    }
    self::assertFalse($this->freshNode()->isPublished());
    self::assertSame('The reviewed draft', $this->freshNode()->getTitle());
    self::assertSame(0, $this->countRows('vha_execution'));
  }

  public function testKeyedReplayRechecksCurrentReadPermission(): void {
    $this->request();
    Role::load('approval_editor')->revokePermission('use verified human approval')->save();
    $this->expectException(ApprovalException::class);
    $this->expectExceptionMessage('cannot access approval records');
    $this->request();
  }

  public function testLateHookCannotPublishANonDefaultRevision(): void {
    $before = $this->countRows('node_revision');
    $this->container->get('state')->set('vha_test.late_publish', TRUE);
    $this->draft->setNewRevision(TRUE);
    $this->draft->isDefaultRevision(FALSE);
    $this->draft->setTitle('A non-default draft edit');
    try {
      $this->draft->save();
      self::fail('A later hook must not publish a non-default revision.');
    }
    catch (\Throwable $error) {
      self::assertStringContainsString('requires verified publication', $error->getMessage());
    }
    self::assertFalse($this->freshNode()->isPublished());
    self::assertSame($before, $this->countRows('node_revision'));
    self::assertSame(0, (int) $this->container->get('database')->select('node_field_revision', 'r')->condition('status', 1)->countQuery()->execute()->fetchField());
  }

  public function testMultipleBodyItemsAreRefused(): void {
    FieldStorageConfig::loadByName('node', 'body')->setCardinality(2)->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->expectException(ApprovalException::class);
    $this->expectExceptionMessage('single text_with_summary body');
    $this->request();
  }

  public function testEnrollmentSigningFailureRollsBackCredentialAndGrantUse(): void {
    $this->login($this->admin);
    $grant = $this->passkeys()->issueEnrollmentGrant((int) $this->editor->id());
    $this->login($this->editor);
    $options = $this->passkeys()->registrationOptions($grant['token'], 'Second passkey');
    $authenticator = new VirtualAuthenticator();
    $auditCount = $this->countRows('vha_audit');
    unlink($this->privateDirectory . '/secret.key');
    try {
      $this->passkeys()->register($options['ceremony_id'], $authenticator->registration($options['publicKey']));
      self::fail('Enrollment must not survive an audit signing failure.');
    }
    catch (ApprovalException) {
      self::assertSame(1, $this->countRows('vha_credential'));
      self::assertSame($auditCount, $this->countRows('vha_audit'));
      $used = $this->container->get('database')->select('vha_enrollment_grant', 'g')->fields('g', ['consumed'])->condition('token_hash', hash('sha256', $grant['token']))->execute()->fetchField();
      self::assertSame(0, (int) $used);
    }
  }

  private function request(): \VerifiedHumanApproval\Core\ApprovalState {
    return $this->manager()->requestPublish((int) $this->draft->id(), (int) $this->draft->getRevisionId(), 'en', 'test-publish');
  }

  private function manager(): ApprovalManager {
    return $this->container->get('verified_human_approval.approval_manager');
  }

  private function passkeys(): \Drupal\verified_human_approval\PasskeyManager {
    return $this->container->get('verified_human_approval.passkey_manager');
  }

  private function login(UserInterface $user): void {
    $this->container->get('current_user')->setAccount($user);
    $this->browserSession->set('uid', (int) $user->id());
  }

  private function freshNode(): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$this->draft->id()]);
    return $storage->load($this->draft->id());
  }

  private function countRows(string $table): int {
    return (int) $this->container->get('database')->select($table)->countQuery()->execute()->fetchField();
  }

}

