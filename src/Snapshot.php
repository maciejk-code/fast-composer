<?php
namespace FastComposer;

/**
 * The per-project snapshot: for every declared VCS repository, the packages Composer's own
 * VcsRepository produces from the local mirror. Each repository is exposed to the solver as a
 * local `composer` repository in its original position and with its original options.
 *
 * State shape: ['format', 'generated_at', 'repo_config_hash',
 *               'repos' => [url => ['url', 'managed', 'name', 'refs', 'checked_at', 'packages' => list]]]
 */
final class Snapshot
{
    public const FORMAT = 6;
    public const DEFAULT_TTL = 300;

    private string $root;
    private string $cacheDir;
    private string $workStem;
    private GitMirror $mirror;
    private ?ComposerPackages $composerPackages = null;

    public function __construct(string $root, ?GitMirror $mirror = null)
    {
        $resolved = realpath($root);
        $this->root = $resolved !== false ? $resolved : rtrim($root, DIRECTORY_SEPARATOR);
        $baseDir = self::cacheBaseDir();
        $this->cacheDir = $baseDir.'/projects/'.substr(hash('sha256', $this->root), 0, 24);
        $this->workStem = '.fast-composer-'.getmypid().'-'.bin2hex(random_bytes(4));
        $this->mirror = $mirror ?? new GitMirror($baseDir, $this->root);
    }

    public function mirror(): GitMirror
    {
        return $this->mirror;
    }

    /** @param callable(string):void $logger receives human-readable progress lines */
    public function setLogger(callable $logger): void
    {
        $this->mirror->setLogger($logger);
    }

    public function dir(): string
    {
        return $this->cacheDir;
    }

    public function workComposerPath(): string
    {
        return $this->root.'/'.$this->workStem.'.json';
    }

    public function workLockPath(): string
    {
        return $this->root.'/'.$this->workStem.'.lock';
    }

    public function cleanupWorkFiles(): void
    {
        @unlink($this->workComposerPath());
        @unlink($this->workLockPath());
    }

    public function load(): array
    {
        $snapshot = JsonFile::readIfExists($this->dir().'/snapshot.json');
        return ($snapshot['format'] ?? null) === self::FORMAT ? $snapshot : [];
    }

    public function save(array $snapshot): void
    {
        $this->ensureDir();
        $snapshot['format'] = self::FORMAT;
        JsonFile::atomicWrite(
            $this->dir().'/snapshot.json',
            json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
            true
        );
    }

    public function readLock(string $path = 'composer.lock'): array
    {
        $absolute = str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
        return JsonFile::readIfExists($absolute ? $path : $this->root.'/'.$path);
    }

    public function isCompatible(array $snapshot, array $rootConfig): bool
    {
        return ($snapshot['format'] ?? null) === self::FORMAT
            && ($snapshot['repo_config_hash'] ?? null) === RootConfig::repositoriesHash($rootConfig);
    }

    public function isFresh(array $snapshot, int $ttl): bool
    {
        $threshold = time() - max(0, $ttl);
        foreach ($snapshot['repos'] ?? [] as $repo) {
            if (($repo['managed'] ?? false) === true && ($repo['checked_at'] ?? 0) < $threshold) {
                return false;
            }
        }
        return true;
    }

    /** All versions in the snapshot, per package name (for status output and tests). */
    public static function versions(array $snapshot): array
    {
        $versions = [];
        foreach ($snapshot['repos'] ?? [] as $repo) {
            foreach ($repo['packages'] ?? [] as $package) {
                $versions[$package['name']][$package['version']] = $package;
            }
        }
        return $versions;
    }

    /** Rebuild the snapshot from scratch (`fast-composer refresh`). */
    public function buildFromLockAndCache(array $rootConfig): array
    {
        $snapshot = [];
        $this->sync($snapshot, $rootConfig, true);
        return $snapshot;
    }

    /**
     * Bring a snapshot in line with the root configuration without a regular Composer solve.
     *
     * Repositories no longer declared are dropped, repositories already synchronized are kept
     * as they are (the TTL and targeted refreshes handle them), and every other repository is
     * fetched into its mirror in parallel and indexed by Composer's VcsRepository.
     *
     * @return int number of repositories synchronized
     */
    public function sync(array &$snapshot, array $rootConfig, bool $refreshDefaultBranches = false): int
    {
        $snapshot['format'] = self::FORMAT;
        $snapshot['generated_at'] ??= time();
        $snapshot['repos'] ??= [];
        unset($snapshot['packages']);

        $declared = $this->declaredRepositories($rootConfig);
        $snapshot['repos'] = array_intersect_key($snapshot['repos'], $declared);

        $pending = [];
        foreach (array_keys($declared) as $url) {
            if (empty($snapshot['repos'][$url]['checked_at'])) {
                $pending[] = (string) $url;
            }
        }

        $errors = $this->mirror->sync($pending, $refreshDefaultBranches);
        foreach ($pending as $url) {
            if (isset($errors[$url])) {
                throw new \RuntimeException("Cannot synchronize VCS repository $url: ".$errors[$url]);
            }
            $this->hydrate($snapshot, $declared[$url]);
        }

        $snapshot['repo_config_hash'] = RootConfig::repositoriesHash($rootConfig);
        $this->save($snapshot);
        return count($pending);
    }

    public function refreshAllIfStale(array &$snapshot, array $rootConfig, int $ttl): int
    {
        if ($this->isFresh($snapshot, $ttl)) {
            return 0;
        }

        $declared = $this->declaredRepositories($rootConfig);
        $urls = array_map('strval', array_keys($declared));

        // One network round-trip per repository, overlapped across repositories.
        $errors = $this->mirror->sync($urls);
        foreach ($urls as $url) {
            if (isset($errors[$url])) {
                throw new \RuntimeException($errors[$url]);
            }
            $this->hydrate($snapshot, $declared[$url]);
        }

        $snapshot['repo_config_hash'] = RootConfig::repositoriesHash($rootConfig);
        $this->save($snapshot);
        return count($urls);
    }

    /** @param list<string> $patterns package names or wildcards */
    public function refreshPackages(array &$snapshot, array $patterns, array $rootConfig): int
    {
        $declared = $this->declaredRepositories($rootConfig);
        $matches = [];
        foreach ($snapshot['repos'] ?? [] as $url => $repo) {
            $name = $repo['name'] ?? null;
            if (!is_string($name) || !isset($declared[$url])) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if ($this->packagePatternMatches($pattern, $name)) {
                    $matches[] = (string) $url;
                    break;
                }
            }
        }

        $errors = $this->mirror->sync($matches);
        foreach ($matches as $url) {
            if (isset($errors[$url])) {
                throw new \RuntimeException($errors[$url]);
            }
            $this->hydrate($snapshot, $declared[$url]);
        }

        $this->save($snapshot);
        return count($matches);
    }

    /**
     * Revalidate explicit development branches, fetching all of them concurrently. Only those
     * branches are refreshed, so the repositories' TTL clock is left alone.
     *
     * @param list<array{0:string,1:string}> $requests [package, branch] pairs
     */
    public function ensureBranches(array &$snapshot, array $requests, array $rootConfig): void
    {
        if ($requests === []) {
            return;
        }
        $declared = $this->declaredRepositories($rootConfig);
        $fetches = [];
        foreach ($requests as $i => [$package, $branch]) {
            $url = $this->urlForPackage($snapshot, $package);
            if ($url === null || !isset($declared[$url])) {
                throw new \RuntimeException("No VCS repository mapping for $package. Run fast-composer refresh.");
            }
            $fetches[$i] = [$url, $branch, $package.':dev-'.$branch];
        }

        $errors = $this->mirror->fetchBranches($fetches);
        foreach ($requests as $i => [$package, $branch]) {
            $notFound = "Branch $branch not found for $package";
            if (isset($errors[$i])) {
                throw new \RuntimeException($notFound.($errors[$i] !== '' ? ': '.$errors[$i] : ''));
            }
            $this->mirror->branch($fetches[$i][0], $branch, $notFound);
        }
        foreach (array_unique(array_column($fetches, 0)) as $url) {
            $this->hydrate($snapshot, $declared[$url], false);
        }
        $this->save($snapshot);
    }

    /**
     * Write each repository's packages as a local `composer` repository and a root
     * composer.json that uses them in place of the VCS repositories, keeping order and options.
     */
    public function writeFastComposer(array $rootConfig, array $snapshot): string
    {
        $this->ensureDir();

        $repositories = [];
        foreach (($rootConfig['repositories'] ?? []) as $key => $repo) {
            $url = is_array($repo) && ($repo['type'] ?? null) === 'vcs' ? ($repo['url'] ?? null) : null;
            if (!is_string($url)) {
                $repositories[$key] = $repo;
                continue;
            }

            $grouped = [];
            foreach ($snapshot['repos'][$url]['packages'] ?? [] as $package) {
                $grouped[$package['name']][$package['version']] = $package;
            }
            $dir = $this->dir().'/repositories/'.substr(hash('sha256', GitUrl::normalize($url)), 0, 24);
            JsonFile::atomicWrite(
                $dir.'/packages.json',
                json_encode(['packages' => $grouped === [] ? new \stdClass() : $grouped], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
                true
            );
            $repositories[$key] = ['type' => 'composer', 'url' => $dir]
                + array_intersect_key($repo, array_flip(['only', 'exclude', 'canonical']));
        }

        $config = $rootConfig;
        $config['repositories'] = $repositories;

        $path = $this->workComposerPath();
        JsonFile::atomicWrite($path, ComposerJson::encode($config), true);
        return $path;
    }

    /** Every packages.json written by writeFastComposer(), for checks on the solver input. */
    public function repositoryFiles(): array
    {
        return glob($this->dir().'/repositories/*/packages.json') ?: [];
    }

    /** @return array<string,array> declared VCS repository entries by URL */
    private function declaredRepositories(array $rootConfig): array
    {
        $declared = [];
        foreach (RootConfig::vcsRepositories($rootConfig) as $repo) {
            $declared[$repo['url']] ??= $repo;
        }
        return $declared;
    }

    /** Rebuild a repository's packages from its mirror with Composer's VcsRepository. */
    private function hydrate(array &$snapshot, array $repoConfig, bool $checked = true): void
    {
        $url = $repoConfig['url'];
        $refs = $this->mirror->refs($url);
        $root = $this->mirror->defaultBranch($url);
        $files = $this->mirror->files($url, array_values(array_unique(array_merge(array_values($refs['heads']), array_values($refs['tags'])))));

        $this->composerPackages ??= new ComposerPackages($this->root);
        $packages = $this->composerPackages->forRepository($repoConfig, [
            'root' => $root,
            'branches' => $refs['heads'],
            'tags' => $refs['tags'],
            'files' => $files,
        ]);

        $name = null;
        foreach ($packages as $package) {
            if (($package['default-branch'] ?? false) === true) {
                $name = $package['name'];
                break;
            }
            $name ??= $package['name'];
        }

        $snapshot['repos'][$url] = [
            'url' => $url,
            'managed' => true,
            'name' => $name ?? ($snapshot['repos'][$url]['name'] ?? null),
            'refs' => $refs,
            'checked_at' => $checked ? time() : ($snapshot['repos'][$url]['checked_at'] ?? 0),
            'packages' => $packages,
        ];
    }

    private function packagePatternMatches(string $pattern, string $package): bool
    {
        $pattern = explode(':', $pattern, 2)[0];
        if ($pattern === $package) {
            return true;
        }
        return strpbrk($pattern, '*?[') !== false && fnmatch($pattern, $package);
    }

    private function urlForPackage(array $snapshot, string $name): ?string
    {
        foreach ($snapshot['repos'] ?? [] as $url => $repo) {
            if (($repo['name'] ?? null) === $name) {
                return (string) $url;
            }
        }
        return null;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir()) && !mkdir($this->dir(), 0700, true) && !is_dir($this->dir())) {
            throw new \RuntimeException('Cannot create Fast Composer cache directory '.$this->dir());
        }
        @chmod($this->dir(), 0700);
    }

    /**
     * Deliberately outside Composer's cache-dir: `composer clear-cache` must not throw away
     * the snapshot and mirrors.
     */
    private static function cacheBaseDir(): string
    {
        $override = getenv('FAST_COMPOSER_CACHE_DIR');
        if (is_string($override) && trim($override) !== '') {
            return rtrim($override, '/\\');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $local = getenv('LOCALAPPDATA');
            if (is_string($local) && trim($local) !== '') {
                return rtrim($local, '/\\').'/fast-composer';
            }
        }

        $home = getenv('HOME');
        if (!is_string($home) || trim($home) === '') {
            $home = sys_get_temp_dir();
        }
        $home = rtrim($home, '/\\');

        if (PHP_OS_FAMILY === 'Darwin') {
            return $home.'/Library/Caches/fast-composer';
        }

        $xdg = getenv('XDG_CACHE_HOME');
        if (is_string($xdg) && trim($xdg) !== '') {
            return rtrim($xdg, '/\\').'/fast-composer';
        }

        return $home.'/.cache/fast-composer';
    }
}
