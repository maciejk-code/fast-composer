<?php
// The cache holds Git directories and solver input: a cache directory other local users can
// write to is refused instead of trusted.
use FastComposer\Snapshot;

$cache = $base.'/shared-cache';
mkdir($cache, 0700, true);
chmod($cache, 0777);
putenv('FAST_COMPOSER_CACHE_DIR='.$cache);
try {
    $refused = false;
    try {
        new Snapshot($base);
    } catch (\RuntimeException $e) {
        $refused = str_contains($e->getMessage(), 'Refusing to use Fast Composer cache directory');
    }
    fc_assert(!function_exists('posix_geteuid') || $refused, 'world-writable cache directory must be refused');

    chmod($cache, 0700);
    new Snapshot($base);

    // A missing cache directory is created private to the user.
    putenv('FAST_COMPOSER_CACHE_DIR='.$base.'/new/cache');
    new Snapshot($base);
    fc_assert((fileperms($base.'/new/cache') & 0077) === 0, 'new cache directory must be private');
} finally {
    putenv('FAST_COMPOSER_CACHE_DIR='.$base.'/cache');
}
