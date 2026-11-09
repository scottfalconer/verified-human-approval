<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;

/** The default integration trusts Drupal cookie login, never bearer enrollment. */
final class DrupalActorProvider implements ActorProviderInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ConfigFactoryInterface $config,
    private readonly CacheTagsInvalidatorInterface $cacheTags,
    private readonly RequestStack $requests,
    private readonly ModuleSettings $settings,
  ) {}

  public function current(): Actor {
    return $this->interactive();
  }

  public function interactive(): Actor {
    $request = $this->requests->getCurrentRequest();
    if ($this->currentUser->isAnonymous() || $request === NULL || $request->headers->has('Authorization') || !$request->hasSession() || (string) $request->getSession()->get('uid') !== (string) $this->currentUser->id()) {
      throw new ApprovalException('interactive_login_required', 'Use your Drupal browser login. A bearer token cannot enroll a passkey or approve a request.');
    }
    $storage = $this->entities->getStorage('user');
    $storage->resetCache([$this->currentUser->id()]);
    $user = $storage->load($this->currentUser->id());
    if (!$user instanceof UserInterface || !$user->isActive()) {
      throw new ApprovalException('permission_denied', 'This Drupal account is no longer active.');
    }
    return new Actor($user->uuid(), $this->settings->tenantId());
  }

  public function accountFor(Actor $actor): UserInterface {
    if ($actor->tenantId !== $this->settings->tenantId() || $actor->delegationId !== NULL) {
      throw new ApprovalException('delegation_unsupported', 'This integration requires a matching site and a supported, currently valid identity context.');
    }
    $storage = $this->entities->getStorage('user');
    $storage->resetCache();
    $this->entities->getStorage('user_role')->resetCache();
    $this->config->reset();
    // Route checks may have populated calculated permissions before the writer
    // reservation. Recalculate them for the authoritative transaction.
    $this->cacheTags->invalidateTags(['access_policies']);
    $users = $storage->loadByProperties(['uuid' => $actor->userId]);
    $user = reset($users);
    if (!$user instanceof UserInterface || !$user->isActive() || $user->isAnonymous()) {
      throw new ApprovalException('permission_denied', 'This Drupal account is no longer active.');
    }
    return $user;
  }

}

