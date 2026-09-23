<?php
namespace FastComposer;

final class Application
{
    public const VERSION = '0.1.0';

    private float $startedAt = 0.0;

    /** Progress line with the time elapsed since the command started. */
    private function log(string $message): void
    {
        fwrite(STDOUT, sprintf("[fast-composer %5.1fs] %s\n", microtime(true) - $this->startedAt, $message));
    }

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
            $rootCfg = JsonFile::read($root.'/composer.json');
        } catch (\Throwable $e) {
            fwrite(STDERR, '[fast-composer] '.$e->getMessage()."\n");
            return 2;
        }

        $snapshot = new Snapshot($root);
        $this->startedAt = microtime(true);
        $snapshot->setLogger(function (string $message): void {
            $this->log($message);
        });
        // HTTPS VCS URLs get the same credentials Composer would use (auth.json, COMPOSER_AUTH).
        $auth = null;
        $snapshot->mirror()->setCredentials(static function (string $url) use (&$auth, $root): array {
            if ($auth === null) {
                $auth = ComposerPackages::available() ? new ComposerAuth($root) : false;
            }
            return $auth === false ? [] : $auth->gitEnvironment($url);
        });
        // The in-process Composer solver may exit() directly; never leave work files behind.
        register_shutdown_function([$snapshot, 'cleanupWorkFiles']);
        $operationLock = null;

        try {
            $operationLock = $this->acquireOperationLock($snapshot);

            if ($cmd === 'status') {
                return $this->status($snapshot, $rootCfg);
            }

            if ($cmd === 'verify') {
                $bad = 0;
                $validator = new LockValidator($snapshot->mirror());
                foreach ($validator->verify($snapshot->readLock(), $rootCfg) as $name => $result) {
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
                $this->log("rebuilding full VCS snapshot");
                $this->log("this is the exhaustive path: it may read metadata for many refs; time depends on repository/ref count, Git/SSH latency and cache warmth");
                $state = $snapshot->buildFromLockAndCache($rootCfg);
                printf(
                    "snapshot repos=%d versions=%d\n",
                    count($state['repos'] ?? []),
                    array_sum(array_map('count', Snapshot::versions($state)))
                );
                return 0;
            }

            // Installation is deliberately never accelerated. Standard Composer remains the
            // authority for materializing a committed lock file.
            if ($cmd === 'install') {
                return $this->delegateComposer($args, $root);
            }

            if (!in_array($cmd, ['update', 'require'], true)) {
                fwrite(STDERR, "Unsupported command: $cmd\n");
                return 2;
            }

            // These modes intentionally have semantics beyond an optimistic lock update. Preserve
            // exact Composer behavior rather than partially emulating them.
            if (($cmd === 'require' && CommandLine::hasFlag($args, '--no-update'))
                || ($cmd === 'update' && (CommandLine::hasFlag($args, '--lock') || CommandLine::hasFlag($args, '--bump-after-update')))) {
                return $this->delegateComposer($args, $root);
            }

            $solveArgs = CommandLine::lockOnly($args);
            if (!ComposerPackages::available()) {
                // Package data comes from Composer's own VcsRepository; without Composer's
                // classes (non-phar installation) stay correct and run plain Composer.
                $this->log('WARNING: Composer classes are not loadable from the `composer` on PATH (needs the Composer phar); running regular Composer instead');
                return $this->delegateComposer($solveArgs, $root);
            }
            $state = $snapshot->load();
            if (!$state || !$snapshot->isCompatible($state, $rootCfg)) {
                // No regular Composer solve needed: fetch the repositories the snapshot does not
                // cover yet (all of them on a first run, only added ones after a change of
                // "repositories") in parallel, then continue on the fast path.
                $pending = count(RootConfig::vcsUrls($rootCfg)) - $this->syncedRepositoryCount($state, $rootCfg);
                $this->log(sprintf(
                    "%s: synchronizing %d VCS repositories (one parallel git fetch each)",
                    $state ? 'repositories changed' : 'no snapshot yet',
                    $pending
                ));
                $synced = $snapshot->sync($state, $rootCfg);
                $this->log(sprintf(
                    "snapshot ready (synchronized=%d, repos=%d, versions=%d)",
                    $synced,
                    count($state['repos'] ?? []),
                    array_sum(array_map('count', Snapshot::versions($state)))
                ));
            }

            $ttl = $this->ttl();
            if ($cmd === 'require') {
                $this->log("refreshing requested VCS repositories");
                $this->refreshForRequire($snapshot, $state, $args, $rootCfg);
            } else {
                $targets = CommandLine::packageArguments(array_slice($args, 1));
                if ($targets === []) {
                    $count = $snapshot->refreshAllIfStale($state, $rootCfg, $ttl);
                    if ($count > 0) {
                        $this->log(sprintf("refreshed %d VCS repositories (TTL %ds)", $count, $ttl));
                    } else {
                        // Explicit mutable refs are always revalidated, regardless of the broad TTL.
                        // A full refresh above has already re-read every branch tip.
                        $devCount = $this->refreshExplicitDevRequirements($snapshot, $state, $rootCfg);
                        if ($devCount > 0) {
                            $this->log(sprintf("revalidated %d explicit dev branch refs", $devCount));
                        }
                    }
                } else {
                    $count = $this->refreshTargetedUpdates($snapshot, $state, $rootCfg, $targets);
                    if ($count > 0) {
                        $this->log(sprintf("refreshed %d targeted VCS repositories", $count));
                    }
                }
            }

            return $this->runFastComposer($snapshot, $state, $rootCfg, $solveArgs, $root);
        } catch (\Throwable $e) {
            fwrite(STDERR, '[fast-composer] '.$e->getMessage()."\n");
            return 1;
        } finally {
            $snapshot->cleanupWorkFiles();
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

        $this->log("solving dependency graph with Composer from the cached snapshot (lock only; Composer's own output follows; its security audit queries packagist.org unless --no-audit)");
        $solveStart = microtime(true);
        $code = ComposerSolver::run($args, $root, $fastComposer, $rootCfg, $snapshot->repositoryFiles());
        if ($code !== 0) {
            return $code;
        }

        if (CommandLine::hasFlag($args, '--dry-run')) {
            $this->log("dry-run; real composer files unchanged");
            return 0;
        }

        if (!is_file($fastLock)) {
            throw new \RuntimeException('Composer did not produce a lock file');
        }

        $newComposer = null;
        if (($args[0] ?? null) === 'require') {
            // Composer edited the temporary copy; apply the same edits to the real file text.
            $temporaryRoot = JsonFile::read($fastComposer);
            $original = (string) file_get_contents($root.'/composer.json');
            $newComposer = ComposerJson::applyRequireChanges($original, $rootCfg, $temporaryRoot, $this->sortPackages($args, $rootCfg));
            $rootCfg = JsonFile::decode($newComposer);
        }

        $this->log(sprintf("Composer solve finished (%.1fs); validating changed VCS packages against their exact locked SHA", microtime(true) - $solveStart));
        $afterLock = $snapshot->readLock($fastLock);
        (new LockValidator($snapshot->mirror()))->validateChanged($beforeLock, $afterLock, $rootCfg);
        LockFile::fixContentHash($fastLock, $rootCfg);
        $this->log("validation complete; publishing composer files");

        $newLock = file_get_contents($fastLock);
        if ($newLock === false) {
            throw new \RuntimeException('Cannot read generated lock file');
        }

        $this->publishAtomically($root, $newComposer, $newLock);

        $this->warnAboutUnapprovedPlugins($rootCfg, $snapshot->readLock());
        $this->log("lock verified and published; done");
        return 0;
    }

    /**
     * Lock-only updates never install, so Composer never asks "Do you trust this plugin?". A
     * later non-interactive `composer install` (CI) would then refuse the plugin: say so now.
     */
    /** Whether Composer's require would sort packages: --sort-packages, or the setting in root/global config. */
    private function sortPackages(array $args, array $rootCfg): bool
    {
        if (CommandLine::hasFlag($args, '--sort-packages') || ($rootCfg['config']['sort-packages'] ?? false) === true) {
            return true;
        }
        $home = getenv('COMPOSER_HOME') ?: (getenv('HOME') ? getenv('HOME').'/.composer' : null);
        foreach ($home ? [$home.'/config.json', (getenv('XDG_CONFIG_HOME') ?: getenv('HOME').'/.config').'/composer/config.json'] : [] as $path) {
            try {
                if (is_file($path) && (JsonFile::read($path)['config']['sort-packages'] ?? false) === true) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }
        return false;
    }

    private function warnAboutUnapprovedPlugins(array $rootCfg, array $lock): void
    {
        $allowed = $rootCfg['config']['allow-plugins'] ?? [];
        if ($allowed === true) {
            return;
        }
        $allowed = is_array($allowed) ? $allowed : [];

        foreach (LockFile::packages($lock) as $package) {
            $name = $package['name'] ?? null;
            if (($package['type'] ?? null) !== 'composer-plugin' || !is_string($name)) {
                continue;
            }
            $decision = null;
            foreach ($allowed as $pattern => $value) {
                if (is_string($pattern) && fnmatch(strtolower($pattern), strtolower($name))) {
                    $decision = $value;
                    break;
                }
            }
            if ($decision === null) {
                $this->log("WARNING: $name is a Composer plugin not listed in config.allow-plugins; a non-interactive `composer install` will refuse it. Decide with: composer config allow-plugins.$name true   (or false)");
            }
        }
    }

    private function refreshForRequire(Snapshot $snapshot, array &$state, array $args, array $rootCfg): void
    {
        $branches = [];
        $names = [];
        foreach (CommandLine::packageArguments(array_slice($args, 1)) as $spec) {
            [$name, $constraint] = CommandLine::splitPackageSpec($spec);
            if (!$this->isManagedPackage($state, $name)) {
                continue;
            }
            $branch = is_string($constraint) ? CommandLine::explicitDevBranch($constraint) : null;
            if ($branch !== null) {
                $branches[] = [$name, $branch];
            } else {
                $names[] = $name;
            }
        }

        // All requested repositories are fetched in one parallel batch.
        $snapshot->ensureBranches($state, $branches, $rootCfg);
        if ($names !== []) {
            $snapshot->refreshPackages($state, $names, $rootCfg);
        }
    }

    /** @param list<string> $targets */
    private function refreshTargetedUpdates(Snapshot $snapshot, array &$state, array $rootCfg, array $targets): int
    {
        $branches = [];
        $patterns = [];
        foreach ($targets as $spec) {
            [$name, $temporaryConstraint] = CommandLine::splitPackageSpec($spec);
            if ($this->isManagedPackage($state, $name)) {
                $constraint = $temporaryConstraint ?? $this->rootConstraintForPackage($rootCfg, $name);
                $branch = is_string($constraint) ? CommandLine::explicitDevBranch($constraint) : null;
                if ($branch !== null) {
                    $branches[] = [$name, $branch];
                    continue;
                }
            }

            // Preserve wildcard and non-dev targeted update behavior.
            $patterns[] = $name;
        }

        // All targeted repositories are fetched in one parallel batch.
        $snapshot->ensureBranches($state, $branches, $rootCfg);
        $count = count($branches);
        if ($patterns !== []) {
            $count += $snapshot->refreshPackages($state, $patterns, $rootCfg);
        }
        return $count;
    }

    private function rootConstraintForPackage(array $rootCfg, string $package): ?string
    {
        foreach (['require', 'require-dev'] as $section) {
            $constraint = $rootCfg[$section][$package] ?? null;
            if (is_string($constraint)) {
                return $constraint;
            }
        }
        return null;
    }

    private function refreshExplicitDevRequirements(Snapshot $snapshot, array &$state, array $rootCfg): int
    {
        $requests = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach (($rootCfg[$section] ?? []) as $name => $constraint) {
                if (!is_string($name) || !is_string($constraint) || !$this->isManagedPackage($state, $name)) {
                    continue;
                }
                $branch = CommandLine::explicitDevBranch($constraint);
                if ($branch === null) {
                    continue;
                }
                $requests[] = [$name, $branch];
            }
        }
        $snapshot->ensureBranches($state, $requests, $rootCfg);
        return count($requests);
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

    private function syncedRepositoryCount(array $state, array $rootCfg): int
    {
        $count = 0;
        foreach (RootConfig::vcsUrls($rootCfg) as $url) {
            if (!empty($state['repos'][$url]['checked_at'])) {
                $count++;
            }
        }
        return $count;
    }

    private function status(Snapshot $snapshot, array $rootCfg): int
    {
        $state = $snapshot->load();
        if (!$state) {
            echo "snapshot: missing\n";
            printf("cache: %s\n", $snapshot->dir());
            return 1;
        }

        $ttl = $this->ttl();
        $repos = count($state['repos'] ?? []);
        $versions = array_sum(array_map('count', Snapshot::versions($state)));
        printf("version: %s\n", self::VERSION);
        printf("snapshot-format: %d\n", $state['format'] ?? 0);
        printf("repositories: %d\n", $repos);
        printf("versions: %d\n", $versions);
        printf("config-compatible: %s\n", $snapshot->isCompatible($state, $rootCfg) ? 'yes' : 'no');
        printf("refs-fresh: %s (ttl=%ds)\n", $snapshot->isFresh($state, $ttl) ? 'yes' : 'no', $ttl);
        printf("cache: %s\n", $snapshot->dir());

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

    private function acquireOperationLock(Snapshot $snapshot)
    {
        if (!is_dir($snapshot->dir()) && !mkdir($snapshot->dir(), 0700, true) && !is_dir($snapshot->dir())) {
            throw new \RuntimeException('Cannot create Fast Composer cache directory '.$snapshot->dir());
        }
        @chmod($snapshot->dir(), 0700);
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
                JsonFile::atomicWrite($composerPath, $composerContents);
            }
            JsonFile::atomicWrite($lockPath, $lockContents);
        } catch (\Throwable $e) {
            JsonFile::atomicWrite($composerPath, $oldComposer);
            if ($hadLock && is_string($oldLock)) {
                JsonFile::atomicWrite($lockPath, $oldLock);
            } elseif (!$hadLock) {
                @unlink($lockPath);
            }
            throw $e;
        }
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
        echo "Fast update/require operations always imply --no-install.\n";
        echo "The first run fetches every VCS repository in parallel into shared local mirrors; no regular Composer solve.\n";
        echo "FAST_COMPOSER_TTL controls broad full-update ref validation (default: 300 seconds).\n";
        echo "FAST_COMPOSER_CACHE_DIR overrides the Fast Composer cache base directory.\n";
        echo "FAST_COMPOSER_JOBS limits concurrent Git network operations (default: 8).\n";
        echo "FAST_COMPOSER_IN_PROCESS=0 runs the Composer solver as a subprocess instead of in-process.\n";
        echo "Targeted update/require and explicit root dev-* constraints always bypass the TTL.\n";
    }
}
