<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\verified_human_approval\PasskeyManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use VerifiedHumanApproval\Core\ApprovalException;

/**
 * Issues a targeted, one-time passkey enrollment grant through a native form.
 */
final class EnrollmentGrantForm extends FormBase {

  public function __construct(
    protected PasskeyManager $passkeys,
    protected DateFormatterInterface $dates,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('verified_human_approval.passkey_manager'),
      $container->get('date.formatter'),
    );
  }

  public function getFormId(): string {
    return 'verified_human_approval_enrollment_grant';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['description'] = ['#markup' => $this->t('<p>Issue a one-time link for one person to register one approval passkey. They must sign in with that Drupal account before using it. Share the link privately after checking who will receive it.</p>')];
    $form['uid'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#selection_settings' => ['include_anonymous' => FALSE],
      '#title' => $this->t('Drupal user'),
      '#required' => TRUE,
      '#description' => $this->t('Choose an active user who has permission to use verified human approval.'),
    ];
    $grant = $form_state->get('issued_grant');
    if (is_array($grant)) {
      $url = Url::fromRoute('verified_human_approval.enroll', ['grant' => $grant['token']], ['absolute' => TRUE]);
      $form['issued'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Enrollment link issued'),
        'link' => ['#type' => 'link', '#title' => $url->toString(), '#url' => $url],
        'expiry' => ['#markup' => $this->t('<p>For user ID @uid. Expires @expires. The link can register one passkey and does not sign the person in.</p>', ['@uid' => $grant['uid'], '@expires' => $this->dates->format($grant['expires_at'], 'long')])],
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Issue enrollment link'), '#button_type' => 'primary'];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // A rebuilt POST form is cached for hours by default; the one-time token shown here must not be.
    $form_state->disableCache();
    try {
      $grant = $this->passkeys->issueEnrollmentGrant((int) $form_state->getValue('uid'));
      $form_state->set('issued_grant', $grant);
      $form_state->setRebuild();
      $this->messenger()->addStatus($this->t('The enrollment link was issued. Copy it and share it privately with the named user.'));
    }
    catch (ApprovalException $error) {
      $this->messenger()->addError($error->getMessage());
      $form_state->setRebuild();
    }
  }

}
