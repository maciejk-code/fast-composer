<?php
namespace FastComposer;

final class Application
{
    public const VERSION = '0.1.0';

    public function run(array $args): int
    {
        $cmd = $args[0] ?? 'help';
        if (in_array($cmd, ['help', '--help', '-h'], true)) {
            $this->help();
            return 0;
        }
        if (in_array($cmd, ['version', '--version', '-V'], true)) {
            echo 'fast-composer '.self::VERSION."\n";
            return 0;
        }

        $root = getcwd();
        if (!is_file($root.'/composer.json')) {
            fwrite(STDERR, "composer.json not found\n");
            return 2;
        }

        try {
            $rootCfg = $this->readJson($root.'/composer.json');
        } catch (\Throwable $e) {
            fwrite(STDERR, '[fast-composer] '.$e->getMessage()."\n");
            return 2;
        }

        $snapshot = new Snapshot($root);
        $operationLock = null;

        try {
            $operationLock = $this->acquireOperationLock($snapshot);

            if ($cmd === 'status') {
                return $this->status($snapshot, $rootCfg);
            }

            if ($cmd === 'verify') {
                $bad = 0;
                foreach ($snapshot->verifyLock($rootCfg) as $name => $result) {
                    if (!$result['reachable']) {
                        $status = 'MISSING';
                        $bad++;
                    } elseif (!$result['metadata_match']) {
                        $status = 'METADATA-MISMATCH';
                        $bad++;
                    } else {
                        $status = 'OK';
                    }
                    printf("%s %s %s\n", $status, $name, substr($result['sha'], 0, 12));
                }
                return $bad ? 1 : 0;
            }

            if ($cmd === 'refresh') {
                $state = $snapshot->buildFromLockAndCache($rootCfg);
                printf(
                    "snapshot repos=%d versions=%d\n",
                    count($state['repos'] ?? []),
                    array_sum(array_map('count', $state['packages'] ?? []))
                );
                return 0;
            }

            // Installation is deliberately not accelerated. A committed lock is always installed
            // by standard Composer, preserving Composer as the final install authority.
            if ($cmd === 'install') {
                return $this->delegateComposer($args, $root);
            }

            if (!in_array($cmd, ['update', 'require'], true)) {
                fwrite(STDERR, "Unsupported command: $cmd\n");
                return 2;
            }

            // These modes either do not solve dependencies or can intentionally rewrite root
            // constraints in ways Fast Composer does not mirror yet. Preserve exact Composer behavior.
            if (($cmd === 'require' && $this->hasFlag($args, '--no-update'))
                || ($cmd === 'update' && ($this->hasFlag($args, '--lock') || $this->hasFlag($args, '--bump-after-update')))) {
                return $this->delegateComposer($args, $root);
            }

            $state = $snapshot->load();
            if (!$state || !$snapshot->isCompatible($state, $rootCfg)) {
                fwrite(STDOUT, "[fast-composer] priming snapshot with regular Composer\n");
                $code = $this->delegateComposer($args, $root);
                if ($code !== 0) {
                    return $code;
                }
                $snapshot->buildFromLockAndCache($this->readJson($root.'/composer.json'));
                return 0;
            }

            $ttl = $this->ttl();
            if ($cmd === 'require') {
                $this->refreshForRequire($snapshot, $state, $args);
            } else {
                $targets = $this->packageArguments(array_slice($args, 1));
                if ($targets === []) {
                    $count = $snapshot->refreshAllIfStale($state, $rootCfg, $ttl);
                    if ($count > 0) {
                        printf("[fast-composer] refreshed %d VCS repositories (TTL %ds)\n", $count, $ttl);
                    }
                } else {
                    $count = $snapshot->refreshPackages($state, $targets);
                    if ($count > 0) {
                        printf("[fast-composer] refreshed %d targeted VCS repositories\n", $count);
                    }
                }
            }

            return $this->runFastComposer($snapshot, $state, $rootCfg, $args, $root);
        } catch (\Throwable $e) {
            fwrite(STDERR, '[fast-composer] '.$e->getMessage()."\n");
            return 1;
        } finally {
            if (is_resource($operationLock)) {
                flock($operationLock, LOCK_UN);
                fclose($operationLock);
            }
        }
    }

    private function runFastComposer(Snapshot $snapshot, array &$state, array $rootCfg, array $args, string $root): int
    {
        $beforeLock = $snapshot->readLock();
        $fastComposer = $snapshot->writeFastComposer($rootCfg, $state);
        $fastLock = $snapshot->workLockPath();

        if (is_file($root.'/composer.lock')) {
            if (!copy($root.'/composer.lock', $fastLock)) {
                throw new \RuntimeException('Cannot prepare temporary lock file');
            }
        } elseif (is_file($fastLock)) {
            unlink($fastLock);
        }

        $oldComposerEnv = getenv('COMPOSER');
        try {
            putenv('COMPOSER='.$fastComposer);
            [$code] = Process::run(array_merge(['composer'], $args), $root, true);
        } finally {
            $oldComposerEnv === false ? putenv('COMPOSER') : putenv('COMPOSER='.$oldComposerEnv);
        }

        if ($code !== 0) {
            return $code;
        }

        if ($this->hasFlag($args, '--dry-run')) {
            fwrite(STDOUT, "[fast-composer] dry-run; real composer files unchanged\n");
            return 0;
        }

        if (!is_file($fastLock)) {
            throw new \RuntimeException('Composer did not produce a lock file');
        }

        if (($args[0] ?? null) === 'require') {
            $temporaryRoot = $this->readJson($fastComposer);
            foreach (['require', 'require-dev'] as $key) {
                if (isset($temporaryRoot[$key])) {
                    $rootCfg[$key] = $temporaryRoot[$key];
                } else {
                    unset($rootCfg[$key]);
                }
            }
        }

        $afterLock = $snapshot->readLock($fastLock);
        $snapshot->validateChangedPackages($beforeLock, $afterLock, $rootCfg);
        $snapshot->fixContentHash($fastLock, $rootCfg);

        $newComposer = ($args[0] ?? null) === 'require'
            ? json_encode($rootCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"
            : null;
        $newLock = file_get_contents($fastLock);
        if ($newLock === false) {
            throw new \RuntimeException('Cannot read generated lock file');
        }

        $this->publishAtomically($root, $newComposer, $newLock);
        $snapshot->mergeLockIntoSnapshot($state, $rootCfg, $snapshot->readLock());

        fwrite(STDOUT, "[fast-composer] lock verified and published\n");
        return 0;
    }

    private function refreshForRequire(Snapshot $snapshot, array &$state, array $args): void
    {
        $targets = $this->packageArguments(array_slice($args, 1));
        foreach ($targets as $spec) {
            [$name, $constraint] = array_pad(explode(':', $spec, 2), 2, null);
            if (!$this->isManagedPackage($state, $name)) {
                continue;
            }

            if (is_string($constraint) && str_starts_with($constraint, 'dev-') && strlen($constraint) > 4) {
                $snapshot->ensureBranch($state, $name, substr($constraint, 4));
            } else {
                $snapshot->refreshPackages($state, [$name]);
            }
        }
    }

    /** @return list<string> */
    private function packageArguments(array $args): array
    {
        $result = [];
        foreach ($args as $arg) {
            if (!is_string($arg) || $arg === '' || str_starts_with($arg, '-')) {
                continue;
            }
            $name = explode(':', $arg, 2)[0];
            if (str_contains($name, '/')) {
                $result[] = $arg;
            }
        }
        return array_values(array_unique($result));
    }

    private function isManagedPackage(array $state, string $package): bool
    {
        foreach ($state['repos'] ?? [] as $repo) {
            if (($repo['name'] ?? null) === $package && ($repo['managed'] ?? false) === true) {
                return true;
            }
        }
        return false;
    }

    private function status(Snapshot $snapshot, array $rootCfg): int
    {
        $state = $snapshot->load();
        if (!$state) {
            echo "snapshot: missing\n";
            return 1;
        }

        $ttl = $this->ttl();
        $repos = count($state['repos'] ?? []);
        $versions = array_sum(array_map('count', $state['packages'] ?? []));
        printf("version: %s\n", self::VERSION);
        printf("snapshot-format: %d\n", $state['format'] ?? 0);
        printf("repositories: %d\n", $repos);
        printf("versions: %d\n", $versions);
        printf("config-compatible: %s\n", $snapshot->isCompatible($state, $rootCfg) ? 'yes' : 'no');
        printf("refs-fresh: %s (ttl=%ds)\n", $snapshot->isFresh($state, $ttl) ? 'yes' : 'no', $ttl);

        foreach ($state['repos'] ?? [] as $url => $repo) {
            if (!empty($repo['last_error'])) {
                printf("warning: %s: %s\n", $url, $repo['last_error']);
            }
        }
        return 0;
    }

    private function delegateComposer(array $args, string $root): int
    {
        [$code] = Process::run(array_merge(['composer'], $args), $root, true);
        return $code;
    }

    private function ttl(): int
    {
        $value = getenv('FAST_COMPOSER_TTL');
        if ($value === false || $value === '') {
            return Snapshot::DEFAULT_TTL;
        }
        if (!preg_match('/^\d+$/', $value)) {
            throw new \RuntimeException('FAST_COMPOSER_TTL must be a non-negative integer number of seconds');
        }
        return (int) $value;
    }

    private function hasFlag(array $args, string $flag): bool
    {
        foreach ($args as $arg) {
            if ($arg === $flag || str_starts_with((string) $arg, $flag.'=')) {
                return true;
            }
        }
        return false;
    }

    private function acquireOperationLock(Snapshot $snapshot)
    {
        if (!is_dir($snapshot->dir()) && !mkdir($snapshot->dir(), 0777, true) && !is_dir($snapshot->dir())) {
            throw new \RuntimeException('Cannot create '.Snapshot::DIR);
        }
        $handle = fopen($snapshot->dir().'/operation.lock', 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open operation lock');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another fast-composer process is already running in this project');
        }
        return $handle;
    }

    private function publishAtomically(string $root, ?string $composerContents, string $lockContents): void
    {
        $composerPath = $root.'/composer.json';
        $lockPath = $root.'/composer.lock';
        $oldComposer = file_get_contents($composerPath);
        if ($oldComposer === false) {
            throw new \RuntimeException('Cannot back up composer.json');
        }
        $hadLock = is_file($lockPath);
        $oldLock = $hadLock ? file_get_contents($lockPath) : null;
        if ($hadLock && $oldLock === false) {
            throw new \RuntimeException('Cannot back up composer.lock');
        }

        try {
            if ($composerContents !== null) {
                $this->atomicWrite($composerPath, $composerContents);
            }
            $this->atomicWrite($lockPath, $lockContents);
        } catch (\Throwable $e) {
            $this->atomicWrite($composerPath, $oldComposer);
            if ($hadLock && is_string($oldLock)) {
                $this->atomicWrite($lockPath, $oldLock);
            } elseif (!$hadLock) {
                @unlink($lockPath);
            }
            throw $e;
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $tmp = $path.'.fast-composer-'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write $tmp");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot replace $path");
        }
    }

    private function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read $path");
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON: $path");
        }
        return $data;
    }

    private function help(): void
    {
        echo 'fast-composer '.self::VERSION."\n\n";
        echo "Commands:\n";
        echo "  fast-composer update [packages...] [composer options]\n";
        echo "  fast-composer require vendor/package:constraint [composer options]\n";
        echo "  fast-composer refresh\n";
        echo "  fast-composer verify\n";
        echo "  fast-composer status\n";
        echo "  fast-composer install [args...]  (delegates to standard Composer)\n";
        echo "  fast-composer --version\n\n";
        echo "FAST_COMPOSER_TTL controls full-update ref validation (default: 300 seconds).\n";
        echo "Targeted update/require always refreshes the matching managed VCS repository.\n";
    }
}
