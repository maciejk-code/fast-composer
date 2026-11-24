<?php

namespace FastComposer;

use Composer\Cache;
use Composer\Config;

/** A persistent, private VCS cache not shared with plain Composer. */
final class InstallCache
{
    /** @param resource $lock */
    private function __construct(public readonly string $path, private $lock)
    {
    }

    public static function open(string $root, Config $config): ?self
    {
        if (
            PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid')
            || $config->get('cache-read-only') || getenv('COMPOSER_CACHE_VCS_DIR') !== false
            || !Cache::isUsable((string) $config->get('cache-vcs-dir'))
        ) {
            return null;
        }
        $base = (string) $config->get('cache-dir');
        if ($base === '' || !Cache::isUsable($base) || (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base))) {
            return null;
        }
        $base = realpath($base);
        if ($base === false || $base === '/' || $base === realpath($root)) {
            return null;
        }
        $lockData = JsonFile::read($root.'/composer.lock');
        $urls = [];
        foreach (LockFile::packages($lockData) as $package) {
            if (is_string($package['source']['url'] ?? null)) {
                $urls[] = $package['source']['url'];
            }
        }
        sort($urls, SORT_STRING);
        // Distinct URL sets do not reuse a namespace with a lossy Composer cache key.
        $path = $base.'/fast-composer-install-'.hash('sha256', $root."\0".implode("\0", $urls));
        if (!is_dir($path) && !@mkdir($path, 0700)) {
            return null;
        }
        if (is_link($path) || fileowner($path) !== posix_geteuid() || (fileperms($path) & 0077) !== 0) {
            return null;
        }
        $lockPath = $path.'/.operation.lock';
        if (is_link($lockPath)) {
            return null;
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            return null;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return null;
        }
        return new self($path, $lock);
    }

    public function close(): void
    {
        if (is_resource($this->lock)) {
            fclose($this->lock);
        }
    }
}
