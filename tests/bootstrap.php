<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// The demo's compiled container depends on no runtime (DemoRuntimeSettings serves each runtime's settings),
// so the whole run shares one, compiled on first use in a directory of its own and removed at the end.
$shared = sys_get_temp_dir().'/verified-human-approval-test-'.getmypid().'-'.bin2hex(random_bytes(4));
putenv('VERIFIED_HUMAN_APPROVAL_TEST_CACHE='.$shared);
$_SERVER['VERIFIED_HUMAN_APPROVAL_TEST_CACHE'] = $_ENV['VERIFIED_HUMAN_APPROVAL_TEST_CACHE'] = $shared;
register_shutdown_function(static function () use ($shared): void {
    (new Symfony\Component\Filesystem\Filesystem())->remove($shared);
});
