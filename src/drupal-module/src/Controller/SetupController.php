<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;

/**
 * Explains trusted setup without exposing or modifying signing secrets.
 */
final class SetupController extends ControllerBase {

  public function overview(): array {
    $settings = Settings::get('verified_human_approval', []);
    $settings = is_array($settings) ? $settings : [];
    $rows = [
      [$this->t('Trusted origin'), $settings['origin'] ?? $this->t('Not configured')],
      [$this->t('Relying party ID'), $settings['rp_id'] ?? $this->t('Not configured')],
      [$this->t('Private signing key'), $this->keyStatus($settings['signing_key_file'] ?? NULL)],
      [$this->t('Public trust file'), $this->keyStatus($settings['public_key_file'] ?? NULL)],
      [$this->t('Protected content types'), implode(', ', array_filter((array) ($settings['protected_bundles'] ?? []), 'is_string')) ?: $this->t('None configured')],
    ];
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['vha']],
      'intro' => ['#markup' => $this->t('<p>Verified Human Approval asks a person to approve an exact stored publication request with a passkey. Drupal checks their current permissions again before publishing and records a signed receipt.</p>')],
      'status' => ['#type' => 'table', '#header' => [$this->t('Setting'), $this->t('Configured value')], '#rows' => $rows],
      'configuration' => ['#markup' => $this->t('<p>Configure the trusted origin, relying party ID, private key paths and protected content types in <code>$settings[\'verified_human_approval\']</code> in settings.php. The keys must be provisioned outside the public web directory. This page reports configuration presence; a successful signed approval is the end-to-end check.</p>')],
      'profile' => ['#markup' => $this->t('<p>This first integration publishes an unpublished latest/default node revision with one language, standard node fields and an optional plain-text body. Content Moderation, translated nodes, additional content fields and external effects need their own integration.</p>')],
      'steps' => [
        '#theme' => 'item_list',
        '#list_type' => 'ol',
        '#items' => [
          $this->t('Assign the module permissions to trusted Drupal roles.'),
          $this->t('Issue a one-time enrollment link for each new approval passkey. Share it privately with the named user.'),
          $this->t('The user signs in to Drupal, opens the enrollment link, and registers their passkey.'),
          $this->t('Open an eligible draft and choose “Request verified publication”. Review the frozen request and approve it with the passkey.'),
          $this->t('Download the original receipt and retain the public verification key.'),
        ],
      ],
      'grant' => ['#type' => 'link', '#title' => $this->t('Issue an enrollment link'), '#url' => Url::fromRoute('verified_human_approval.grant'), '#attributes' => ['class' => ['button', 'button--primary']]],
      'passkeys' => ['#type' => 'link', '#title' => $this->t('My approval passkeys'), '#url' => Url::fromRoute('verified_human_approval.passkeys')],
      '#attached' => ['library' => ['verified_human_approval/ceremony']],
      '#cache' => ['max-age' => 0],
    ];
  }

  private function keyStatus(mixed $path): string {
    return (string) (is_string($path) && str_starts_with($path, '/') && is_readable($path)
      ? $this->t('File is configured and readable')
      : $this->t('Not configured or not readable'));
  }

}
