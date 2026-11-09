<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\verified_human_approval\ApprovalManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use VerifiedHumanApproval\Core\ApprovalException;

/**
 * Prepares a publication proposal; this form never publishes a node itself.
 */
final class RequestPublicationForm extends FormBase {

  public function __construct(
    protected ApprovalManager $approvals,
    protected EntityTypeManagerInterface $entities,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('verified_human_approval.approval_manager'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'verified_human_approval_request_publication';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL) {
      throw new NotFoundHttpException();
    }
    $storage = $this->entities->getStorage('node');
    $revision_id = $storage->getLatestRevisionId($node->id());
    $revision = $revision_id ? $storage->loadRevision($revision_id) : NULL;
    if (!$revision instanceof NodeInterface) {
      throw new NotFoundHttpException('The node revision is unavailable.');
    }
    $form_state->set('publication_node', (int) $node->id());
    $form_state->set('publication_revision', (int) $revision->getRevisionId());
    $form_state->set('publication_language', $revision->language()->getId());
    if (!$form_state->has('publication_request_key')) {
      $form_state->set('publication_request_key', bin2hex(random_bytes(16)));
    }
    $form['explanation'] = ['#markup' => $this->t('<p>Create a review request for this draft. You will see the exact frozen proposal before approving it with your passkey. This form does not publish content.</p>')];
    $form['title'] = ['#type' => 'item', '#title' => $this->t('Title'), '#plain_text' => (string) $revision->label()];
    $form['revision'] = ['#type' => 'item', '#title' => $this->t('Source revision'), '#plain_text' => (string) $revision->getRevisionId()];
    $form['language'] = ['#type' => 'item', '#title' => $this->t('Language'), '#plain_text' => $revision->language()->getName()];
    $form['profile'] = ['#markup' => $this->t('<p>Supported here: a configured content type, an unpublished latest/default revision, one language, standard node fields and an optional plain-text body. Content Moderation, translated content and additional content fields are not supported by this publication action.</p>')];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Review this revision'), '#button_type' => 'primary'];
    $form['actions']['cancel'] = ['#type' => 'link', '#title' => $this->t('Cancel'), '#url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()])];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $state = $this->approvals->requestPublish(
        (int) $form_state->get('publication_node'),
        (int) $form_state->get('publication_revision'),
        (string) $form_state->get('publication_language'),
        (string) $form_state->get('publication_request_key'),
      );
      $form_state->setRedirect('verified_human_approval.review', ['request_id' => $state->request->id]);
    }
    catch (ApprovalException $error) {
      $this->messenger()->addError($error->getMessage());
      $form_state->setRebuild();
    }
  }

}
