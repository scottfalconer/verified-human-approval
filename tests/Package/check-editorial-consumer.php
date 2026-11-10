<?php

declare(strict_types=1);

/* Install the independent private Editorial Desk application against the current packages. */
$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir().'/vha-editorial-consumer-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
$argv ??= [];
$keep = in_array('--keep', $argv, true);
$browser = in_array('--browser', $argv, true);
$composer = getenv('COMPOSER_BIN') ?: 'composer';

$run = static function (array $command, string $description) use ($directory): void {
    echo '> '.$description."\n";
    $process = proc_open($command, [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR], $pipes, $directory);
    if (!is_resource($process) || 0 !== proc_close($process)) {
        throw new RuntimeException($description.' failed.');
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
    $source = $root.'/tests/EditorialDesk';
    $files = new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($source): bool {
            $relative = substr($file->getPathname(), strlen($source) + 1);
            $top = explode('/', $relative, 2)[0];
            if ($file->isLink() || preg_match('#^(?:public/bundles|config/reference.php)(?:/|$)#', $relative)) {
                return false;
            }

            return in_array($top, ['src', 'config', 'templates', 'public', 'tests'], true)
                || in_array($relative, ['composer.json', 'package.json', 'package-lock.json', 'phpunit.xml.dist', 'README.md'], true);
        });
    foreach (new RecursiveIteratorIterator($files, RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        $target = $directory.'/'.$relative;
        if ($file->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0700, true);
            }
        } else {
            copy($file->getPathname(), $target);
        }
    }
    $manifest = json_decode(file_get_contents($directory.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
    $manifest['repositories'][0]['url'] = $root.'/src/*';
    file_put_contents($directory.'/composer.json', json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
    $run([$composer, 'update', '--no-interaction', '--no-progress', '--prefer-dist', '--no-scripts', '--no-plugins'], 'Install current packages with the independent application dependencies');
    $run([$composer, 'check-platform-reqs'], 'Check the consumer runtime requirements');
    $run([\PHP_BINARY, 'vendor/bin/phpunit', '--colors=never'], 'Run the independent application acceptance suite');
    $run([\PHP_BINARY, 'tests/install-assets.php'], 'Install production bundle assets through the application kernel');
    $run([\PHP_BINARY, 'tests/http_smoke.php'], 'Run real HTTP and MCP checks on fresh state');
    if ($browser) {
        $run(['npm', 'ci', '--ignore-scripts', '--no-audit', '--no-fund'], 'Install the pinned browser test dependency');
        $run(['node', 'tests/browser_smoke.mjs'], 'Run production JavaScript and virtual WebAuthn in Chrome');
    }
    echo "Editorial Desk consumer check passed.\n";
} finally {
    if ($keep) {
        echo 'Kept '.$directory."\n";
    } else {
        $remove($directory);
    }
}
