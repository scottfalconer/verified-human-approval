<?php

declare(strict_types=1);

/*
 * Install all seven packages into a separate Symfony application, copied from the public publishing example.
 * No root Composer replacements, test autoloader, demo services, database or secrets participate.
 * The packages come from this checkout through a path repository and the application is the example's own code, so
 * this shows the packages working apart from this monorepo; it installs no published release, and no application
 * was written from the documentation alone.
 * Usage: php tests/Package/check-symfony-consumer.php [--keep]
 */

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir().'/vha-symfony-consumer-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
$keep = in_array('--keep', $argv, true);
$composer = getenv('COMPOSER_BIN') ?: 'composer';

$run = static function (array $command, string $description): void {
    echo '> '.$description."\n";
    passthru(implode(' ', array_map('escapeshellarg', $command)), $exit);
    if (0 !== $exit) {
        throw new RuntimeException($description.' failed (exit '.$exit.').');
    }
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $remove($path.'/'.$entry);
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
};

try {
    foreach (['config', 'src', 'templates', 'public'] as $part) {
        $source = $root.'/examples/doctrine-publishing/'.$part;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $relative = substr($file->getPathname(), strlen($source) + 1);
            if ('reference.php' === $relative) {
                continue; // Symfony can generate this locally; it is not application configuration.
            }
            $target = $directory.'/'.$part.'/'.$relative;
            if ($file->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0700, true);
                }
                continue;
            }
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            $contents = file_get_contents($file->getPathname());
            // An application's normal PSR-4 namespace, with no monorepo example autoloader.
            $contents = str_replace('VerifiedHumanApproval\\Example\\Publishing', 'App', $contents);
            file_put_contents($target, $contents);
        }
    }
    copy($root.'/tests/Adoption/symfony/verify.php', $directory.'/verify.php');
    $packages = array_map(static fn (string $name): string => 'verified-human-approval/'.$name,
        ['core', 'webauthn', 'mcp', 'symfony-bundle', 'doctrine', 'store-contracts', 'testing']);
    $manifest = [
        'name' => 'vha-adoption/symfony-application',
        'type' => 'project',
        'license' => 'MIT',
        'require' => ['php' => '^8.3'] + array_fill_keys($packages, 'dev-main') + [
            'doctrine/doctrine-bundle' => '^2.18 || ^3.1',
            'doctrine/orm' => '^3.5',
            'nyholm/psr7' => '^1.8',
            'symfony/css-selector' => '^7.4 || ^8.0',
            'symfony/monolog-bundle' => '^4.1',
            'symfony/translation' => '^7.4 || ^8.0',
        ],
        'autoload' => ['psr-4' => ['App\\' => 'src/']],
        'repositories' => [[
            'type' => 'path', 'url' => $root.'/src/*',
            'options' => ['symlink' => false, 'versions' => array_fill_keys($packages, 'dev-main')],
        ]],
        'minimum-stability' => 'beta',
        'prefer-stable' => true,
        // Resolve for this PHP runtime: the CI matrix exercises Symfony 7.4/DoctrineBundle 2 and Symfony 8/Bundle 3.
        'config' => ['allow-plugins' => false],
    ];
    file_put_contents($directory.'/composer.json', json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
    $run([$composer, '--working-dir='.$directory, 'update', '--no-interaction', '--no-progress', '--prefer-dist', '--no-scripts', '--no-plugins'], 'Install seven real packages and Symfony into a fresh project');
    $run([$composer, '--working-dir='.$directory, 'check-platform-reqs'], 'Check the running PHP against installed requirements');
    $run([\PHP_BINARY, $directory.'/verify.php'], 'Run the application through its own autoloader, Security, Doctrine and MCP endpoint');
    echo "Symfony consumer check passed.\n";
} finally {
    if ($keep) {
        echo 'Kept '.$directory."\n";
    } else {
        $remove($directory);
    }
}
