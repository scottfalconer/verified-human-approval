<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\verified_human_approval\PasskeyManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use VerifiedHumanApproval\Core\ApprovalException;

/**
 * Confirms revocation of a credential belonging to the current Drupal user.
 */
final class RevokePasskeyForm extends ConfirmFormBase {

  protected string $credentialId = '';
  protected string $credentialLabel = '';

  public function __construct(protected PasskeyManager $passkeys) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('verified_human_approval.passkey_manager'));
  }

  public function getFormId(): string {
    return 'verified_human_approval_revoke_passkey';
  }

  public function getQuestion() {
    return $this->t('Revoke the approval passkey “@label”?', ['@label' => $this->credentialLabel]);
  }

  public function getDescription() {
    return $this->t('This passkey will no longer approve requests on this site. Existing signed receipts remain available. Registering a replacement requires a new enrollment link from an administrator.');
  }

  public function getConfirmText() {
    return $this->t('Revoke passkey');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('verified_human_approval.passkeys');
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $credential_id = ''): array {
    foreach ($this->passkeys->credentials() as $credential) {
      if (hash_equals($credential->id, $credential_id) && !$credential->revoked) {
        $this->credentialId = $credential->id;
        $this->credentialLabel = $credential->label;
        $form = parent::buildForm($form, $form_state);
        $form['#cache']['max-age'] = 0;
        return $form;
      }
    }
    throw new NotFoundHttpException('This passkey is unavailable for your account.');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $revoked = $this->passkeys->revoke($this->credentialId);
      $this->messenger()->addStatus($revoked
        ? $this->t('The approval passkey was revoked.')
        : $this->t('That passkey is no longer active.'));
      $form_state->setRedirect('verified_human_approval.passkeys');
    }
    catch (ApprovalException $error) {
      $this->messenger()->addError($error->getMessage());
      $form_state->setRebuild();
    }
  }

}
