<?php

declare(strict_types=1);

use Composer\InstalledVersions;

$consumer = $argv[1] ?? '';
if (!is_file($consumer.'/vendor/autoload.php')) {
    throw new RuntimeException('Usage: php tests/Drupal/assert-consumer.php /absolute/path/to/drupal-consumer');
}
require $consumer.'/vendor/autoload.php';

foreach ([
    'verified-human-approval/symfony-bundle',
    'verified-human-approval/mcp',
    'symfony/framework-bundle',
    'symfony/security-bundle',
    'symfony/twig-bundle',
    'symfony/mcp-bundle',
    'mcp/sdk',
] as $package) {
    if (InstalledVersions::isInstalled($package)) {
        throw new RuntimeException('The independent Drupal consumer must not depend on '.$package.'.');
    }
}

foreach ([
    'drupal/core',
    'drupal/core-dev',
    'drupal/verified_human_approval',
    'verified-human-approval/core',
    'verified-human-approval/webauthn',
    'web-auth/webauthn-lib',
    'phpunit/phpunit',
] as $package) {
    if (!InstalledVersions::isInstalled($package)) {
        throw new RuntimeException('The independent Drupal consumer is missing '.$package.'.');
    }
    echo $package.' '.InstalledVersions::getPrettyVersion($package)."\n";
}

$sqlite = (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();
if (!is_string($sqlite) || version_compare($sqlite, '3.45.0', '<')) {
    throw new RuntimeException('Drupal 11 requires SQLite 3.45 or later; actual version: '.(string) $sqlite);
}
echo 'PHP '.\PHP_VERSION.', SQLite '.$sqlite."\n";
