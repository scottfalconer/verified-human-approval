<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\exact_approval\Controller\HumanRequestGuard;
use Drupal\exact_approval\Security\ActorProvider;
use Drupal\exact_approval\Storage\DrupalCredentialRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Revocation preserves public registration evidence needed by past approvals. */
final class RevokeCredentialForm extends ConfirmFormBase
{
    protected string $credentialId = '';

    public function __construct(protected ActorProvider $actors, protected DrupalCredentialRepository $credentials) {}

    public static function create(ContainerInterface $container): static
    {
        return new static($container->get('exact_approval.actor_provider'), $container->get('exact_approval.credentials'));
    }

    public function getFormId(): string
    {
        return 'exact_approval_revoke_credential';
    }

    public function getQuestion(): \Drupal\Core\StringTranslation\TranslatableMarkup
    {
        return $this->t('Revoke this approval key?');
    }

    public function getDescription(): \Drupal\Core\StringTranslation\TranslatableMarkup
    {
        return $this->t('This key will no longer approve new actions. Its public registration record will remain available to verify past signatures.');
    }

    public function getCancelUrl(): Url
    {
        return Url::fromRoute('exact_approval.keys');
    }

    public function getConfirmText(): \Drupal\Core\StringTranslation\TranslatableMarkup
    {
        return $this->t('Revoke key');
    }

    public function buildForm(array $form, FormStateInterface $form_state, ?string $credential = null): array
    {
        $actor = HumanRequestGuard::interactive($this->actors);
        $record = is_string($credential) ? $this->credentials->find($credential) : null;
        if ($record === null || !$record->belongsTo($actor) || $record->revoked) {
            throw new NotFoundHttpException('This active approval key is unavailable for your account.');
        }
        $this->credentialId = $record->id;
        $form['key'] = ['#type' => 'item', '#title' => $this->t('Credential identifier'), '#plain_text' => $record->id];
        $form['#cache']['max-age'] = 0;

        return parent::buildForm($form, $form_state);
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $this->credentials->revokeForActor(HumanRequestGuard::interactive($this->actors), $this->credentialId);
        $this->messenger()->addStatus($this->t('The approval key was revoked. Historical evidence has been retained.'));
        $form_state->setRedirect('exact_approval.keys');
    }
}
