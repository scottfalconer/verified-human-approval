<?php

declare(strict_types=1);

/*
 * Symfony's coding standard, as Symfony's own packages apply it. Run it with:
 *
 *     composer cs        # check
 *     composer cs:fix    # fix
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/demo/src', __DIR__.'/demo/bin', __DIR__.'/demo/public', __DIR__.'/examples', __DIR__.'/tests'])
    // Keep Symfony's coding rules scoped to the library and Symfony packages.
    ->exclude('drupal-module')
    ->name(['*.php', 'console'])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache');
