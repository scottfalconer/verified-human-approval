<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
if (!getenv('DATABASE_URL') && is_file(dirname(__DIR__).'/var/runtime.php')) {
    foreach (require dirname(__DIR__).'/var/runtime.php' as $name => $value) {
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }
}
