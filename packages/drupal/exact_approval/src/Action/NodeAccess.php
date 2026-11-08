<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Action;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\exact_approval\Policy\PolicyRules;
use Drupal\exact_approval\Security\ActorProvider;
use Drupal\node\NodeInterface;
use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\CanonicalJson;

/** Approval is an additional condition, never an alternative access grant. */
final class NodeAccess
{
    public function __construct(
        private readonly ActorProvider $actors,
        private readonly EntityTypeManagerInterface $entityTypes,
        private readonly PolicyRules $rules,
        private readonly ?ModerationInformationInterface $moderation = null,
        private readonly ?StateTransitionValidationInterface $transitions = null,
    ) {}

    /** @return array{workflow:?string, workflow_digest:?string, transition:?string, state:?string} */
    public function assertPublish(Actor $actor, NodeInterface $node, ?string $transitionId = null): array
    {
        $account = $this->actors->account($actor);
        $this->entityTypes->getAccessControlHandler('node')->resetCache();
        if ($this->moderation !== null) {
            $this->entityTypes->getStorage('workflow')->resetCache();
        }
        if (!$node->access('update', $account, true)->isAllowed()) {
            throw new ApprovalException('access_denied', 'Drupal does not allow this account to update this content.');
        }
        $this->assertReviewable($node, $account);
        $this->assertBinding('publish', $node, $account);
        if ($this->moderation?->isModeratedEntity($node)) {
            if ($this->transitions === null) {
                throw new ApprovalException('unsupported_workflow', 'Moderation transition validation is unavailable.');
            }
            $workflow = $this->moderation->getWorkflowForEntity($node);
            $candidates = [];
            foreach ($this->transitions->getValidTransitions($node, $account) as $transition) {
                $state = $transition->to();
                if ($state->isPublishedState() && $state->isDefaultRevisionState()
                    && ($transitionId === null || $transition->id() === $transitionId)) {
                    $candidates[] = $transition;
                }
            }
            if (count($candidates) !== 1) {
                throw new ApprovalException(count($candidates) > 1 ? 'transition_required' : 'access_denied',
                    count($candidates) > 1 ? 'Choose the exact publishing transition to approve.' : 'Drupal does not allow the requested publishing transition.');
            }
            $transition = $candidates[0];
            return [
                'workflow' => $workflow->id(),
                'workflow_digest' => hash('sha256', CanonicalJson::encode($workflow->toArray())),
                'transition' => $transition->id(), 'state' => $transition->to()->id(),
            ];
        }
        if ($transitionId !== null && $transitionId !== '') {
            throw new ApprovalException('invalid_transition', 'This content does not use a moderation workflow.');
        }
        if (!$node->get('status')->access('edit', $account, true)->isAllowed()) {
            throw new ApprovalException('access_denied', 'Drupal does not allow this account to edit publication status.');
        }
        return ['workflow' => null, 'workflow_digest' => null, 'transition' => null, 'state' => null];
    }

    public function assertDelete(Actor $actor, NodeInterface $node): void
    {
        $account = $this->actors->account($actor);
        $this->entityTypes->getAccessControlHandler('node')->resetCache();
        if (!$node->access('delete', $account, true)->isAllowed()) {
            throw new ApprovalException('access_denied', 'Drupal does not allow this account to delete this content.');
        }
        $this->assertReviewable($node, $account);
        $this->assertBinding('delete', $node, $account);
    }

    /** The immutable proposal/evidence contains these values: never disclose hidden fields. */
    private function assertReviewable(NodeInterface $node, AccountInterface $account): void
    {
        foreach ($node->getTranslationLanguages() as $langcode => $language) {
            $translation = $node->getTranslation($langcode);
            if (!$translation->access('view', $account, true)->isAllowed()) {
                throw new ApprovalException('access_denied', 'Exact approval requires access to view every reviewed content translation.');
            }
            foreach ($translation->getFieldDefinitions() as $name => $definition) {
                if ((!$definition->isComputed() || $name === 'moderation_state')
                    && !$translation->get($name)->access('view', $account, true)->isAllowed()) {
                    throw new ApprovalException('access_denied', 'A field is hidden from this account and cannot be disclosed in the exact approval record.');
                }
            }
        }
    }

    private function assertBinding(string $operation, NodeInterface $node, AccountInterface $account): void
    {
        $permissions = $this->rules->permissionBindings($operation, $node->bundle());
        if ($permissions !== []) {
            foreach ($permissions as $permission) {
                if ($account->hasPermission($permission)) {
                    return;
                }
            }
            throw new ApprovalException('access_denied', 'The account no longer has an administrator-selected permission for this action.');
        }
    }
}
