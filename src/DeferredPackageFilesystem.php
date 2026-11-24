<?php

namespace FastComposer;

use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

/** Scoped filesystem for Composer's own downloader removal calls, never PATH interception. */
final class DeferredPackageFilesystem extends Filesystem
{
    /** @var array<string,array{dev:int,ino:int}> */
    private array $allowed = [];
    /** @var array<string,resource> */
    private array $pending = [];

    public function __construct(private readonly string $root, ProcessExecutor $process)
    {
        parent::__construct($process);
        register_shutdown_function([$this, 'finish']);
    }

    private function exactPath(string $path): ?string
    {
        $absolute = rtrim(str_starts_with($path, '/') ? $path : $this->root.'/'.$path, '/');
        clearstatcache(true);
        $real = realpath($absolute);
        // Reject symlinks anywhere in the path, noncanonical segments and external paths.
        return $real === $absolute && str_starts_with($real, $this->root.'/') ? $real : null;
    }

    public function allow(string $path): void
    {
        $path = $this->exactPath($path);
        if ($path === null || !is_dir($path.'/node_modules') || is_link($path.'/node_modules')) {
            return;
        }
        foreach ([$path, $path.'/node_modules'] as $candidate) {
            $stat = @lstat($candidate);
            if ($stat !== false && is_dir($candidate) && !is_link($candidate)) {
                $this->allowed[$candidate] = ['dev' => $stat['dev'], 'ino' => $stat['ino']];
            }
        }
    }

    private function defer(string $path): bool
    {
        $path = $this->exactPath($path);
        if ($path === null || !isset($this->allowed[$path])) {
            return false;
        }
        $expected = $this->allowed[$path];
        unset($this->allowed[$path]);
        $stat = @lstat($path);
        $rootStat = @lstat($this->root);
        if (
            $stat === false || $rootStat === false || $stat['dev'] !== $rootStat['dev']
            || $stat['dev'] !== $expected['dev'] || $stat['ino'] !== $expected['ino']
            || !is_dir($path) || is_link($path)
        ) {
            return false;
        }
        $trash = $this->root.'/.fast-composer-trash-'.bin2hex(random_bytes(16));
        if (!@mkdir($trash, 0700)) {
            return false;
        }
        if (!@rename($path, $trash.'/package')) {
            @rmdir($trash);
            return false;
        }
        clearstatcache(true);
        $moved = @lstat($trash.'/package');
        if ($moved === false || $moved['dev'] !== $expected['dev'] || $moved['ino'] !== $expected['ino']) {
            // Never delete an object whose identity changed between validation and rename.
            if (!file_exists($path) && !is_link($path) && @rename($trash.'/package', $path)) {
                @rmdir($trash);
            }
            throw new \RuntimeException('Package changed during removal; inspect '.$trash);
        }
        $command = PHP_OS_FAMILY === 'Darwin'
            ? ['/bin/rm', '-rf', '-x', '--', $trash.'/package']
            : ['/bin/rm', '-rf', '--one-file-system', '--', $trash.'/package'];
        try {
            $process = proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ], $pipes);
        } catch (\Throwable) {
            $process = false;
        }
        if (!is_resource($process)) {
            // Do not silently lose track of a moved directory if cleanup cannot start.
            if (!file_exists($path) && !is_link($path) && @rename($trash.'/package', $path)) {
                @rmdir($trash);
                return false;
            }
            throw new \RuntimeException('Cannot start package cleanup; inspect '.$trash);
        }
        $this->pending[$trash] = $process;
        return true;
    }

    public function removeDirectory(string $directory)
    {
        return $this->defer($directory) || parent::removeDirectory($directory);
    }

    public function removeDirectoryAsync(string $directory)
    {
        return $this->defer($directory) ? \React\Promise\resolve(true) : parent::removeDirectoryAsync($directory);
    }

    /** Wait for cleanup before the normal command returns; retain failed quarantine for recovery. */
    public function finish(): bool
    {
        $ok = true;
        foreach ($this->pending as $trash => $process) {
            unset($this->pending[$trash]);
            $code = proc_close($process);
            clearstatcache(true);
            if ($code !== 0 || !@rmdir($trash)) {
                fwrite(STDERR, '[fast-composer] package cleanup failed; inspect '.$trash."\n");
                $ok = false;
            }
        }
        return $ok;
    }
}
