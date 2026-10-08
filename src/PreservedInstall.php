<?php

namespace FastComposer;

/**
 * Local-only install overlay: keep dirty Git source checkouts at their installed
 * revisions while Composer installs everything else. Never changes the real lock.
 */
final class PreservedInstall
{
    public function __construct(private readonly string $root)
    {
    }

    /** @param list<string> $args Composer install arguments without Fast Composer flags. */
    public function run(array $args): int
    {
        if (!is_file($this->root.'/composer.lock')) {
            throw new \RuntimeException('--skip-dirty-packages requires composer.lock');
        }
        $custom = getenv('COMPOSER');
        if ($custom !== false && $custom !== '' && $custom !== 'composer.json') {
            throw new \RuntimeException('--skip-dirty-packages does not support custom COMPOSER manifests');
        }
        $lock = JsonFile::read($this->root.'/composer.lock');
        $installedPath = $this->installedPath();
        if (!is_file($installedPath)) {
            return $this->delegate($args);
        }
        $installed = JsonFile::read($installedPath);
        if (!isset($installed['packages']) || !is_array($installed['packages'])) {
            throw new \RuntimeException('Unsupported installed.json format: '.$installedPath);
        }

        $locked = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $index => $package) {
                if (isset($package['name'])) {
                    $locked[$package['name']] = [$section, $index, $package];
                }
            }
        }

        $preserved = [];
        foreach ($installed['packages'] as $package) {
            if (($package['source']['type'] ?? null) !== 'git' || !isset($package['name'])) {
                continue;
            }
            $path = $this->checkoutPath($installedPath, $package);
            if ($path === null) {
                continue;
            }
            $reason = $this->localChanges($path);
            if ($reason === null) {
                continue;
            }
            $name = $package['name'];
            if (!isset($locked[$name])) {
                throw new \RuntimeException('Cannot preserve '.$name.': removed from composer.lock; commit or stash your changes first');
            }
            [$section, $index, $target] = $locked[$name];
            if ($section === 'packages-dev' && in_array('--no-dev', $args, true)) {
                throw new \RuntimeException('Cannot preserve dev dependency '.$name.' during --no-dev install');
            }
            $this->assertCompatible($package, $target, $name);
            $lock[$section][$index]['source']['reference'] = $package['source']['reference'];
            if (isset($package['dist']['reference'])) {
                $lock[$section][$index]['dist']['reference'] = $package['dist']['reference'];
            }
            $preserved[] = $name.' ('.$reason.')';
        }

        if ($preserved === []) {
            return $this->delegate($args);
        }

        // COMPOSER resolves the temporary lock as a sibling of its temporary json.
        $basename = '.fast-composer-preserve-'.bin2hex(random_bytes(12));
        $temporaryJson = $this->root.'/'.$basename.'.json';
        $temporaryLock = $this->root.'/'.$basename.'.lock';
        try {
            $sourceJson = file_get_contents($this->root.'/composer.json');
            if ($sourceJson === false) {
                throw new \RuntimeException('Cannot read composer.json');
            }
            $this->writeNewFile($temporaryJson, $sourceJson);
            $this->writeNewFile(
                $temporaryLock,
                json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"
            );
            foreach ($preserved as $name) {
                fwrite(STDERR, '[fast-composer] preserving local Git package '.$name."\n");
            }
            $code = $this->delegate($args, $basename.'.json');
            if ($code === 0) {
                fwrite(STDERR, "[fast-composer] WARNING: local installation intentionally differs from composer.lock; run regular composer install once local changes are safe to replace.\n");
            }
            return $code;
        } finally {
            @unlink($temporaryLock);
            @unlink($temporaryJson);
        }
    }

    private function installedPath(): string
    {
        [$code, $output, $error] = Process::run(
            ['composer', 'config', 'vendor-dir', '--absolute', '--no-plugins', '--no-scripts'],
            $this->root
        );
        if ($code !== 0 || trim($output) === '') {
            throw new \RuntimeException('Cannot resolve Composer vendor-dir: '.trim($error));
        }
        $vendor = trim($output);
        if (!str_starts_with($vendor, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $vendor)) {
            throw new \RuntimeException('Composer returned non-absolute vendor-dir: '.$vendor);
        }
        return rtrim($vendor, '/\\').'/composer/installed.json';
    }

    /** @param array<string,mixed> $package */
    private function checkoutPath(string $installedPath, array $package): ?string
    {
        $relative = $package['install-path'] ?? null;
        if (!is_string($relative) || $relative === '' || ($package['installation-source'] ?? null) !== 'source') {
            return null;
        }
        $path = realpath(dirname($installedPath).'/'.$relative);
        if ($path === false || !is_dir($path)) {
            return null;
        }
        [$code, $root] = Process::run(['git', '-C', $path, 'rev-parse', '--show-toplevel']);
        // Do not protect a non-Git package merely because the project root is Git.
        return $code === 0 && realpath(trim($root)) === $path ? $path : null;
    }

    private function localChanges(string $path): ?string
    {
        [$code, $status, $error] = Process::run(['git', '-C', $path, 'status', '--porcelain=v1', '--untracked-files=all']);
        if ($code !== 0) {
            throw new \RuntimeException('Cannot inspect '.$path.': '.trim($error));
        }
        if (trim($status) !== '') {
            return 'uncommitted or untracked files';
        }
        // Detached HEAD is the normal state for a clean Composer Git install.
        [$code] = Process::run(['git', '-C', $path, 'symbolic-ref', '-q', '--short', 'HEAD']);
        if ($code !== 0) {
            return null;
        }
        [$code] = Process::run(['git', '-C', $path, 'rev-parse', '--verify', '@{upstream}']);
        if ($code !== 0) {
            return 'branch without upstream (push status unknown)';
        }
        [$code, $ahead, $error] = Process::run(['git', '-C', $path, 'rev-list', '--count', '@{upstream}..HEAD']);
        if ($code !== 0) {
            throw new \RuntimeException('Cannot inspect local commits in '.$path.': '.trim($error));
        }
        return (int) trim($ahead) > 0 ? 'unpushed commits' : null;
    }

    /** @param array<string,mixed> $installed @param array<string,mixed> $locked */
    private function assertCompatible(array $installed, array $locked, string $name): void
    {
        if (
            ($installed['version'] ?? null) !== ($locked['version'] ?? null)
            || ($installed['type'] ?? 'library') !== ($locked['type'] ?? 'library')
            || ($installed['source']['type'] ?? null) !== 'git'
            || ($locked['source']['type'] ?? null) !== 'git'
            || ($installed['source']['url'] ?? null) !== ($locked['source']['url'] ?? null)
            || !is_string($installed['source']['reference'] ?? null)
        ) {
            throw new \RuntimeException('Cannot safely preserve '.$name.': installed version, type or source differs from lock (only Git revision changes are supported)');
        }
        // Changed dependency or autoload metadata would make the overlay inconsistent.
        foreach (['require', 'require-dev', 'conflict', 'provide', 'replace', 'suggest', 'autoload', 'autoload-dev', 'include-path', 'target-dir', 'extra', 'bin'] as $key) {
            if (JsonFile::canonical($installed[$key] ?? null) !== JsonFile::canonical($locked[$key] ?? null)) {
                throw new \RuntimeException('Cannot safely preserve '.$name.': locked '.$key.' differs from installed metadata');
            }
        }
        if (isset($locked['dist']['reference']) && !isset($installed['dist']['reference'])) {
            throw new \RuntimeException('Cannot safely preserve '.$name.': installed dist reference is missing');
        }
    }

    private function writeNewFile(string $path, string $content): void
    {
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create '.$path);
        }
        try {
            if (fwrite($handle, $content) !== strlen($content)) {
                throw new \RuntimeException('Cannot write '.$path);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string> $args */
    private function delegate(array $args, ?string $composerFile = null): int
    {
        $old = getenv('COMPOSER');
        try {
            if ($composerFile !== null) {
                putenv('COMPOSER='.$composerFile);
            }
            [$code] = Process::run(array_merge(['composer'], $args), $this->root, true);
            return $code;
        } finally {
            if ($composerFile !== null) {
                $old === false ? putenv('COMPOSER') : putenv('COMPOSER='.$old);
            }
        }
    }
}
