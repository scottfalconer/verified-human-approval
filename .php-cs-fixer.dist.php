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
    ->name(['*.php', 'console', 'setup'])
    // What running the publishing example generates (its var/ directory, and Symfony's config reference when it boots
    // with debug on), neither of them committed.
    ->notPath(['doctrine-publishing/var/', 'doctrine-publishing/config/reference.php'])
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
