<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval;

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
    private readonly RequestStack $requests,
    private readonly ModuleSettings $settings,
  ) {}

  public function current(): Actor {
    return $this->interactive();
  }

  public function interactive(): Actor {
    $request = $this->requests->getCurrentRequest();
    // Browsers resend Basic credentials (a staging site's password, the Shield module) on every request, so
    // those are tolerated; any other Authorization scheme, a bearer token above all, is refused.
    $authorization = $request?->headers->get('Authorization');
    if ($this->currentUser->isAnonymous() || $request === NULL || ($authorization !== NULL && !preg_match('/^Basic\s/i', $authorization)) || !$request->hasSession() || (string) $request->getSession()->get('uid') !== (string) $this->currentUser->id()) {
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
    // Inside the approval transaction, DrupalTransaction has already dropped this request's calculated
    // permissions. Reload this account and its roles' configuration from the database, and nothing else.
    $this->entities->getStorage('user_role')->resetCache();
    $this->config->reset();
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('uuid', $actor->userId)->range(0, 2)->execute();
    $user = NULL;
    if (count($ids) === 1) {
      $uid = reset($ids);
      $storage->resetCache([$uid]);
      $user = $storage->load($uid);
    }
    if (!$user instanceof UserInterface || !$user->isActive() || $user->isAnonymous()) {
      throw new ApprovalException('permission_denied', 'This Drupal account is no longer active.');
    }
    return $user;
  }

}

