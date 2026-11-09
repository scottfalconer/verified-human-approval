<?php

declare(strict_types=1);

// The Drupal integration suite deliberately does not load tests/bootstrap.php:
// its dependency graph must be the independent consumer's, not the demo's.
$drupalRoot = getenv('VHA_DRUPAL_ROOT');
if (!is_string($drupalRoot) || !is_file($drupalRoot.'/core/tests/bootstrap.php')) {
    throw new RuntimeException('Set VHA_DRUPAL_ROOT to the independent Drupal consumer web directory.');
}
require $drupalRoot.'/core/tests/bootstrap.php';
