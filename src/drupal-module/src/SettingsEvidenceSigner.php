<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval;

use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ChallengeBinding;
use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;

/** Public reads never open the private key. Only sign() needs it. */
final class SettingsEvidenceSigner implements EvidenceSigner {

  public function __construct(private readonly ModuleSettings $settings) {}

  /** @return array{keyId: string, publicKey: string} */
  public function trust(): array {
    try {
      $file = $this->settings->file('public_key_file');
      if (filesize($file) > 4096) {
        throw new \UnexpectedValueException('Oversized trust file.');
      }
      $data = json_decode((string) file_get_contents($file), TRUE, 16, JSON_THROW_ON_ERROR);
      if (!is_array($data) || !is_string($data['keyId'] ?? NULL) || $data['keyId'] === '' || !is_string($data['publicKey'] ?? NULL) || strlen(ChallengeBinding::decode($data['publicKey'])) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new \UnexpectedValueException('Invalid public trust file.');
      }
      return ['keyId' => $data['keyId'], 'publicKey' => $data['publicKey']];
    }
    catch (\Throwable $error) {
      throw new ApprovalException('configuration_required', 'The application public trust file is missing or invalid.', $error);
    }
  }

  public function keyId(): string {
    return $this->trust()['keyId'];
  }

  public function publicKey(): string {
    return $this->trust()['publicKey'];
  }

  public function sign(string $manifest): string {
    $trust = $this->trust();
    $path = $this->settings->file('signing_key_file');
    // Avoid loading arbitrarily large files and reject web-root key placement.
    $real = realpath($path);
    $web = defined('DRUPAL_ROOT') ? realpath(DRUPAL_ROOT) : FALSE;
    if ($real === FALSE || ($web !== FALSE && str_starts_with($real, $web . DIRECTORY_SEPARATOR)) || filesize($path) > 256) {
      throw new ApprovalException('configuration_required', 'The signing key must be a small private file outside the Drupal web root.');
    }
    $signer = SodiumEvidenceSigner::fromEncoded($trust['keyId'], trim((string) file_get_contents($path)));
    if (!hash_equals($trust['publicKey'], $signer->publicKey())) {
      throw new ApprovalException('configuration_required', 'The signing key does not match the configured public trust key.');
    }
    return $signer->sign($manifest);
  }

}

