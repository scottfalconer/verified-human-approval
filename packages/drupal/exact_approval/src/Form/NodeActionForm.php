<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Form;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\exact_approval\Controller\HumanRequestGuard;
use Drupal\exact_approval\Security\ActorProvider;
use Drupal\node\NodeInterface;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ApprovalService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A human can prepare the same stored action as an authenticated MCP caller. */
final class NodeActionForm extends FormBase
{
    public function __construct(
        protected ApprovalService $approvals,
        protected ActorProvider $actors,
        protected EntityTypeManagerInterface $entities,
        protected ?ModerationInformationInterface $moderation = null,
        protected ?StateTransitionValidationInterface $transitions = null,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('exact_approval.approvals'),
            $container->get('exact_approval.actor_provider'),
            $container->get('entity_type.manager'),
            $container->has('content_moderation.moderation_information') ? $container->get('content_moderation.moderation_information') : null,
            $container->has('content_moderation.state_transition_validation') ? $container->get('content_moderation.state_transition_validation') : null,
        );
    }

    public function getFormId(): string
    {
        return 'exact_approval_node_action';
    }

    public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = null, ?string $operation = null): array
    {
        $actor = HumanRequestGuard::interactive($this->actors);
        if ($node === null || $node->isNew() || !in_array($operation, ['publish', 'delete'], true)) {
            throw new NotFoundHttpException('Choose saved content and a supported operation.');
        }
        if ($operation === 'publish') {
            $storage = $this->entities->getStorage('node');
            $latest = $storage->loadRevision($storage->getLatestRevisionId($node->id()));
            if (!$latest instanceof NodeInterface) throw new NotFoundHttpException('The latest saved revision is unavailable.');
            $node = $latest;
        }
        $account = $this->actors->account($actor);
        if (!$node->access($operation === 'delete' ? 'delete' : 'update', $account, true)->isAllowed()) {
            throw new AccessDeniedHttpException('Drupal does not permit this account to perform the selected operation.');
        }
        $form_state->set('exact_approval.node_id', (int) $node->id());
        $form_state->set('exact_approval.revision_id', (int) $node->getRevisionId());
        $form_state->set('exact_approval.operation', $operation);
        if (!$form_state->has('exact_approval.idempotency')) {
            $form_state->set('exact_approval.idempotency', 'drupal-ui-' . bin2hex(random_bytes(16)));
        }
        $form['intro'] = [
            '#type' => 'item',
            '#markup' => $operation === 'delete'
                ? $this->t('Prepare deletion of this content, including all its revisions and translations. You will review the exact target, content snapshot and statement on the next page before signing. No content is deleted by this button.')
                : $this->t('Prepare publication of this saved revision. Save your content edits as an unpublished draft or revision first. You will review the exact snapshot and statement before signing. No content is published by this button.'),
        ];
        $form['content'] = ['#type' => 'item', '#title' => $this->t('Content'), '#plain_text' => $node->label()];
        $form['revision'] = ['#type' => 'item', '#title' => $this->t('Saved revision'), '#plain_text' => (string) $node->getRevisionId()];
        if ($operation === 'publish' && $this->moderation?->isModeratedEntity($node)) {
            $options = [];
            foreach ($this->transitions?->getValidTransitions($node, $account) ?? [] as $transition) {
                if ($transition->to()->isPublishedState() && $transition->to()->isDefaultRevisionState()) {
                    $options[$transition->id()] = $transition->label();
                }
            }
            if ($options === []) {
                throw new AccessDeniedHttpException('Drupal does not allow a publishing transition for this account and revision.');
            }
            $form['transition_id'] = [
                '#type' => 'select', '#title' => $this->t('Publishing transition'), '#required' => true,
                '#options' => $options, '#empty_option' => $this->t('- Choose a transition -'),
                '#default_value' => count($options) === 1 ? array_key_first($options) : null,
            ];
        }
        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Prepare exact approval'), '#button_type' => 'primary'];
        $form['#cache']['max-age'] = 0;

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $actor = HumanRequestGuard::interactive($this->actors);
        $input = ['node_id' => $form_state->get('exact_approval.node_id')];
        if ($form_state->get('exact_approval.operation') === 'publish') $input['revision_id'] = $form_state->get('exact_approval.revision_id');
        if ($form_state->hasValue('transition_id')) $input['transition_id'] = $form_state->getValue('transition_id');
        try {
            $state = $this->approvals->request($actor, 'node.' . $form_state->get('exact_approval.operation'), $input, $form_state->get('exact_approval.idempotency'));
            $form_state->setIgnoreDestination();
            $form_state->setRedirect('exact_approval.review', ['id' => $state->request->id]);
        } catch (ApprovalException $error) {
            $this->messenger()->addError($error->getMessage());
            $form_state->setRebuild();
        }
    }
}
