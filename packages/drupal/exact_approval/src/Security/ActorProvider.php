<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Security;

use Drupal\Core\Authentication\AuthenticationCollectorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;
use ExactApproval\Core\{Actor, ApprovalException};
use Symfony\Component\HttpFoundation\RequestStack;

/** Authentication is supplied by Drupal. Request arguments never select the acting user. */
final readonly class ActorProvider
{
    public function __construct(
        private AccountProxyInterface $currentUser,
        private EntityTypeManagerInterface $entities,
        private ConfigFactoryInterface $config,
        private RequestStack $requests,
        private AuthenticationCollectorInterface $authentication,
    ) {}

    public function tenantId(): string
    {
        $id = (string) $this->config->get('system.site')->get('uuid');
        if ($id === '') {
            throw new ApprovalException('site_identity_missing', 'The site needs a stable UUID.');
        }
        return $id;
    }

    public function current(): Actor
    {
        if (!$this->currentUser->isAuthenticated()) {
            throw new ApprovalException('authentication_required', 'Sign in with your own Drupal account.');
        }
        // Only a trusted authentication integration may set this request attribute.
        $request = $this->requests->getCurrentRequest();
        $delegation = $request?->attributes->get('_exact_approval_delegation_id');
        if ($request !== null && !is_string($delegation)) {
            foreach ($this->authentication->getSortedProviders() as $providerId => $provider) {
                if ($provider->applies($request)) {
                    if (!in_array($providerId, ['cookie', 'basic_auth'], true)) {
                        throw new ApprovalException('delegation_not_configured', 'This authentication provider must supply a stored delegation reference and current-grant authorizer.');
                    }
                    break;
                }
            }
            $authorization = (string) $request->headers->get('Authorization', '');
            if ($authorization !== '' && !str_starts_with(strtolower($authorization), 'basic ')) {
                throw new ApprovalException('delegation_not_configured', 'A bearer credential cannot fall back to the full Drupal account authority.');
            }
        }
        $actor = new Actor((string) $this->currentUser->id(), $this->tenantId(), is_string($delegation) ? $delegation : null);
        $this->account($actor);
        return $actor;
    }

    public function interactive(): Actor
    {
        $request = $this->requests->getCurrentRequest();
        $actor = $this->current();
        if ($request === null || !$request->hasSession() || !$request->getSession()->isStarted()
            || (string) $request->getSession()->get('uid') !== $actor->userId
            || $request->headers->has('Authorization') || $actor->delegationId !== null) {
            throw new ApprovalException('interactive_session_required', 'Use your signed-in Drupal browser session to enroll a key or approve an action.');
        }
        return $actor;
    }

    /** Reload account and roles at each boundary; possession of an old proposal is not authority. */
    public function account(Actor $actor): UserInterface
    {
        if ($actor->tenantId !== $this->tenantId() || !ctype_digit($actor->userId) || (int) $actor->userId < 1) {
            throw new ApprovalException('wrong_account', 'The account does not belong to this site.');
        }
        $this->entities->getStorage('user_role')->resetCache();
        $storage = $this->entities->getStorage('user');
        $storage->resetCache([(int) $actor->userId]);
        $account = $storage->load((int) $actor->userId);
        if (!$account instanceof UserInterface || !$account->isActive()) {
            throw new ApprovalException('account_disabled', 'This account is unavailable or blocked.');
        }
        // Drupal's permission cache contexts consult current_user. Refresh it too
        // when checking the same UID, so removed role membership cannot survive
        // in a long-lived MCP request via an old account proxy.
        if ((string) $this->currentUser->id() === $actor->userId) {
            $this->currentUser->setAccount($account);
        }
        return $account;
    }
}

