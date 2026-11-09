<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\user\UserInterface;
use Drupal\verified_human_approval\Storage\DrupalTransaction;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/** Account-bound enrollment grants and signed enrollment share the Drupal DB. */
final class PasskeyManager {

  private const SESSION_KEY = 'verified_human_approval.registration';

  public function __construct(
    private readonly ContainerInterface $container,
    private readonly Connection $database,
    private readonly DrupalTransaction $transactions,
    private readonly ActorProviderInterface $actors,
    private readonly EntityTypeManagerInterface $entities,
    private readonly RequestStack $requests,
    private readonly Clock $clock,
    private readonly FloodInterface $flood,
  ) {}

  /** @return list<Credential> */
  public function credentials(): array {
    // Listing passkeys only reads, so it takes no writer lock.
    $actor = $this->actors->interactive();
    $this->assertUser($actor);
    return $this->repository()->forActor($actor);
  }

  /** @return array{token: string, expires_at: int, uid: int} */
  public function issueEnrollmentGrant(int $uid): array {
    $actor = $this->actors->interactive();
    $this->limit($actor, 'grant', 10);
    return $this->transactions->run(function () use ($actor, $uid): array {
      $issuer = $this->actors->accountFor($actor);
      if (!$issuer->hasPermission('administer verified human approval')) {
        throw new ApprovalException('permission_denied', 'Only an authorized administrator can issue enrollment grants.');
      }
      $storage = $this->entities->getStorage('user');
      $storage->resetCache([$uid]);
      $user = $storage->load($uid);
      if (!$user instanceof UserInterface || !$user->isActive() || $user->isAnonymous() || !$user->hasPermission('use verified human approval')) {
        throw new ApprovalException('invalid_account', 'Choose an active account with permission to use verified human approval.');
      }
      $token = bin2hex(random_bytes(32));
      $hash = hash('sha256', $token);
      $expires = $this->clock->now()->getTimestamp() + 600;
      $record = [
        'token_hash' => $hash, 'user_uuid' => $user->uuid(),
        'tenant_id' => $actor->tenantId, 'issuer_uuid' => $actor->userId,
        'expires' => $expires, 'consumed' => 0,
      ];
      $this->database->insert('vha_enrollment_grant')->fields($record)->execute();
      $this->audit()->append($actor->tenantId, 'enrollment.granted', $hash, [
        'user_uuid' => $user->uuid(), 'issuer_uuid' => $actor->userId, 'expires' => $expires,
      ]);
      return ['token' => $token, 'expires_at' => $expires, 'uid' => $uid];
    });
  }

  /** @return array{ceremony_id: string, publicKey: array<string, mixed>} */
  public function registrationOptions(string $grant, string $label): array {
    $actor = $this->actors->interactive();
    $this->limit($actor, 'enroll_options', 10);
    if (!preg_match('/^[a-f0-9]{64}$/D', $grant) || trim($label) === '' || strlen($label) > 200) {
      throw new ApprovalException('invalid_enrollment', 'Provide your enrollment grant and a passkey name of at most 200 bytes.');
    }
    $options = $this->transactions->run(function () use ($actor, $grant): array {
      $user = $this->assertUser($actor);
      $this->grant($actor, hash('sha256', $grant));
      return $this->verifier()->registrationOptions($actor, random_bytes(32), $user->getDisplayName());
    });
    $id = bin2hex(random_bytes(16));
    $this->session()->set(self::SESSION_KEY, [
      'id' => $id, 'actor' => $actor->key(), 'grant_hash' => hash('sha256', $grant),
      'expires' => $this->clock->now()->getTimestamp() + 120,
      'label' => $label, 'options' => $options,
    ]);
    return ['ceremony_id' => $id, 'publicKey' => $options];
  }

  public function register(string $ceremonyId, array $response): Credential {
    $actor = $this->actors->interactive();
    $this->limit($actor, 'enroll_complete', 10);
    $saved = $this->session()->remove(self::SESSION_KEY);
    if (!is_array($saved) || !is_string($saved['id'] ?? NULL) || !hash_equals($saved['id'], $ceremonyId) || ($saved['actor'] ?? NULL) !== $actor->key() || ($saved['expires'] ?? 0) <= $this->clock->now()->getTimestamp()) {
      throw new ApprovalException('enrollment_expired', 'Start a fresh passkey enrollment from your account page.');
    }
    return $this->transactions->run(function () use ($actor, $saved, $response): Credential {
      $this->assertUser($actor);
      $grant = $this->grant($actor, $saved['grant_hash']);
      $credential = $this->verifier()->register($actor, $saved['options'], $response, $saved['label']);
      $this->repository()->anchor($credential, ['authorizedBy' => 'administrator_grant', 'issuer_uuid' => $grant->issuer_uuid, 'grant_hash' => $saved['grant_hash']]);
      $count = $this->database->update('vha_enrollment_grant')->fields(['consumed' => 1])
        ->condition('token_hash', $saved['grant_hash'])->condition('consumed', 0)->execute();
      if ($count !== 1) {
        throw new ApprovalException('enrollment_consumed', 'This enrollment grant has already been used.');
      }
      $this->audit()->append($actor->tenantId, 'enrollment.consumed', $saved['grant_hash'], ['user_uuid' => $actor->userId, 'credential_id' => $credential->id]);
      return $credential;
    });
  }

  public function revoke(string $credentialId): bool {
    $actor = $this->actors->interactive();
    $this->limit($actor, 'revoke', 10);
    return $this->transactions->run(function () use ($actor, $credentialId): bool {
      $this->assertUser($actor);
      return $this->repository()->revoke($actor, $credentialId, ['revokedBy' => $actor->userId, 'channel' => 'drupal_account']);
    });
  }

  private function assertUser(Actor $actor): UserInterface {
    $user = $this->actors->accountFor($actor);
    if (!$user->hasPermission('use verified human approval')) {
      throw new ApprovalException('permission_denied', 'Your current account cannot use approval passkeys.');
    }
    return $user;
  }

  private function grant(Actor $actor, string $hash): \stdClass {
    $this->transactions->assertActive();
    $grant = $this->database->select('vha_enrollment_grant', 'g')->fields('g')->condition('token_hash', $hash)->execute()->fetchObject();
    $anchor = $this->audit()->find('enrollment.granted', $hash);
    if (!$grant instanceof \stdClass || $grant->user_uuid !== $actor->userId || $grant->tenant_id !== $actor->tenantId || (int) $grant->consumed !== 0 || (int) $grant->expires <= $this->clock->now()->getTimestamp()
      || $anchor === NULL || $anchor['tenantId'] !== $actor->tenantId
      || ($anchor['data']->user_uuid ?? NULL) !== $actor->userId
      || ($anchor['data']->issuer_uuid ?? NULL) !== $grant->issuer_uuid
      || ($anchor['data']->expires ?? NULL) !== (int) $grant->expires
      || $this->audit()->find('enrollment.consumed', $hash) !== NULL) {
      throw new ApprovalException('invalid_enrollment', 'This enrollment grant is invalid, expired, already used, or belongs to another account.');
    }
    return $grant;
  }

  private function repository(): AnchoredCredentialRepository {
    return $this->container->get('verified_human_approval.credentials');
  }

  private function audit(): AuditLog {
    return $this->container->get('verified_human_approval.audit');
  }

  private function verifier(): WebAuthnVerifier {
    return $this->container->get('verified_human_approval.verifier');
  }

  private function session(): \Symfony\Component\HttpFoundation\Session\SessionInterface {
    $request = $this->requests->getCurrentRequest();
    if ($request === NULL || !$request->hasSession()) {
      throw new ApprovalException('interactive_login_required', 'An authenticated Drupal browser session is required.');
    }
    return $request->getSession();
  }

  private function limit(Actor $actor, string $operation, int $threshold): void {
    $name = 'verified_human_approval.' . $operation;
    if (!$this->flood->isAllowed($name, $threshold, 300, $actor->key())) {
      throw new ApprovalException('rate_limited', 'Too many passkey requests. Wait five minutes and try again.');
    }
    $this->flood->register($name, 300, $actor->key());
  }

}

