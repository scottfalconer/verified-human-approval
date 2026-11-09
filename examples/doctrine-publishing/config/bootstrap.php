<?php

declare(strict_types=1);

/*
 * Reads var/settings.php, which bin/setup wrote: the origin, the database URL and the secrets, kept outside the code
 * and the database. A real environment variable wins over it, as over Symfony's .env files, so DATABASE_URL=... or
 * APP_ENV=prod on the command line overrides a setting. APP_ENV defaults to dev, with debug on.
 */
(static function (string $file): void {
    $settings = is_file($file) ? require $file : [];
    foreach (is_array($settings) ? $settings : [] as $name => $value) {
        if (is_string($name) && !isset($_SERVER[$name]) && false === getenv($name)) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
    }
    $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? (getenv('APP_ENV') ?: 'dev');
    $_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? (getenv('APP_DEBUG') ?: ('prod' === $_SERVER['APP_ENV'] ? '0' : '1'));
})(dirname(__DIR__).'/var/settings.php');
