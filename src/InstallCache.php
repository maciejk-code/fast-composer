<?php

namespace FastComposer;

use Composer\Cache;
use Composer\Config;
use Composer\Util\Url;

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
        $keys = [];
        foreach (LockFile::packages($lockData) as $package) {
            if (is_string($package['source']['url'] ?? null)) {
                $url = $package['source']['url'];
                $key = (string) preg_replace('{[^a-z0-9.]}i', '-', Url::sanitize($url));
                if (in_array($key, ['', '.', '..'], true) || (isset($keys[$key]) && $keys[$key] !== $url)) {
                    return null;
                }
                $keys[$key] = $url;
            }
        }
        $project = realpath($root);
        if ($project === false) {
            return null;
        }
        // Stable across dependency changes. A new namespace avoids trusting old caches
        // without a persistent key registry.
        $path = $base.'/fast-composer-install-v2-'.hash('sha256', $project);
        if (!is_dir($path) && !@mkdir($path, 0700)) {
            return null;
        }
        if (is_link($path) || fileowner($path) !== posix_geteuid() || (fileperms($path) & 0077) !== 0) {
            return null;
        }
        $lockPath = $path.'/.operation.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
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
        try {
            if (!self::reserveKeys($path, $keys)) {
                fclose($lock);
                return null;
            }
            return new self($path, $lock);
        } catch (\Throwable) {
            fclose($lock);
            return null;
        }
    }

    /**
     * Reserve Composer's lossy cache keys across the lifetime of the project cache.
     * Called only with the operation lock held. No raw repository URL is persisted.
     *
     * @param array<string,string> $keys
     */
    private static function reserveKeys(string $path, array $keys): bool
    {
        $registry = $path.'/.url-keys.json';
        if (is_link($registry) || (file_exists($registry) && !is_file($registry))) {
            return false;
        }
        $known = JsonFile::readIfExists($registry);
        foreach ($known as $digest) {
            if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                return false;
            }
        }
        $next = $known;
        foreach ($keys as $key => $url) {
            $digest = hash('sha256', $url);
            $mirror = $path.'/'.$key;
            // A mirror without a matching registry entry may be from a native
            // fallback URL or a partially removed registry. Never adopt it blindly.
            if (isset($known[$key]) ? $known[$key] !== $digest : (file_exists($mirror) || is_link($mirror))) {
                return false;
            }
            if (is_link($mirror) || (file_exists($mirror) && !is_dir($mirror))) {
                return false;
            }
            $next[$key] = $digest;
        }
        if ($next !== $known || !is_file($registry)) {
            JsonFile::atomicWrite($registry, json_encode($next, JSON_THROW_ON_ERROR), true);
        }
        return true;
    }

    public function close(): void
    {
        if (is_resource($this->lock)) {
            fclose($this->lock);
        }
    }
}
