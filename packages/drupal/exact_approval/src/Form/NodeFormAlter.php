<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Form;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\exact_approval\Controller\HumanRequestGuard;
use Drupal\node\Form\NodeDeleteForm;
use Drupal\node\Form\NodeForm;
use Drupal\node\NodeInterface;
use ExactApproval\Core\ApprovalException;

/** Small Form API glue; the storage guard remains the enforcement boundary. */
final class NodeFormAlter
{
    public static function alter(array &$form, FormStateInterface $formState): void
    {
        $object = $formState->getFormObject();
        if (!$object instanceof EntityFormInterface || !($node = $object->getEntity()) instanceof NodeInterface) return;
        $rules = \Drupal::service('exact_approval.rules');

        if ($object instanceof NodeDeleteForm && $rules->protects('delete', $node->bundle())) {
            if (!$node->isDefaultTranslation()) {
                // node.delete means the whole entity. A translation request must never broaden to that action.
                $form['description'] = ['#markup' => t('Exact approval currently supports deleting a complete content item, including its revisions and translations. Deleting only one translation is unavailable while this rule is enabled.')];
                $form['actions']['submit']['#access'] = false;
                $form['#validate'][] = [self::class, 'rejectTranslationDelete'];
                return;
            }
            $form['description'] = ['#markup' => t('This content type requires a signed approval before deletion. Prepare the exact request, review it on the next page, then approve with your registered key. This button does not delete content.')];
            $form['actions']['submit']['#value'] = t('Prepare exact approval');
            $form['actions']['submit']['#submit'] = [[self::class, 'prepareDelete']];
            $form['#submit'] = [];
            if (!$formState->has('exact_approval.delete_idempotency')) {
                $formState->set('exact_approval.delete_idempotency', 'drupal-delete-' . bin2hex(random_bytes(16)));
            }
            return;
        }

        if ($object instanceof NodeForm && $rules->protects('publish', $node->bundle())) {
            $form['exact_approval_notice'] = [
                '#type' => 'container', '#weight' => -100,
                '#attributes' => ['class' => ['messages', 'messages--warning']],
                'text' => ['#markup' => t('Publishing and saves that leave this content published require exact approval. Save an unpublished draft or revision first; then review that saved revision and sign its publication. Unsaved edits are never carried into an existing approval request.')],
            ];
            if (!$node->isNew()) {
                $form['exact_approval_notice']['link'] = [
                    '#type' => 'link', '#title' => t('Prepare approval for the latest saved revision'),
                    '#url' => Url::fromRoute('exact_approval.node_action', ['node' => $node->id(), 'operation' => 'publish']),
                    '#prefix' => '<p>', '#suffix' => '</p>',
                ];
            }
            // Only the saving button: preview and field-widget AJAX remain available.
            if (isset($form['actions']['submit'])) {
                $form['actions']['submit']['#validate'] = array_merge(
                    $form['actions']['submit']['#validate'] ?? $form['#validate'] ?? [],
                    [[self::class, 'validatePublishedSave']],
                );
            }
        }
    }

    public static function prepareDelete(array &$form, FormStateInterface $formState): void
    {
        $object = $formState->getFormObject();
        if (!$object instanceof EntityFormInterface || !($node = $object->getEntity()) instanceof NodeInterface || !$node->isDefaultTranslation()) {
            throw new \LogicException('Exact node deletion requires the complete content item.');
        }
        $actor = HumanRequestGuard::interactive(\Drupal::service('exact_approval.actor_provider'));
        try {
            $state = \Drupal::service('exact_approval.approvals')->request($actor, 'node.delete', ['node_id' => (int) $node->id()], $formState->get('exact_approval.delete_idempotency'));
            $formState->setIgnoreDestination();
            $formState->setRedirect('exact_approval.review', ['id' => $state->request->id]);
        } catch (ApprovalException $error) {
            \Drupal::messenger()->addError($error->getMessage());
            $formState->setRebuild();
        }
    }

    public static function rejectTranslationDelete(array &$form, FormStateInterface $formState): void
    {
        $formState->setErrorByName('', t('Translation-only deletion is unavailable while the exact deletion rule is enabled.'));
    }

    public static function validatePublishedSave(array &$form, FormStateInterface $formState): void
    {
        $object = $formState->getFormObject();
        if (!$object instanceof NodeForm) return;
        $node = $object->buildEntity($form, $formState);
        if (!$node instanceof NodeInterface || !\Drupal::service('exact_approval.rules')->protects('publish', $node->bundle())) return;
        $published = $node->isPublished();
        if (\Drupal::hasService('content_moderation.moderation_information')) {
            $moderation = \Drupal::service('content_moderation.moderation_information');
            if ($moderation->isModeratedEntity($node)) {
                $workflow = $moderation->getWorkflowForEntity($node);
                $state = (string) $node->get('moderation_state')->value;
                if ($state !== '' && $workflow->getTypePlugin()->hasState($state)) {
                    $published = $workflow->getTypePlugin()->getState($state)->isPublishedState();
                }
            }
        }
        if ($published) {
            $formState->setErrorByName('', t('This save would publish protected content. Save an unpublished draft or revision first, then use “Prepare approval for the latest saved revision” and approve that exact action with your key. No content has been saved.'));
        }
    }
}
