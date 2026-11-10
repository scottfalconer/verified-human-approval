<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$directory = sys_get_temp_dir().'/editorial-assets-'.bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$process = proc_open([\PHP_BINARY, __DIR__.'/browser_fixture.php', $directory, 'http://localhost:8028'], [1 => ['file', $directory.'/fixture.log', 'w'], 2 => ['file', $directory.'/fixture.log', 'a']], $pipes, dirname(__DIR__));
if (!is_resource($process) || 0 !== proc_close($process)) {
    throw new RuntimeException('Asset fixture initialization failed.');
}
$settings = json_decode(file_get_contents($directory.'/fixture.json'), true, flags: \JSON_THROW_ON_ERROR)['env'];
foreach ($settings as $name => $value) {
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv($name.'='.$value);
}
$kernel = new App\Kernel('dev', false);
$application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$application->setAutoExit(false);
try {
    $code = $application->run(new Symfony\Component\Console\Input\ArrayInput(['command' => 'assets:install', 'target' => dirname(__DIR__).'/public']));
} finally {
    $kernel->shutdown();
    (new Symfony\Component\Filesystem\Filesystem())->remove($directory);
}

exit($code);
