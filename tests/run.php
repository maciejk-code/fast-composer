<?php
// Runs every tests/unit/*.php file in isolation: a fresh temporary $base directory and cache.
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/support.php';

$failed = 0;
foreach (glob(__DIR__.'/unit/*.php') ?: [] as $file) {
    $name = basename($file, '.php');
    $base = sys_get_temp_dir().'/fast-composer-test-'.bin2hex(random_bytes(4));
    mkdir($base, 0700, true);
    putenv('FAST_COMPOSER_CACHE_DIR='.$base.'/cache');
    try {
        (static function (string $base) use ($file): void {
            require $file;
        })($base);
        echo "$name: OK\n";
    } catch (Throwable $e) {
        $failed++;
        echo "$name: FAIL ".$e->getMessage()."\n";
    } finally {
        fc_rrmdir($base);
    }
}
exit($failed === 0 ? 0 : 1);
