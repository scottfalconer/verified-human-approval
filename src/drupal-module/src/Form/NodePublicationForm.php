<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Form;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/** Helps ordinary node forms enter the supported unpublished-draft workflow. */
final class NodePublicationForm {

  public static function alter(array &$form, FormStateInterface $form_state): void {
    $node = self::protectedNode($form_state);
    if ($node === NULL) {
      return;
    }

    $moderated = $node->hasField('moderation_state');
    if ($node->isNew() && !$moderated && !$form_state->get('verified_human_approval.draft_default')) {
      // This is a form default, not an override of submitted field values.
      // Set the entity too: users without access to the status widget must also
      // be able to create a draft when the content type defaults to published.
      $node->setUnpublished();
      if (isset($form['status']['widget']['value'])) {
        $form['status']['widget']['value']['#default_value'] = FALSE;
      }
      $form_state->set('verified_human_approval.draft_default', TRUE);
    }

    if ($moderated) {
      $message = new TranslatableMarkup('This protected content type uses Content Moderation, which this publication action does not support. Ask an administrator to use a supported content type or a dedicated moderation action.');
    }
    elseif (!$node->isNew() && $node->isPublished()) {
      $message = new TranslatableMarkup('This content is currently published. To save changes through this form, explicitly clear Published and save an unpublished draft, then request verified publication. Saving it unpublished takes it off the public site until approval. Keeping a live version while preparing a separate draft requires a different workflow.');
    }
    else {
      $message = new TranslatableMarkup('Save this content as an unpublished draft. After saving, choose Request verified publication to review the exact saved revision and approve it with a passkey. Saving the draft does not publish it.');
    }
    $form['verified_human_approval_help'] = [
      '#type' => 'container', '#weight' => -100,
      'message' => ['#markup' => $message, '#prefix' => '<p>', '#suffix' => '</p>'],
    ];
    $form['#validate'][] = [self::class, 'validate'];
    // A button's own validators replace the form-level list in Form API.
    if (isset($form['actions']['submit']['#validate'])) {
      $form['actions']['submit']['#validate'][] = [self::class, 'validate'];
    }
    if (isset($form['actions']['submit']['#submit'])) {
      $form['actions']['submit']['#submit'][] = [self::class, 'afterSave'];
    }
  }

  /** Rejects ordinary published saves before the storage guard is reached. */
  public static function validate(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    // Preview and field-level actions do not save the node.
    if (!in_array('::save', $trigger['#submit'] ?? [], TRUE)) {
      return;
    }
    $node = self::protectedNode($form_state);
    if ($node === NULL) {
      return;
    }
    // Use Drupal's submitted entity, including values from accessible widgets.
    $node = $form_state->getFormObject()->buildEntity($form, $form_state);
    if (!$node->isPublished()) {
      return;
    }
    $message = $node->hasField('moderation_state')
      ? new TranslatableMarkup('Verified publication does not support Content Moderation. Ask an administrator to use a supported content type or a dedicated moderation action.')
      : new TranslatableMarkup('This content cannot be saved as published. Clear Published and save an unpublished draft, then choose Request verified publication. If this content is currently live, saving it unpublished takes it off the public site until approval.');
    $form_state->setErrorByName('status', $message);
  }

  /** Adds the next step only after Drupal successfully saves an actual draft. */
  public static function afterSave(array &$form, FormStateInterface $form_state): void {
    $node = self::protectedNode($form_state);
    if ($node === NULL || $node->isNew() || $node->isPublished()
      || $node->hasField('moderation_state') || $form_state->isRebuilding()) {
      return;
    }
    $url = Url::fromRoute('verified_human_approval.request_publication', ['node' => $node->id()]);
    $message = $url->access()
      ? new TranslatableMarkup('Saved as an unpublished draft. <a href=":url">Request verified publication</a> to review the exact saved revision and approve it with a passkey.', [':url' => $url->toString()])
      : new TranslatableMarkup('Saved as an unpublished draft. Ask an authorized editor to request verified publication.');
    \Drupal::messenger()->addStatus($message);
  }

  private static function protectedNode(FormStateInterface $form_state): ?NodeInterface {
    $object = $form_state->getFormObject();
    $node = $object instanceof EntityFormInterface ? $object->getEntity() : NULL;
    return $node instanceof NodeInterface && in_array($node->bundle(), \Drupal::service('verified_human_approval.settings')->protectedBundles(), TRUE)
      ? $node : NULL;
  }

}
