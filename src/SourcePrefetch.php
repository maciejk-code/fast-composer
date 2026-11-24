<?php

namespace FastComposer;

use Composer\Cache;
use Composer\Config;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Util\Filesystem;
use Composer\Util\Git;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;
use Composer\Util\Url;

/** Best-effort parallel warming of Composer's own source-download cache. */
final class SourcePrefetch
{
    /**
     * @param list<string> $args
     * @return array{int,int}|null successful and failed mirror groups; null when bypassed
     */
    public static function run(string $root, array $args): ?array
    {
        if (!self::enabled($args) || !is_file($root.'/composer.lock') || !InProcessComposer::loadClasses()) {
            return null;
        }
        [$config] = self::configuration($root);
        $cache = (string) $config->get('cache-vcs-dir');
        if ($config->get('cache-read-only') || $cache === '' || !Cache::isUsable($cache)) {
            return null;
        }
        $installedPath = rtrim((string) $config->get('vendor-dir'), '/\\').'/composer/installed.json';
        $installed = is_file($installedPath) ? JsonFile::read($installedPath) : [];
        $lock = JsonFile::read($root.'/composer.lock');
        $noDev = in_array('--no-dev', $args, true) || (bool) getenv('COMPOSER_NO_DEV');
        $groups = [];
        foreach (self::candidates($lock, $installed, $installedPath, $noDev, $config->get('preferred-install'), $args) as $package) {
            $key = self::cacheKey($package['source']['url']);
            $groups[$key][] = $package;
        }
        $commands = [];
        foreach ($groups as $key => $packages) {
            // Composer's cache key is lossy. Do not concurrently or speculatively
            // share a cache directory between different URL spellings.
            if (count(array_unique(array_column(array_column($packages, 'source'), 'url'))) !== 1) {
                continue;
            }
            $names = array_column($packages, 'name');
            $commands[$key] = [[
                PHP_BINARY, '-r',
                'require $argv[1]; require $argv[2]; require $argv[3]; '
                .'exit(\FastComposer\SourcePrefetch::worker(array_slice($argv, 4)));',
                '--', __DIR__.'/InProcessComposer.php', __DIR__.'/JsonFile.php', __FILE__,
                ...$names,
            ], $root, ['GIT_TERMINAL_PROMPT' => '0', 'SSH_ASKPASS_REQUIRE' => 'never']];
        }
        $results = Process::runMany($commands);
        $success = count(array_filter($results, static fn (array $result): bool => $result[0] === 0));
        return [$success, count($results) - $success];
    }

    /** @param list<string> $args */
    public static function enabled(array $args): bool
    {
        $manifest = getenv('COMPOSER');
        if (($manifest !== false && $manifest !== '' && $manifest !== 'composer.json')
            || (bool) getenv('COMPOSER_DISABLE_NETWORK')
        ) {
            return false;
        }
        foreach ($args as $arg) {
            if (in_array($arg, ['--dry-run', '--no-cache', '--no-install', '--help', '-h', '-d', '--'], true)
                || str_starts_with($arg, '--working-dir')
                || str_starts_with($arg, '-d')
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<array> locked remote Git packages expected to install from source
     */
    public static function candidates(array $lock, array $installed, string $installedPath, bool $noDev, mixed $preference = 'dist', array $args = []): array
    {
        $byName = [];
        foreach ($installed['packages'] ?? [] as $package) {
            if (isset($package['name'])) {
                $byName[$package['name']] = $package;
            }
        }
        $packages = array_merge($lock['packages'] ?? [], $noDev ? [] : ($lock['packages-dev'] ?? []));
        $result = [];
        foreach ($packages as $package) {
            $source = $package['source'] ?? [];
            $url = $source['url'] ?? '';
            $reference = $source['reference'] ?? '';
            if (($source['type'] ?? null) !== 'git'
                || !is_string($url)
                || !self::isRemoteGit($url)
                || !is_string($reference)
                || !preg_match('/^[a-f0-9]{40}$/i', $reference)
                || !is_string($package['name'] ?? null)
                || !is_string($package['version'] ?? null)
            ) {
                continue;
            }
            $current = $byName[$package['name']] ?? [];
            if (!self::prefersSource($package, $current, $preference, $args)) {
                continue;
            }
            $relative = $current['install-path'] ?? null;
            if (($current['version'] ?? null) === $package['version']
                && ($current['source']['reference'] ?? null) === $reference
                && ($current['source']['url'] ?? null) === $url
                && is_string($relative)
                && $relative !== ''
                && is_dir(dirname($installedPath).'/'.$relative)
            ) {
                continue;
            }
            $result[] = $package;
        }
        return $result;
    }

    /** Predict Composer's source choice without changing its actual download behavior. */
    private static function prefersSource(array $package, array $current, mixed $preference, array $args): bool
    {
        if (empty($package['dist']['type']) || empty($package['dist']['url'])) {
            return true;
        }
        $isDev = static fn (string $version): bool => str_starts_with(strtolower($version), 'dev-')
            || str_ends_with(strtolower($version), '-dev');
        $dev = $isDev($package['version']);
        $previous = $current['installation-source'] ?? null;
        if ($previous === 'source') {
            return true;
        }
        if ($previous === 'dist' && !(!$isDev((string) ($current['version'] ?? '')) && $dev)) {
            return false;
        }
        $override = null;
        foreach ($args as $index => $arg) {
            if ($arg === '--prefer-source' || $arg === '--prefer-dist') {
                $override = substr($arg, 9);
            } elseif (str_starts_with($arg, '--prefer-install=')) {
                $override = substr($arg, 17);
            } elseif ($arg === '--prefer-install') {
                $override = $args[$index + 1] ?? null;
            }
        }
        if ($override === 'source' || $override === 'dist') {
            return $override === 'source';
        }
        if ($override !== 'auto' && is_string($preference) && $preference !== 'auto') {
            return $preference === 'source';
        }
        if (is_array($preference)) {
            foreach ($preference as $pattern => $value) {
                if (preg_match('{^'.str_replace('\\*', '.*', preg_quote((string) $pattern, '{')).'$}i', $package['name'])) {
                    return $value === 'source' || ($value === 'auto' && $dev);
                }
            }
        }
        return $dev;
    }

    private static function isRemoteGit(string $url): bool
    {
        // HTTPS without embedded credentials; Composer supplies configured credentials.
        if (preg_match('~^https://[a-zA-Z0-9][a-zA-Z0-9.-]*(?::[0-9]+)?/[^\s?#]+$~D', $url) === 1) {
            return true;
        }
        // Only unambiguous SSH URLs without embedded passwords or option-like hosts.
        return preg_match('~^(?:ssh://[a-zA-Z0-9_.-]+@[a-zA-Z0-9][a-zA-Z0-9.-]*(?::[0-9]+)?/|[a-zA-Z0-9_.-]+@[a-zA-Z0-9][a-zA-Z0-9.-]*:)[^\s]+$~D', $url) === 1;
    }

    private static function cacheKey(string $url): string
    {
        // Must match Composer\Downloader\GitDownloader, not Fast Composer's shallow mirror keys.
        return (string) preg_replace('{[^a-z0-9.]}i', '-', Url::sanitize($url));
    }

    /** @return array{Config,NullIO} */
    public static function configuration(string $root): array
    {
        $io = new NullIO();
        $config = Factory::createConfig($io, $root);
        $config->merge(JsonFile::read($root.'/composer.json'), $root.'/composer.json');
        if (is_file($root.'/auth.json')) {
            $config->merge(['config' => JsonFile::read($root.'/auth.json')], $root.'/auth.json');
        }
        $io->loadConfiguration($config);
        return [$config, $io];
    }

    /**
     * Internal subprocess entry point. Package names, not source URLs or credentials,
     * cross the process boundary. Read the original project lock again in the worker.
     *
     * @param list<string> $names
     */
    public static function worker(array $names): int
    {
        try {
            $root = getcwd();
            if ($root === false || !InProcessComposer::loadClasses() || !self::enabled([])) {
                return 1;
            }
            [$config, $io] = self::configuration($root);
            $cache = (string) $config->get('cache-vcs-dir');
            if ($config->get('cache-read-only') || $cache === '' || !Cache::isUsable($cache)) {
                return 1;
            }
            $lock = JsonFile::read($root.'/composer.lock');
            $packages = self::candidates($lock, [], '', false, 'source');
            $packages = array_values(array_filter(
                $packages,
                static fn (array $package): bool => in_array($package['name'], $names, true)
            ));
            if ($packages === []) {
                return 1;
            }
            $url = $packages[0]['source']['url'];
            foreach ($packages as $package) {
                if ($package['source']['url'] !== $url) {
                    return 1;
                }
            }
            $config->prohibitUrlByConfig($url);
            $path = rtrim($cache, '/\\').'/'.self::cacheKey($url);
            if (is_link($path) || (!is_dir($cache) && !@mkdir($cache, 0700, true) && !is_dir($cache))) {
                return 1;
            }
            // Coordinate Fast Composer workers from different projects. Standard Composer
            // does not take this lock: concurrent external installs are not supported.
            $handle = @fopen($path.'.fast-composer.lock', 'c');
            if ($handle === false) {
                return 1;
            }
            try {
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    return 1;
                }
                ProcessExecutor::setTimeout((int) $config->get('process-timeout'));
                $process = new ProcessExecutor($io);
                Git::cleanEnv($process);
                $git = new Git($io, $config, $process, new Filesystem($process));
                $git->setHttpDownloader(new HttpDownloader($io, $config));
                foreach ($packages as $package) {
                    if (!$git->fetchRefOrSyncMirror($url, $path.'/', $package['source']['reference'], $package['version'])) {
                        return 1;
                    }
                }
                return 0;
            } finally {
                fclose($handle);
            }
        } catch (\Throwable) {
            // Never print worker exceptions (they may contain remote credentials).
            return 1;
        }
    }
}
