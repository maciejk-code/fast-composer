<?php

use FastComposer\AsyncPackageRemoval;
use FastComposer\Process;

if (PHP_OS_FAMILY === 'Windows') {
    return;
}
$root = $base.'/project';
$installedPath = $root.'/vendor/composer/installed.json';
$package = $root.'/plugins/example';
mkdir(dirname($installedPath), 0700, true);
mkdir($package.'/node_modules', 0700, true);
file_put_contents($package.'/node_modules/generated', 'data');
file_put_contents($installedPath, json_encode(['packages' => [['install-path' => '../../plugins/example']]]));
fc_assert(AsyncPackageRemoval::eligiblePaths($root, $installedPath) === [$package], 'Custom installer path with node_modules');
$manifest = $base.'/manifest.json';
file_put_contents($manifest, json_encode(['rm' => '/bin/rm', 'paths' => [$package]]));
$worker = dirname(__DIR__, 2).'/bin/remove-package-worker.php';
$old = getenv('FAST_COMPOSER_REMOVE_MANIFEST');
putenv('FAST_COMPOSER_REMOVE_MANIFEST='.$manifest);
try {
    [$code] = Process::run([PHP_BINARY, $worker, '-rf', $package]);
    fc_assert($code === 0 && !file_exists($package), 'Actual deletion frees path immediately');
    mkdir($package, 0700, true);
    file_put_contents($package.'/new', 'replacement');
    $deadline = microtime(true) + 5;
    do {
        usleep(10000);
        $trash = glob(dirname($package).'/.fast-composer-trash-*');
    } while ($trash !== [] && microtime(true) < $deadline);
    fc_assert($trash === [] && is_file($package.'/new'), 'Cleanup never touches replacement');
} finally {
    $old === false ? putenv('FAST_COMPOSER_REMOVE_MANIFEST') : putenv('FAST_COMPOSER_REMOVE_MANIFEST='.$old);
}
