#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Creates an application receipt key pair without printing the private key.
 *
 * Run with PHP CLI and an absolute directory outside the Drupal web root.
 * The PHP worker must be able to read the files after deployment.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

if ($argc !== 2 || !str_starts_with($argv[1], '/')) {
  fwrite(STDERR, "Usage: php generate-keys.php /absolute/private/directory\n");
  exit(1);
}

$created = [];
$secret = NULL;
$pair = NULL;
$encoded = NULL;

try {
  if (!extension_loaded('sodium')) {
    throw new RuntimeException('The sodium PHP extension is required.');
  }
  $directory = $argv[1];
  if (!is_dir($directory) && !mkdir($directory, 0700, TRUE) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create the private directory.');
  }
  $directory = realpath($directory);
  if ($directory === FALSE || !is_writable($directory)) {
    throw new RuntimeException('The private directory is not writable.');
  }
  $privatePath = $directory . '/signing.key';
  $publicPath = $directory . '/public-key.json';
  if (file_exists($privatePath) || is_link($privatePath) || file_exists($publicPath) || is_link($publicPath)) {
    throw new RuntimeException('Key files already exist. They were not changed.');
  }

  $pair = sodium_crypto_sign_keypair();
  $secret = sodium_crypto_sign_secretkey($pair);
  $public = sodium_crypto_sign_publickey($pair);
  $encode = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
  $encoded = $encode($secret) . "\n";
  $trust = json_encode([
    'keyId' => 'drupal-' . substr(hash('sha256', $public), 0, 16),
    'publicKey' => $encode($public),
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

  // Exclusive creation prevents replacement, including concurrent runs. Set a
  // restrictive umask before opening either file so no secret is exposed first.
  $oldMask = umask(0077);
  try {
    foreach ([$privatePath => $encoded, $publicPath => $trust] as $path => $contents) {
      $stream = fopen($path, 'x');
      if ($stream === FALSE) {
        throw new RuntimeException('Cannot create a key file exclusively.');
      }
      $created[] = $path;
      try {
        if (fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)) {
          throw new RuntimeException('Cannot write the complete key file.');
        }
      }
      finally {
        fclose($stream);
      }
    }
  }
  finally {
    umask($oldMask);
  }

  fwrite(STDOUT, "Created receipt keys. Keep this directory outside the Drupal web root.\n");
  fwrite(STDOUT, "Allow the Drupal PHP worker to read both files. Keep the private key secret.\n\n");
  fwrite(STDOUT, "Add these entries to the verified_human_approval settings array:\n");
  fwrite(STDOUT, "  'signing_key_file' => " . var_export($privatePath, TRUE) . ",\n");
  fwrite(STDOUT, "  'public_key_file' => " . var_export($publicPath, TRUE) . ",\n");
}
catch (Throwable $error) {
  foreach ($created as $path) {
    unlink($path);
  }
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
finally {
  foreach (['secret', 'pair', 'encoded'] as $variable) {
    if (is_string($$variable)) {
      sodium_memzero($$variable);
    }
  }
}
