<?php

declare(strict_types=1);

namespace Drupal\Tests\verified_human_approval\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\KernelTests\KernelTestBase;
use Drupal\verified_human_approval\Clock;
use Drupal\verified_human_approval\Storage\DrupalApprovalStore;
use Drupal\verified_human_approval\Storage\DrupalAuditStore;
use Drupal\verified_human_approval\Storage\DrupalCredentialRepository;
use Drupal\verified_human_approval\Storage\DrupalTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalPolicy;
use VerifiedHumanApproval\Core\ApprovalRequest;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Ceremony;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Credential;

/** Exercises the native adapters through two connections to one SQLite file. */
#[Group('verified_human_approval')]
#[RunTestsInSeparateProcesses]
final class StorageConcurrencyTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'sqlite',
    'verified_human_approval',
  ];

  private string $databasePath;
  private Connection $first;
  private Connection $other;
  private DrupalTransaction $transactions;
  private DrupalApprovalStore $store;
  private DrupalCredentialRepository $credentials;
  private AuditLog $audit;
  private SodiumEvidenceSigner $signer;
  private Actor $actor;

  protected function setUp(): void {
    parent::setUp();
    $path = tempnam(sys_get_temp_dir(), 'vha-drupal-storage-');
    if ($path === FALSE) {
      throw new \RuntimeException('Could not create the isolated SQLite database.');
    }
    $this->databasePath = $path;
    $journal = $this->providedData()[0] ?? 'WAL';
    $options = [
      'driver' => 'sqlite', 'database' => $path, 'prefix' => '',
      'namespace' => 'Drupal\\sqlite\\Driver\\Database\\sqlite',
      'init_commands' => ['wal' => 'PRAGMA journal_mode=' . $journal],
    ];
    Database::addConnectionInfo('vha_storage_first', 'default', $options);
    Database::addConnectionInfo('vha_storage_other', 'default', $options);
    $this->first = Database::getConnection('default', 'vha_storage_first');
    $this->other = Database::getConnection('default', 'vha_storage_other');
    $this->first->query('PRAGMA busy_timeout = 0');
    $this->other->query('PRAGMA busy_timeout = 0');
    $this->container->get('module_handler')->loadInclude('verified_human_approval', 'install');
    foreach (verified_human_approval_schema() as $table => $definition) {
      $this->first->schema()->createTable($table, $definition);
    }
    $this->first->schema()->createTable('ordinary_content', [
      'fields' => [
        'id' => ['type' => 'int', 'not null' => TRUE],
        'version' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['id'],
    ]);
    $this->first->insert('ordinary_content')->fields(['id' => 1, 'version' => 0])->execute();
    $this->transactions = new DrupalTransaction($this->first, $this->container->get('entity_type.manager'));
    $this->store = new DrupalApprovalStore($this->first, $this->transactions);
    $this->credentials = new DrupalCredentialRepository($this->first, $this->transactions);
    $this->signer = new SodiumEvidenceSigner('storage-test', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    $this->audit = new AuditLog(new DrupalAuditStore($this->first, $this->transactions), $this->signer, $this->signer->publicKey(), new Clock());
    $this->actor = new Actor('storage-user', 'storage-site');
  }

  protected function tearDown(): void {
    unset($this->audit, $this->credentials, $this->store, $this->transactions, $this->first, $this->other);
    Database::removeConnection('vha_storage_first');
    Database::removeConnection('vha_storage_other');
    if (isset($this->databasePath)) {
      foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $path) {
        if (is_file($path)) {
          unlink($path);
        }
      }
    }
    parent::tearDown();
  }

  /** @return iterable<string, array{string}> */
  public static function journals(): iterable {
    yield 'rollback journal' => ['DELETE'];
    yield 'WAL' => ['WAL'];
  }

  #[DataProvider('journals')]
  public function testWriterReservationPrecedesTheCallbackAndBlocksOrdinaryWriters(string $journal): void {
    self::assertSame(strtolower($journal), $this->first->query('PRAGMA journal_mode')->fetchField());
    self::assertSame(strtolower($journal), $this->other->query('PRAGMA journal_mode')->fetchField());
    self::assertSame(0, $this->countRows('vha_mutex'), 'An empty mutex table must still reserve the SQLite writer.');
    $calls = 0;
    $result = $this->transactions->run(function () use (&$calls): string {
      ++$calls;
      self::assertTrue($this->transactions->isActive());
      // There have been no approval, credential, audit or content writes yet.
      // This ordinary Drupal writer does not acquire the module's own lock.
      try {
        $this->other->update('ordinary_content')->fields(['version' => 1])->condition('id', 1)->execute();
        self::fail('An ordinary writer entered before the approval callback finished.');
      }
      catch (DatabaseExceptionWrapper $error) {
        self::assertStringContainsString('database is locked', $error->getMessage());
      }
      return 'committed';
    });
    self::assertSame('committed', $result);
    self::assertSame(1, $calls, 'A contention failure must not retry the protected callback.');
    self::assertFalse($this->first->inTransaction());
    self::assertFalse($this->transactions->isActive());
    self::assertSame(1, $this->other->update('ordinary_content')->fields(['version' => 2])->condition('id', 1)->execute(), 'The other writer can proceed after the root commit.');
    self::assertSame(2, (int) $this->first->select('ordinary_content', 'c')->fields('c', ['version'])->execute()->fetchField());
  }

  public function testEnrollmentAnchorAndGrantConsumptionRollBackTogether(): void {
    $credential = $this->credential();
    $tokenHash = hash('sha256', 'synthetic-user-bound-grant');
    $this->transactions->run(function () use ($tokenHash): void {
      $this->first->insert('vha_enrollment_grant')->fields([
        'token_hash' => $tokenHash, 'user_uuid' => $this->actor->userId,
        'tenant_id' => $this->actor->tenantId, 'issuer_uuid' => 'storage-admin',
        'expires' => time() + 600, 'consumed' => 0,
      ])->execute();
    });
    $anchored = new AnchoredCredentialRepository($this->credentials, $this->audit);
    $failure = new \RuntimeException('Fail after all enrollment writes.');
    try {
      $this->transactions->run(function () use ($credential, $tokenHash, $anchored, $failure): void {
        $this->credentials->add($credential);
        $anchored->anchor($credential, ['method' => 'administrator-issued-grant']);
        $this->first->update('vha_enrollment_grant')->fields(['consumed' => 1])->condition('token_hash', $tokenHash)->execute();
        self::assertNotNull($anchored->find($credential->id));
        self::assertTrue($this->first->inTransaction(), 'The audit append must join the root transaction.');
        self::assertSame(0, $this->countRows('vha_credential', $this->other), 'The second connection cannot see uncommitted enrollment.');
        throw $failure;
      });
      self::fail('The injected enrollment failure was swallowed.');
    }
    catch (\RuntimeException $error) {
      self::assertSame($failure, $error, $error->getMessage());
    }
    self::assertFalse($this->first->inTransaction());
    self::assertNull($this->credentials->find($credential->id));
    self::assertNull($anchored->find($credential->id));
    self::assertSame(0, $this->countRows('vha_credential', $this->other));
    self::assertSame(0, $this->countRows('vha_audit', $this->other));
    self::assertSame(0, (int) $this->other->select('vha_enrollment_grant', 'g')->fields('g', ['consumed'])->condition('token_hash', $tokenHash)->execute()->fetchField());
  }

  public function testApprovalEvidenceAndCredentialWritesRollBackTogether(): void {
    $pending = $this->pending();
    $credential = $this->credential();
    $ceremony = new Ceremony('ceremony-storage', $pending->request->id, 'nonce', 'challenge', '2030-01-01T00:00:00.000000Z', ['challenge' => 'challenge']);
    $this->store->transaction(function () use ($pending, $credential, $ceremony): void {
      $this->store->insert($pending, 'storage-idempotency', hash('sha256', 'storage-fingerprint'));
      $this->store->putCeremony($ceremony);
      $this->credentials->add($credential);
    });
    $manifest = '{"original":"receipt bytes"}';
    $completed = new ApprovalState($pending->request, 'completed', result: ['published' => TRUE], execution: ['revision' => 2], proof: ['credentialId' => $credential->id], receipt: [
      'keyId' => $this->signer->keyId(), 'manifest' => $manifest, 'signature' => $this->signer->sign($manifest),
    ]);
    $failure = new \RuntimeException('Fail after all approval writes.');
    try {
      $this->store->transaction(function () use ($credential, $ceremony, $completed, $failure): void {
        $this->credentials->update($this->credential(1), $credential->sourceJson);
        $this->store->retireCeremony($ceremony->id);
        $this->first->update('ordinary_content')->fields(['version' => 1])->condition('id', 1)->execute();
        $this->store->save($completed);
        $this->audit->append($this->actor->tenantId, 'storage.completed', $completed->request->id, []);
        $storedReceipt = $this->store->get($completed->request->id)->receipt;
        foreach ($completed->receipt as $field => $bytes) {
          self::assertSame($bytes, $storedReceipt[$field]);
        }
        self::assertSame(1, $this->countRows('vha_execution'));
        self::assertSame(0, $this->countRows('vha_execution', $this->other));
        throw $failure;
      });
      self::fail('The injected approval failure was swallowed.');
    }
    catch (\RuntimeException $error) {
      self::assertSame($failure, $error, $error->getMessage());
    }
    self::assertFalse($this->first->inTransaction());
    $stored = $this->store->get($pending->request->id);
    self::assertSame('pending', $stored->status);
    self::assertSame($pending->request->canonical, $stored->request->canonical);
    self::assertNull($stored->proof);
    self::assertNull($stored->execution);
    self::assertNull($stored->receipt);
    self::assertFalse($this->store->getCeremony($ceremony->id)->consumed);
    self::assertSame($credential->sourceJson, $this->credentials->find($credential->id)->sourceJson);
    foreach (['vha_execution', 'vha_receipt', 'vha_audit'] as $table) {
      self::assertSame(0, $this->countRows($table, $this->other));
    }
    self::assertSame('pending', $this->other->select('vha_request', 'r')->fields('r', ['status'])->execute()->fetchField());
    self::assertSame(0, (int) $this->other->select('ordinary_content', 'c')->fields('c', ['version'])->execute()->fetchField());
  }

  public function testZeroCounterCompareAndReplaceRequiresTheMatchingActiveOwner(): void {
    $credential = $this->credential();
    $this->transactions->run(fn () => $this->credentials->add($credential));
    $this->transactions->run(function () use ($credential): void {
      $this->credentials->update($credential, $credential->sourceJson);
      $this->credentials->update($credential, $credential->sourceJson);
    });
    self::assertSame($credential->sourceJson, $this->credentials->find($credential->id)->sourceJson);
    $attempts = [
      [$credential, 'stale source'],
      [new Credential($credential->id, 'other-user', $credential->tenantId, $credential->sourceJson), $credential->sourceJson],
      [new Credential($credential->id, $credential->userId, 'other-site', $credential->sourceJson), $credential->sourceJson],
      [new Credential($credential->id, $credential->userId, $credential->tenantId, $credential->sourceJson, TRUE), $credential->sourceJson],
    ];
    foreach ($attempts as [$replacement, $expected]) {
      try {
        $this->transactions->run(fn () => $this->credentials->update($replacement, $expected));
        self::fail('Unchanged bytes must not bypass ownership, revocation or expected-source checks.');
      }
      catch (ApprovalException $error) {
        self::assertSame('credential_changed', $error->reason);
      }
    }
    self::assertTrue($this->transactions->run(fn () => $this->credentials->revoke($this->actor, $credential->id)));
    try {
      $this->transactions->run(fn () => $this->credentials->update($credential, $credential->sourceJson));
      self::fail('A revoked zero-counter credential must not match.');
    }
    catch (ApprovalException $error) {
      self::assertSame('credential_changed', $error->reason);
    }
    self::assertTrue($this->credentials->find($credential->id)->revoked);
    self::assertSame($credential->sourceJson, $this->other->select('vha_credential', 'c')->fields('c', ['source_json'])->execute()->fetchField());
  }

  private function credential(int $counter = 0): Credential {
    return new Credential('storage-passkey', $this->actor->userId, $this->actor->tenantId, json_encode([
      'publicKeyCredentialId' => 'aWQ', 'credentialPublicKey' => 'a2V5', 'userHandle' => 'dXNlcg', 'counter' => $counter,
    ], JSON_THROW_ON_ERROR), FALSE, 'Storage test passkey');
  }

  private function pending(): ApprovalState {
    $action = new PreparedAction('storage.publish', '1', ['id' => 1], ['published' => TRUE], ['version' => 0], ['title' => 'Stored draft']);
    $request = ApprovalRequest::create($this->actor, $action, new ApprovalPolicy('storage.publish', '1', 'Publish the stored draft.'), new \DateTimeImmutable(), 'en');
    return new ApprovalState($request);
  }

  private function countRows(string $table, ?Connection $database = NULL): int {
    return (int) ($database ?? $this->first)->select($table)->countQuery()->execute()->fetchField();
  }

}
