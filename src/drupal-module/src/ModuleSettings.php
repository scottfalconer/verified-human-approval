<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\WebAuthn\Configuration;

/** Trust settings come from settings.php, never request headers or tool inputs. */
final class ModuleSettings {

  public function __construct(private readonly ConfigFactoryInterface $config) {}

  public function values(): array {
    $values = Settings::get('verified_human_approval', []);
    return is_array($values) ? $values : [];
  }

  public function tenantId(): string {
    $id = $this->values()['site_id'] ?? $this->config->get('system.site')->get('uuid');
    if (!is_string($id) || $id === '' || strlen($id) > 128) {
      throw new ApprovalException('configuration_required', 'Configure a stable site UUID before using verified approval.');
    }
    return $id;
  }

  /** @return list<string> */
  public function protectedBundles(): array {
    $bundles = $this->values()['protected_bundles'] ?? [];
    if (!is_array($bundles)) {
      throw new ApprovalException('configuration_required', 'protected_bundles must be a list of node bundle machine names.');
    }
    foreach ($bundles as $bundle) {
      if (!is_string($bundle) || !preg_match('/^[a-z][a-z0-9_]*$/D', $bundle)) {
        throw new ApprovalException('configuration_required', 'Invalid protected node bundle.');
      }
    }
    return array_values(array_unique($bundles));
  }

  public function webAuthn(): Configuration {
    $settings = $this->values();
    try {
      return new Configuration((string) ($settings['rp_id'] ?? ''), (string) ($settings['origin'] ?? ''));
    }
    catch (\Throwable $error) {
      throw new ApprovalException('configuration_required', 'Configure the trusted WebAuthn origin and RP ID in settings.php.', $error);
    }
  }

  public function lifetime(): int {
    $seconds = $this->values()['lifetime_seconds'] ?? 600;
    if (!is_int($seconds) || $seconds < 30 || $seconds > 3600) {
      throw new ApprovalException('configuration_required', 'Approval lifetime must be between 30 and 3600 seconds.');
    }
    return $seconds;
  }

  public function file(string $setting): string {
    $file = $this->values()[$setting] ?? NULL;
    if (!is_string($file) || !str_starts_with($file, '/') || !is_file($file) || !is_readable($file)) {
      throw new ApprovalException('configuration_required', "Configure a readable absolute $setting path outside the public web root.");
    }
    return $file;
  }

}

