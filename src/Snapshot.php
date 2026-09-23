<?php
namespace FastComposer;

/**
 * The per-project snapshot: every version of every managed VCS package, exposed to Composer
 * as a local `composer` repository instead of the VCS repositories themselves.
 *
 * State shape: ['format', 'generated_at', 'repo_config_hash',
 *               'repos' => [url => ['url', 'managed', 'name', 'refs', 'checked_at']],
 *               'packages' => [name => [version => package]]]
 */
final class Snapshot
{
    public const FORMAT = 5;
    public const DEFAULT_TTL = 300;

    /** Package fields kept from composer.json / the lock (what Composer's solver needs). */
    private const KEEP = [
        'name', 'description', 'type', 'keywords', 'homepage', 'license', 'authors', 'support', 'funding',
        'require', 'require-dev', 'conflict', 'replace', 'provide', 'suggest', 'autoload', 'include-path',
        'target-dir', 'bin', 'extra', 'time',
    ];

    private string $root;
    private string $cacheDir;
    private string $workStem;
    private GitMirror $mirror;

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
            if (($repo['managed'] ?? false) === true && !empty($repo['name']) && ($repo['checked_at'] ?? 0) < $threshold) {
                return false;
            }
        }
        return true;
    }

    /** Rebuild the snapshot from scratch (`fast-composer refresh`). */
    public function buildFromLockAndCache(array $rootConfig): array
    {
        $snapshot = [];
        $this->sync($snapshot, $rootConfig);
        return $snapshot;
    }

    /**
     * Bring a snapshot in line with the root configuration without a regular Composer solve.
     *
     * Repositories no longer declared are dropped, repositories already synchronized are kept
     * as they are (the TTL and targeted refreshes handle them), and every other repository is
     * fetched into its mirror in parallel and fully indexed from it. After this the snapshot
     * holds every version Composer's VCS repositories would offer.
     *
     * @return int number of repositories synchronized
     */
    public function sync(array &$snapshot, array $rootConfig): int
    {
        $this->initialize($snapshot);

        $declared = array_flip(RootConfig::vcsUrls($rootConfig));
        foreach ($snapshot['repos'] as $url => $repo) {
            if (isset($declared[$url])) {
                continue;
            }
            unset($snapshot['repos'][$url]);
            $name = $repo['name'] ?? null;
            if (is_string($name) && $this->urlForPackage($snapshot, $name) === null) {
                unset($snapshot['packages'][$name]);
            }
        }

        // Lock entries map package names to repositories without any network access.
        $this->mergeLockIntoSnapshot($snapshot, $rootConfig, $this->readLock(), false);

        $pending = [];
        foreach (array_keys($declared) as $url) {
            if (empty($snapshot['repos'][$url]['checked_at'])) {
                $pending[] = (string) $url;
            }
        }

        $errors = $this->mirror->sync($pending);
        $unnamed = [];
        foreach ($pending as $url) {
            if (isset($errors[$url])) {
                throw new \RuntimeException("Cannot synchronize VCS repository $url: ".$errors[$url]);
            }
            if (empty($snapshot['repos'][$url]['name'])) {
                $unnamed[] = $url;
            }
        }
        foreach ($unnamed === [] ? [] : $this->mirror->defaultBranchNames($unnamed) as $url => $name) {
            $snapshot['repos'][$url]['name'] = $name;
        }
        foreach ($pending as $url) {
            $this->hydrate($snapshot, $url, $snapshot['repos'][$url]['name']);
        }

        $snapshot['repo_config_hash'] = RootConfig::repositoriesHash($rootConfig);
        $this->save($snapshot);
        return count($pending);
    }

    public function mergeLockIntoSnapshot(array &$snapshot, array $rootConfig, array $lock, bool $save = true): void
    {
        $this->initialize($snapshot);
        $snapshot['repo_config_hash'] = RootConfig::repositoriesHash($rootConfig);

        foreach (RootConfig::vcsUrls($rootConfig) as $url) {
            $snapshot['repos'][$url] ??= ['url' => $url, 'managed' => true];
            $snapshot['repos'][$url]['managed'] = true;
        }

        foreach (LockFile::packages($lock) as $package) {
            $source = $package['source'] ?? [];
            if (($source['type'] ?? null) !== 'git' || empty($source['url']) || empty($package['name'])) {
                continue;
            }
            $managedUrl = RootConfig::managedUrlFor($source['url'], $rootConfig);
            if ($managedUrl === null) {
                continue;
            }

            $snapshot['repos'][$managedUrl] ??= ['url' => $managedUrl, 'managed' => true];
            $snapshot['repos'][$managedUrl]['name'] = $package['name'];
            $snapshot['packages'][$package['name']][$package['version']] = $this->cleanPackage($package);
        }

        if ($save) {
            $this->save($snapshot);
        }
    }

    public function refreshAllIfStale(array &$snapshot, array $rootConfig, int $ttl): int
    {
        if ($this->isFresh($snapshot, $ttl)) {
            return 0;
        }

        $urls = [];
        foreach ($snapshot['repos'] ?? [] as $url => $repo) {
            if (($repo['managed'] ?? false) === true) {
                $urls[] = (string) $url;
            }
        }

        // One network round-trip per repository, overlapped across repositories.
        $errors = $this->mirror->sync($urls);
        foreach ($urls as $url) {
            if (isset($errors[$url])) {
                throw new \RuntimeException($errors[$url]);
            }
            $name = $snapshot['repos'][$url]['name'] ?? null;
            if (!is_string($name) || $name === '') {
                $name = $this->mirror->defaultBranchNames([$url])[$url];
            }
            $this->hydrate($snapshot, $url, $name);
        }

        $snapshot['repo_config_hash'] = RootConfig::repositoriesHash($rootConfig);
        $this->save($snapshot);
        return count($urls);
    }

    /** @param list<string> $patterns package names or wildcards */
    public function refreshPackages(array &$snapshot, array $patterns): int
    {
        $matches = [];
        foreach ($snapshot['repos'] ?? [] as $url => $repo) {
            $name = $repo['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            foreach ($patterns as $pattern) {
                if ($this->packagePatternMatches($pattern, $name)) {
                    $matches[(string) $url] = $name;
                    break;
                }
            }
        }

        $errors = $this->mirror->sync(array_keys($matches));
        foreach ($matches as $url => $name) {
            if (isset($errors[$url])) {
                throw new \RuntimeException($errors[$url]);
            }
            $this->hydrate($snapshot, $url, $name);
        }

        $this->save($snapshot);
        return count($matches);
    }

    public function ensureBranch(array &$snapshot, string $package, string $branch): array
    {
        return $this->ensureBranches($snapshot, [[$package, $branch]])[0];
    }

    /**
     * Revalidate explicit development branches, fetching all of them concurrently.
     *
     * @param list<array{0:string,1:string}> $requests [package, branch] pairs
     * @return list<array> the resulting snapshot package per request
     */
    public function ensureBranches(array &$snapshot, array $requests): array
    {
        $fetches = [];
        foreach ($requests as $i => [$package, $branch]) {
            $url = $this->urlForPackage($snapshot, $package);
            if (!$url) {
                throw new \RuntimeException("No VCS repository mapping for $package. Run a normal Composer update once, then fast-composer refresh.");
            }
            $fetches[$i] = [$url, $branch, $package.':dev-'.$branch];
        }
        $errors = $this->mirror->fetchBranches($fetches);

        $packages = [];
        foreach ($requests as $i => [$package, $branch]) {
            $notFound = "Branch $branch not found for $package";
            if (isset($errors[$i])) {
                throw new \RuntimeException($notFound.($errors[$i] !== '' ? ': '.$errors[$i] : ''));
            }
            $url = $fetches[$i][0];
            [$sha, $meta] = $this->mirror->branch($url, $branch, $notFound);

            $version = $this->branchVersion($branch);
            $existing = $snapshot['packages'][$package][$version] ?? null;
            if (is_array($existing) && ($existing['source']['reference'] ?? null) === $sha) {
                $pkg = $existing;
            } else {
                // Like Composer, the repository's package name wins over the one on this branch.
                $pkg = $this->packageFromMetadata($meta, $package, $version, $url, $sha);
                $snapshot['packages'][$package][$version] = $pkg;
            }

            $snapshot['repos'][$url]['name'] = $package;
            $snapshot['repos'][$url]['refs']['heads'][$branch] = $sha;
            $snapshot['repos'][$url]['checked_at'] = time();
            unset($snapshot['repos'][$url]['last_error']);
            $packages[$i] = $pkg;
        }

        if ($requests !== []) {
            $this->save($snapshot);
        }
        return $packages;
    }

    /** Write the snapshot as a `composer` repository and a root composer.json that uses it. */
    public function writeFastComposer(array $rootConfig, array $snapshot): string
    {
        $this->ensureDir();

        $grouped = [];
        foreach ($snapshot['packages'] ?? [] as $versions) {
            foreach ($versions as $package) {
                // A single version Composer cannot parse makes it reject the whole repository.
                if (isset($package['name'], $package['version']) && ComposerVersion::normalize((string) $package['version']) !== null) {
                    $grouped[$package['name']][$package['version']] = $package;
                }
            }
        }
        JsonFile::atomicWrite(
            $this->dir().'/packages.json',
            json_encode(['packages' => $grouped], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
            true
        );

        $config = $rootConfig;
        $config['repositories'] = array_merge([['type' => 'composer', 'url' => $this->dir()]], RootConfig::nonVcsRepositories($rootConfig));

        $path = $this->workComposerPath();
        JsonFile::atomicWrite($path, ComposerJson::encode($config), true);
        return $path;
    }

    private function initialize(array &$snapshot): void
    {
        $snapshot['format'] = self::FORMAT;
        $snapshot['generated_at'] ??= time();
        $snapshot['repos'] ??= [];
        $snapshot['packages'] ??= [];
    }

    /** Rebuild a repository's versions from its mirror after the mirror was synchronized. */
    private function hydrate(array &$snapshot, string $url, string $package): void
    {
        $refs = $this->mirror->refs($url);
        $existing = $snapshot['packages'][$package] ?? [];
        $next = [];
        $pending = [];

        foreach ($this->versions($refs) as $version => $ref) {
            $current = $existing[$version] ?? null;
            if (is_array($current) && ($current['source']['reference'] ?? null) === $ref['sha']) {
                $next[$version] = $current;
            } else {
                $pending[$version] = $ref;
            }
        }

        $metadata = $pending === [] ? [] : $this->mirror->metadata($url, array_column($pending, 'sha'));
        foreach ($pending as $version => $ref) {
            $meta = $metadata[$ref['sha']] ?? null;
            if ($meta === null) {
                // Like Composer, refs without a readable composer.json simply provide no version.
                continue;
            }
            if ($ref['normalized'] !== null && isset($meta['version'])) {
                // A tag whose composer.json declares a "version": Composer uses that version, and
                // skips the tag when it does not match the tag name.
                $declared = ComposerVersion::normalize((string) $meta['version']);
                if ($declared === null || preg_replace('{(^dev-|[.-]?dev$)}i', '', $declared) !== $ref['normalized']) {
                    continue;
                }
                $version = (string) preg_replace('{[.-]?dev$}i', '', (string) $meta['version']);
            }
            // Composer names every version of a VCS repository after the composer.json on its
            // default branch (VcsRepository::preProcess), so an old tag or branch with a
            // different "name" (renamed package, fork, typo) is still this package.
            $next[$version] = $this->packageFromMetadata($meta, $package, $version, $url, $ref['sha']);
        }

        if ($next === [] && $existing !== []) {
            $next = $existing;
        }

        $snapshot['packages'][$package] = $next;
        $snapshot['repos'][$url]['name'] = $package;
        $snapshot['repos'][$url]['refs'] = $refs;
        $snapshot['repos'][$url]['checked_at'] = time();
        unset($snapshot['repos'][$url]['last_error']);
    }

    /**
     * Composer version string for every tag and branch Composer would offer, with its commit.
     * Tags come first and the first tag wins when two resolve to the same version, as in
     * Composer's VcsRepository.
     *
     * @param array{heads:array<string,string>,tags:array<string,string>} $refs
     * @return array<string,array{sha:string,normalized:?string}> normalized is set for tags
     */
    private function versions(array $refs): array
    {
        $versions = [];
        $seen = [];
        foreach ($refs['tags'] as $tag => $sha) {
            $parsed = ComposerVersion::fromTag((string) $tag);
            if ($parsed === null || isset($seen[$parsed[1]])) {
                continue;
            }
            $seen[$parsed[1]] = true;
            $versions[$parsed[0]] = ['sha' => $sha, 'normalized' => $parsed[1]];
        }
        foreach ($refs['heads'] as $branch => $sha) {
            $version = ComposerVersion::fromBranch((string) $branch);
            if ($version !== null) {
                $versions[$version] = ['sha' => $sha, 'normalized' => null];
            }
        }
        return $versions;
    }

    private function branchVersion(string $branch): string
    {
        return ComposerVersion::fromBranch($branch) ?? 'dev-'.$branch;
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
                return $url;
            }
        }
        return null;
    }

    private function packageFromMetadata(array $meta, string $name, string $version, string $url, string $sha): array
    {
        $package = $this->cleanPackage($meta);
        $package['name'] = $name;
        $package['version'] = $version;
        $package['source'] = ['type' => 'git', 'url' => $url, 'reference' => $sha];
        unset($package['dist']);
        return $package;
    }

    private function cleanPackage(array $package): array
    {
        $result = [];
        foreach (array_merge(self::KEEP, ['name', 'version', 'source', 'dist']) as $key) {
            if (array_key_exists($key, $package)) {
                $result[$key] = $package[$key];
            }
        }
        return $result;
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
