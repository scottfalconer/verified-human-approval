<?php

declare(strict_types=1);

// Generate an independent consumer outside the monorepo. Absolute path repositories
// let Composer install the three unpublished packages without the root `replace` map.
$repository = dirname(__DIR__, 2);
$packages = [
    'verified-human-approval/core' => 'src/core',
    'verified-human-approval/webauthn' => 'src/webauthn',
    'drupal/verified_human_approval' => 'src/drupal-module',
];
$repositories = [];
foreach ($packages as $name => $directory) {
    $path = $repository.'/'.$directory;
    if (!is_file($path.'/composer.json')) {
        throw new RuntimeException('Missing package manifest: '.$path.'/composer.json');
    }
    $repositories[] = [
        'type' => 'path',
        'url' => $path,
        'options' => ['symlink' => true, 'versions' => [$name => 'dev-main']],
    ];
}
$repositories[] = ['type' => 'composer', 'url' => 'https://packages.drupal.org/8'];
$drupal = $argv[1] ?? '^11.4';

$manifest = [
    'name' => 'verified-human-approval/drupal-test-consumer',
    'description' => 'Independent Drupal consumer for the Verified Human Approval integration tests.',
    'type' => 'project',
    'license' => 'MIT',
    'repositories' => $repositories,
    'require' => [
        'php' => '^8.3',
        'ext-pdo_sqlite' => '*',
        'composer/installers' => '^2.3',
        'drupal/core-composer-scaffold' => $drupal,
        'drupal/core-recommended' => $drupal,
        'drupal/verified_human_approval' => 'dev-main',
        'verified-human-approval/core' => 'dev-main',
        'verified-human-approval/webauthn' => 'dev-main',
    ],
    'require-dev' => ['drupal/core-dev' => $drupal],
    'autoload-dev' => [
        'psr-4' => ['VerifiedHumanApproval\\Tests\\Support\\' => $repository.'/tests/Support/'],
    ],
    'minimum-stability' => 'stable',
    'prefer-stable' => true,
    'config' => [
        'sort-packages' => true,
        'allow-plugins' => [
            'composer/installers' => true,
            'drupal/core-composer-scaffold' => true,
            'symfony/runtime' => true,
            'phpstan/extension-installer' => true,
            'dealerdirect/phpcodesniffer-composer-installer' => true,
            'php-http/discovery' => false,
        ],
    ],
    'extra' => [
        'drupal-scaffold' => ['locations' => ['web-root' => 'web/']],
        'installer-paths' => [
            'web/core' => ['type:drupal-core'],
            'web/modules/contrib/{$name}' => ['type:drupal-module'],
        ],
    ],
];

echo json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
