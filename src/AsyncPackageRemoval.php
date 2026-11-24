<?php

namespace FastComposer;

/** Defer only actual rm -rf operations on known package paths containing node_modules. */
final class AsyncPackageRemoval
{
    /** @param list<string> $args */
    public static function run(string $root, array $args, callable $delegate): int
    {
        if (
            PHP_OS_FAMILY === 'Windows' || getenv('FAST_COMPOSER_ASYNC_REMOVE') === '0'
            || !SourcePrefetch::enabled($args) || in_array('--download-only', $args, true)
            || !InProcessComposer::loadClasses()
        ) {
            return $delegate();
        }
        $temp = null;
        $oldPath = getenv('PATH');
        $oldManifest = getenv('FAST_COMPOSER_REMOVE_MANIFEST');
        try {
            [$config] = SourcePrefetch::configuration($root);
            $installedPath = rtrim((string) $config->get('vendor-dir'), '/\\').'/composer/installed.json';
            $paths = self::eligiblePaths($root, $installedPath);
            $rm = self::findRm((string) $oldPath);
            if ($paths !== [] && $rm !== null) {
                $temp = sys_get_temp_dir().'/fast-composer-rm-'.bin2hex(random_bytes(12));
                if (!mkdir($temp, 0700)) {
                    throw new \RuntimeException('Cannot create removal shim');
                }
                $manifest = $temp.'/manifest.json';
                $shim = $temp.'/rm';
                if (
                    file_put_contents($manifest, json_encode(['rm' => $rm, 'paths' => $paths], JSON_THROW_ON_ERROR)) === false
                    || file_put_contents($shim, "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY).' '
                        .escapeshellarg(__DIR__.'/../bin/remove-package-worker.php').' "$@"'."\n") === false
                    || !chmod($shim, 0700)
                ) {
                    throw new \RuntimeException('Cannot write removal shim');
                }
                putenv('FAST_COMPOSER_REMOVE_MANIFEST='.$manifest);
                putenv('PATH='.$temp.PATH_SEPARATOR.$oldPath);
            }
        } catch (\Throwable) {
            // Setup failure leaves standard Composer responsible for deletion.
        }
        try {
            return $delegate();
        } finally {
            $oldPath === false ? putenv('PATH') : putenv('PATH='.$oldPath);
            $oldManifest === false ? putenv('FAST_COMPOSER_REMOVE_MANIFEST') : putenv('FAST_COMPOSER_REMOVE_MANIFEST='.$oldManifest);
            if ($temp !== null) {
                @unlink($temp.'/rm');
                @unlink($temp.'/manifest.json');
                @rmdir($temp);
            }
        }
    }

    /** @return list<string> real package directories within the project */
    public static function eligiblePaths(string $root, string $installedPath): array
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_file($installedPath)) {
            return [];
        }
        $paths = [];
        foreach (JsonFile::read($installedPath)['packages'] ?? [] as $package) {
            $relative = $package['install-path'] ?? null;
            if (!is_string($relative) || $relative === '') {
                continue;
            }
            $raw = dirname($installedPath).'/'.$relative;
            $path = realpath($raw);
            if (
                $path === false || !str_starts_with($path, $realRoot.'/')
                || is_link($raw) || !is_dir($path.'/node_modules') || is_link($path.'/node_modules')
            ) {
                continue;
            }
            $paths[] = $path;
        }
        return array_values(array_unique($paths));
    }

    private static function findRm(string $path): ?string
    {
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory !== '' && is_executable($directory.'/rm')) {
                $real = realpath($directory.'/rm');
                return $real !== false ? $real : null;
            }
        }
        return null;
    }
}
