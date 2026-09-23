<?php
namespace FastComposer;

final class Snapshot
{
    public const FORMAT = 3;
    public const DEFAULT_TTL = 300;

    private const KEEP = [
        'name','description','type','keywords','homepage','license','authors','support','funding',
        'require','require-dev','conflict','replace','provide','suggest','autoload','include-path',
        'target-dir','bin','extra',
    ];

    private const VERIFY = [
        'type','require','require-dev','conflict','replace','provide','suggest','autoload',
        'include-path','target-dir','bin','extra',
    ];

    private string $root;
    private string $cacheDir;
    private string $workStem;
    /** @var array<string,array> Exact-SHA source metadata fetched during this process only. */
    private array $operationMetadata = [];

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        $this->root = $resolved !== false ? $resolved : rtrim($root, DIRECTORY_SEPARATOR);
        $this->cacheDir = $this->cacheBaseDir().'/projects/'.substr(hash('sha256', $this->root), 0, 24);
        $this->workStem = '.fast-composer-'.getmypid().'-'.bin2hex(random_bytes(4));
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
        $path = $this->dir().'/snapshot.json';
        if (!is_file($path)) {
            return [];
        }

        $snapshot = $this->readJson($path, []);
        return ($snapshot['format'] ?? null) === self::FORMAT ? $snapshot : [];
    }

    public function save(array $snapshot): void
    {
        $this->ensureDir();
        $snapshot['format'] = self::FORMAT;
        $this->atomicWrite(
            $this->dir().'/snapshot.json',
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"
        );
    }

    public function readLock(string $path = 'composer.lock'): array
    {
        if (!$this->isAbsolutePath($path)) {
            $path = $this->root.'/'.$path;
        }
        return $this->readJson($path, []);
    }

    public function isCompatible(array $snapshot, array $rootConfig): bool
    {
        return ($snapshot['format'] ?? null) === self::FORMAT
            && ($snapshot['repo_config_hash'] ?? null) === $this->repoConfigHash($rootConfig);
    }

    public function isFresh(array $snapshot, int $ttl): bool
    {
        $threshold = time() - max(0, $ttl);

        foreach ($snapshot['repos'] ?? [] as $repo) {
            if (($repo['managed'] ?? false) !== true || empty($repo['name'])) {
                continue;
            }
            if (($repo['checked_at'] ?? 0) < $threshold) {
                return false;
            }
        }

        return true;
    }

    public function buildFromLockAndCache(array $rootConfig): array
    {
        $snapshot = [
            'format' => self::FORMAT,
            'generated_at' => time(),
            'repo_config_hash' => $this->repoConfigHash($rootConfig),
            'repos' => [],
            'packages' => [],
        ];
        $lock = $this->readLock();

        foreach ($this->managedRepositories($rootConfig) as $url) {
            $snapshot['repos'][$url] = ['url' => $url, 'managed' => true];
        }

        $this->mergeLockIntoSnapshot($snapshot, $rootConfig, $lock, false);

        foreach (array_keys($snapshot['repos']) as $url) {
            try {
                if (empty($snapshot['repos'][$url]['name'])) {
                    $this->discoverRepositoryName($snapshot, $url);
                }
                if (!empty($snapshot['repos'][$url]['name'])) {
                    $this->hydrateRepository($snapshot, $url, $snapshot['repos'][$url]['name']);
                }
            } catch (\Throwable $e) {
                $snapshot['repos'][$url]['last_error'] = $e->getMessage();
            }
        }

        $this->save($snapshot);
        return $snapshot;
    }

    public function mergeLockIntoSnapshot(array &$snapshot, array $rootConfig, array $lock, bool $save = true): void
    {
        $snapshot['format'] = self::FORMAT;
        $snapshot['repo_config_hash'] = $this->repoConfigHash($rootConfig);
        $snapshot['generated_at'] ??= time();
        $snapshot['repos'] ??= [];
        $snapshot['packages'] ??= [];

        foreach ($this->managedRepositories($rootConfig) as $url) {
            $snapshot['repos'][$url] ??= ['url' => $url, 'managed' => true];
            $snapshot['repos'][$url]['managed'] = true;
        }

        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            $source = $package['source'] ?? [];
            if (($source['type'] ?? null) !== 'git' || empty($source['url']) || empty($package['name'])) {
                continue;
            }

            $managedUrl = $this->managedRepoUrl($source['url'], $rootConfig);
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

        $count = 0;
        foreach ($snapshot['repos'] ?? [] as $url => $repo) {
            if (($repo['managed'] ?? false) !== true) {
                continue;
            }
            $name = $repo['name'] ?? null;
            if (!is_string($name) || $name === '') {
                $this->discoverRepositoryName($snapshot, $url);
                $name = $snapshot['repos'][$url]['name'] ?? null;
            }
            if (!is_string($name) || $name === '') {
                throw new \RuntimeException("Cannot determine package name for VCS repository $url");
            }
            $this->hydrateRepository($snapshot, $url, $name);
            $count++;
        }

        $snapshot['repo_config_hash'] = $this->repoConfigHash($rootConfig);
        $this->save($snapshot);
        return $count;
    }

    /** @param list<string> $patterns */
    public function refreshPackages(array &$snapshot, array $patterns): int
    {
        $count = 0;

        foreach ($snapshot['repos'] ?? [] as $url => $repo) {
            $name = $repo['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            foreach ($patterns as $pattern) {
                if ($this->packagePatternMatches($pattern, $name)) {
                    $this->hydrateRepository($snapshot, $url, $name);
                    $count++;
                    break;
                }
            }
        }

        $this->save($snapshot);
        return $count;
    }

    public function ensureBranch(array &$snapshot, string $package, string $branch): array
    {
        $url = $this->urlForPackage($snapshot, $package);
        if (!$url) {
            throw new \RuntimeException("No VCS repository mapping for $package. Run a normal Composer update once, then fast-composer refresh.");
        }

        $ref = 'refs/heads/'.$branch;
        [$sha, $meta] = $this->composerAtRef($url, $ref, "Branch $branch not found for $package");
        $version = $this->branchVersion($branch);
        $existing = $snapshot['packages'][$package][$version] ?? null;
        if (is_array($existing) && ($existing['source']['reference'] ?? null) === $sha) {
            $pkg = $existing;
        } else {
            if (($meta['name'] ?? null) !== $package) {
                throw new \RuntimeException("Repository package name mismatch: expected $package");
            }
            $pkg = $this->packageFromMetadata($meta, $package, $version, $url, $sha);
            $snapshot['packages'][$package][$version] = $pkg;
        }

        $snapshot['repos'][$url]['name'] = $package;
        $snapshot['repos'][$url]['refs']['heads'][$branch] = $sha;
        $snapshot['repos'][$url]['checked_at'] = time();
        unset($snapshot['repos'][$url]['last_error']);
        $this->save($snapshot);

        return $pkg;
    }

    public function writeFastComposer(array $rootConfig, array $snapshot): string
    {
        $this->ensureDir();

        $packages = [];
        foreach ($snapshot['packages'] ?? [] as $versions) {
            foreach ($versions as $package) {
                $packages[] = $package;
            }
        }

        $this->atomicWrite(
            $this->dir().'/packages.json',
            json_encode(['packages' => $this->groupPackages($packages)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"
        );

        $config = $rootConfig;
        $other = [];
        foreach (($config['repositories'] ?? []) as $repo) {
            if (!(is_array($repo) && ($repo['type'] ?? null) === 'vcs')) {
                $other[] = $repo;
            }
        }
        $config['repositories'] = array_merge([['type' => 'composer', 'url' => $this->dir()]], $other);

        $path = $this->workComposerPath();
        $this->atomicWrite(
            $path,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"
        );
        return $path;
    }

    public function validateChangedPackages(array $beforeLock, array $afterLock, array $rootConfig): void
    {
        $before = $this->packagesByName($beforeLock);
        $after = $this->packagesByName($afterLock);

        foreach ($after as $name => $package) {
            $previous = $before[$name] ?? null;
            if ($previous !== null && $this->normalize($previous) === $this->normalize($package)) {
                continue;
            }

            $source = $package['source'] ?? [];
            if (($source['type'] ?? null) !== 'git' || empty($source['url']) || empty($source['reference'])) {
                continue;
            }
            if ($this->managedRepoUrl($source['url'], $rootConfig) === null) {
                continue;
            }

            $meta = $this->composerAt($source['url'], $source['reference']);
            if (!$this->metadataMatches($package, $meta)) {
                throw new \RuntimeException(
                    "Lock metadata mismatch for $name at {$source['reference']}; refusing to write composer.lock"
                );
            }
        }
    }

    public function fixContentHash(string $lockPath, array $rootConfig): void
    {
        $lock = $this->readJson($lockPath, []);
        if (!isset($lock['packages'])) {
            throw new \RuntimeException("Invalid lock file: $lockPath");
        }
        $lock['content-hash'] = $this->contentHash($rootConfig);
        $this->atomicWrite(
            $lockPath,
            json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"
        );
    }

    public function verifyLock(array $rootConfig): array
    {
        $lock = $this->readLock();
        $results = [];

        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            $source = $package['source'] ?? [];
            if (($source['type'] ?? null) !== 'git' || empty($source['url']) || empty($source['reference'])) {
                continue;
            }
            if ($this->managedRepoUrl($source['url'], $rootConfig) === null) {
                continue;
            }

            try {
                $meta = $this->composerAt($source['url'], $source['reference']);
                $results[$package['name']] = [
                    'sha' => $source['reference'],
                    'reachable' => true,
                    'metadata_match' => $this->metadataMatches($package, $meta),
                ];
            } catch (\Throwable) {
                $results[$package['name']] = [
                    'sha' => $source['reference'],
                    'reachable' => false,
                    'metadata_match' => false,
                ];
            }
        }

        return $results;
    }

    private function hydrateRepository(array &$snapshot, string $url, string $package): void
    {
        $remote = $this->remoteVersions($url);
        $existing = $snapshot['packages'][$package] ?? [];
        $next = [];
        $pending = [];
        $missingShas = [];

        foreach ($remote['versions'] as $version => $ref) {
            $current = $existing[$version] ?? null;
            if (is_array($current) && ($current['source']['reference'] ?? null) === $ref['sha']) {
                $next[$version] = $current;
                continue;
            }

            $cached = $this->composerCacheMetadata($url, $ref['sha']);
            if (is_array($cached)) {
                if (($cached['name'] ?? null) !== $package) {
                    throw new \RuntimeException("Repository package name mismatch for $url: expected $package");
                }
                $next[$version] = $this->packageFromMetadata($cached, $package, $version, $url, $ref['sha']);
                continue;
            }

            $key = $this->metadataKey($url, $ref['sha']);
            if (isset($this->operationMetadata[$key])) {
                $meta = $this->operationMetadata[$key];
                if (($meta['name'] ?? null) !== $package) {
                    throw new \RuntimeException("Repository package name mismatch for $url: expected $package");
                }
                $next[$version] = $this->packageFromMetadata($meta, $package, $version, $url, $ref['sha']);
                continue;
            }

            $pending[$version] = $ref;
            $missingShas[$ref['sha']] = true;
        }

        if ($missingShas !== []) {
            $this->composerManyAt($url, array_keys($missingShas));
            foreach ($pending as $version => $ref) {
                $meta = $this->operationMetadata[$this->metadataKey($url, $ref['sha'])] ?? null;
                if (!is_array($meta) || ($meta['name'] ?? null) !== $package) {
                    throw new \RuntimeException("Repository package name mismatch for $url: expected $package");
                }
                $next[$version] = $this->packageFromMetadata($meta, $package, $version, $url, $ref['sha']);
            }
        }

        if ($next === [] && $existing !== []) {
            $next = $existing;
        }

        $snapshot['packages'][$package] = $next;
        $snapshot['repos'][$url]['name'] = $package;
        $snapshot['repos'][$url]['refs'] = $remote['refs'];
        $snapshot['repos'][$url]['checked_at'] = time();
        unset($snapshot['repos'][$url]['last_error']);
    }

    /** @return array{versions:array<string,array{sha:string,kind:string,ref:string}>,refs:array{heads:array<string,string>,tags:array<string,string>}} */
    private function remoteVersions(string $url): array
    {
        [$code, $out, $err] = Process::run(['git', 'ls-remote', '--heads', '--tags', $url], $this->root);
        if ($code !== 0) {
            throw new \RuntimeException(trim($err !== '' ? $err : $out) ?: "Cannot read refs from $url");
        }

        $heads = [];
        $tags = [];
        $peeled = [];

        foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$sha, $ref] = $parts;

            if (str_starts_with($ref, 'refs/heads/')) {
                $heads[substr($ref, strlen('refs/heads/'))] = $sha;
                continue;
            }
            if (!str_starts_with($ref, 'refs/tags/')) {
                continue;
            }

            $tag = substr($ref, strlen('refs/tags/'));
            if (str_ends_with($tag, '^{}')) {
                $peeled[substr($tag, 0, -3)] = $sha;
            } else {
                $tags[$tag] = $sha;
            }
        }
        $tags = array_replace($tags, $peeled);

        $versions = [];
        foreach ($heads as $branch => $sha) {
            $versions[$this->branchVersion($branch)] = ['sha' => $sha, 'kind' => 'branch', 'ref' => $branch];
        }
        foreach ($tags as $tag => $sha) {
            $version = $this->tagVersion($tag);
            if ($version === null) {
                continue;
            }
            $versions[$version] = ['sha' => $sha, 'kind' => 'tag', 'ref' => $tag];
        }

        return ['versions' => $versions, 'refs' => ['heads' => $heads, 'tags' => $tags]];
    }

    private function discoverRepositoryName(array &$snapshot, string $url): void
    {
        [$code, $out, $err] = Process::run(['git', 'ls-remote', $url, 'HEAD'], $this->root);
        if ($code !== 0 || trim($out) === '') {
            throw new \RuntimeException(trim($err !== '' ? $err : $out) ?: "Cannot resolve HEAD for $url");
        }
        $sha = preg_split('/\s+/', trim($out))[0];
        $meta = $this->metadataAt($url, $sha);
        $name = $meta['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \RuntimeException("composer.json at $url HEAD has no package name");
        }
        $snapshot['repos'][$url]['name'] = $name;
    }

    private function metadataAt(string $url, string $sha): array
    {
        $key = $this->metadataKey($url, $sha);
        return $this->operationMetadata[$key]
            ?? $this->composerCacheMetadata($url, $sha)
            ?? $this->composerAt($url, $sha);
    }

    private function metadataKey(string $url, string $sha): string
    {
        return hash('sha256', $this->normalizeGitUrl($url)).':'.$sha;
    }

    private function composerCacheMetadata(string $url, string $sha): ?array
    {
        if (!preg_match('~(?:https?://|ssh://git@|git@)?github\.com[/:]([^/]+)/([^/]+?)(?:\.git)?/?$~i', $url, $m)) {
            return null;
        }

        $owner = $m[1];
        $repo = preg_replace('/\.git$/i', '', $m[2]);
        $cache = $this->composerCacheRepoDir();
        $paths = [
            "$cache/github.com/".strtolower($owner)."/$repo/$sha",
            "$cache/github.com/$owner/$repo/$sha",
        ];

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $meta = json_decode(file_get_contents($path), true);
            if (is_array($meta)) {
                return $meta;
            }
        }

        return null;
    }

    private function composerCacheRepoDir(): string
    {
        [$code, $out] = Process::run(['composer', 'config', 'cache-repo-dir', '--absolute'], $this->root);
        if ($code === 0 && trim($out) !== '') {
            return trim($out);
        }
        return $this->composerCacheBaseDir().'/repo';
    }

    private function composerAt(string $url, string $sha): array
    {
        $key = $this->metadataKey($url, $sha);
        if (isset($this->operationMetadata[$key])) {
            return $this->operationMetadata[$key];
        }

        $this->composerManyAt($url, [$sha]);
        $data = $this->operationMetadata[$key] ?? null;
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid composer.json at '.$sha);
        }
        return $data;
    }

    /** @return array{0:string,1:array} */
    private function composerAtRef(string $url, string $ref, string $notFoundMessage): array
    {
        $this->ensureDir();
        $tmp = $this->dir().'/fetch-ref-'.bin2hex(random_bytes(5));
        if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            throw new \RuntimeException("Cannot create temporary directory $tmp");
        }

        try {
            Process::must(['git', 'init', '-q'], $tmp);
            Process::must(['git', 'remote', 'add', 'origin', $url], $tmp);
            [$code, $out, $err] = Process::run(['git', 'fetch', '-q', '--depth=1', '--no-tags', 'origin', $ref], $tmp);
            if ($code !== 0) {
                throw new \RuntimeException($notFoundMessage.($err !== '' ? ': '.trim($err) : ''));
            }

            $sha = trim(Process::must(['git', 'rev-parse', 'FETCH_HEAD'], $tmp));
            if ($sha === '') {
                throw new \RuntimeException($notFoundMessage);
            }
            $json = Process::must(['git', 'show', $sha.':composer.json'], $tmp);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid composer.json at '.$sha);
            }
            $this->operationMetadata[$this->metadataKey($url, $sha)] = $data;
            return [$sha, $data];
        } finally {
            $this->rrmdir($tmp);
        }
    }

    /** @param list<string> $shas */
    private function composerManyAt(string $url, array $shas): void
    {
        $shas = array_values(array_unique(array_filter($shas, static fn ($sha): bool => is_string($sha) && $sha !== '')));
        if ($shas === []) {
            return;
        }

        $missing = [];
        foreach ($shas as $sha) {
            if (!isset($this->operationMetadata[$this->metadataKey($url, $sha)])) {
                $missing[] = $sha;
            }
        }
        if ($missing === []) {
            return;
        }

        $this->ensureDir();
        $tmp = $this->dir().'/fetch-'.bin2hex(random_bytes(5));
        if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            throw new \RuntimeException("Cannot create temporary directory $tmp");
        }

        try {
            Process::must(['git', 'init', '-q'], $tmp);
            Process::must(['git', 'remote', 'add', 'origin', $url], $tmp);

            // Keep command lines bounded while amortizing SSH/TLS setup across many ref tips.
            foreach (array_chunk($missing, 64) as $chunk) {
                Process::must(array_merge(['git', 'fetch', '-q', '--depth=1', '--no-tags', 'origin'], $chunk), $tmp);
            }

            foreach ($missing as $sha) {
                $json = Process::must(['git', 'show', $sha.':composer.json'], $tmp);
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    throw new \RuntimeException('Invalid composer.json at '.$sha);
                }
                $this->operationMetadata[$this->metadataKey($url, $sha)] = $data;
            }
        } finally {
            $this->rrmdir($tmp);
        }
    }

    private function metadataMatches(array $lockedPackage, array $sourceComposer): bool
    {
        if (($lockedPackage['name'] ?? null) !== ($sourceComposer['name'] ?? null)) {
            return false;
        }
        return $this->metadataForCompare($lockedPackage) === $this->metadataForCompare($sourceComposer);
    }

    private function metadataForCompare(array $package): array
    {
        $result = ['type' => $package['type'] ?? 'library'];
        foreach (self::VERIFY as $key) {
            if ($key === 'type') {
                continue;
            }
            if (array_key_exists($key, $package)) {
                $result[$key] = $package[$key];
            }
        }
        return $this->normalize($result);
    }

    private function packagesByName(array $lock): array
    {
        $result = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (isset($package['name'])) {
                $result[$package['name']] = $package;
            }
        }
        return $result;
    }

    private function contentHash(array $content): string
    {
        $relevantKeys = [
            'name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide',
            'minimum-stability', 'prefer-stable', 'repositories', 'extra',
        ];

        $relevant = [];
        foreach (array_intersect($relevantKeys, array_keys($content)) as $key) {
            $relevant[$key] = $content[$key];
        }
        if (isset($content['config']['platform'])) {
            $relevant['config']['platform'] = $content['config']['platform'];
        }
        ksort($relevant);

        return hash('md5', json_encode($relevant, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function managedRepositories(array $rootConfig): array
    {
        $urls = [];
        foreach (($rootConfig['repositories'] ?? []) as $repo) {
            if (is_array($repo) && ($repo['type'] ?? null) === 'vcs' && is_string($repo['url'] ?? null)) {
                $urls[] = $repo['url'];
            }
        }
        return $urls;
    }

    private function repoConfigHash(array $rootConfig): string
    {
        $repos = [];
        foreach (($rootConfig['repositories'] ?? []) as $repo) {
            if (!is_array($repo) || ($repo['type'] ?? null) !== 'vcs' || !is_string($repo['url'] ?? null)) {
                continue;
            }
            $copy = $repo;
            $copy['url'] = $this->normalizeGitUrl($repo['url']);
            $repos[] = $this->normalize($copy);
        }
        usort($repos, static fn (array $a, array $b): int => ($a['url'] ?? '') <=> ($b['url'] ?? ''));
        return hash('sha256', json_encode($repos, JSON_THROW_ON_ERROR));
    }

    private function managedRepoUrl(string $sourceUrl, array $rootConfig): ?string
    {
        $source = $this->normalizeGitUrl($sourceUrl);
        foreach (($rootConfig['repositories'] ?? []) as $repo) {
            if (!is_array($repo) || ($repo['type'] ?? null) !== 'vcs' || !is_string($repo['url'] ?? null)) {
                continue;
            }
            if ($this->normalizeGitUrl($repo['url']) === $source) {
                return $repo['url'];
            }
        }
        return null;
    }

    private function normalizeGitUrl(string $url): string
    {
        $url = trim($url);
        $local = realpath($url);
        if ($local !== false) {
            return rtrim($local, '/\\');
        }

        if (preg_match('~(?:https?://|ssh://git@|git@)?github\.com[/:]([^/]+)/([^/]+?)(?:\.git)?/?$~i', $url, $m)) {
            return 'github.com/'.strtolower($m[1]).'/'.strtolower(preg_replace('/\.git$/i', '', $m[2]));
        }

        return rtrim(preg_replace('/\.git$/i', '', $url), '/\\');
    }

    private function branchVersion(string $branch): string
    {
        $numeric = preg_replace('/^v(?=\d)/i', '', $branch);
        if (preg_match('/^\d+(?:\.\d+)*\.x$/i', $numeric)) {
            return $numeric.'-dev';
        }
        if (preg_match('/^\d+(?:\.\d+)*$/', $numeric)) {
            return $numeric.'.x-dev';
        }
        return 'dev-'.$branch;
    }

    private function tagVersion(string $tag): ?string
    {
        $version = preg_replace('/^v(?=\d)/i', '', $tag);
        return preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z][0-9A-Za-z.-]*)?$/', $version)
            ? $version
            : null;
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
        foreach (self::KEEP as $key) {
            if (array_key_exists($key, $package)) {
                $result[$key] = $package[$key];
            }
        }
        foreach (['name', 'version', 'source', 'dist'] as $key) {
            if (array_key_exists($key, $package)) {
                $result[$key] = $package[$key];
            }
        }
        return $result;
    }

    private function groupPackages(array $packages): array
    {
        $grouped = [];
        foreach ($packages as $package) {
            if (isset($package['name'], $package['version'])) {
                $grouped[$package['name']][$package['version']] = $package;
            }
        }
        return $grouped;
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->normalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }
        return $value;
    }

    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read JSON: $path");
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON: $path");
        }
        return $data;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir()) && !mkdir($this->dir(), 0700, true) && !is_dir($this->dir())) {
            throw new \RuntimeException('Cannot create Fast Composer cache directory '.$this->dir());
        }
        @chmod($this->dir(), 0700);
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory $dir");
        }
        $tmp = $path.'.tmp-'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write $tmp");
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot replace $path");
        }
    }

    private function cacheBaseDir(): string
    {
        $override = getenv('FAST_COMPOSER_CACHE_DIR');
        if (is_string($override) && trim($override) !== '') {
            return rtrim($override, '/\\');
        }
        return $this->composerCacheBaseDir().'/fast-composer';
    }

    private function composerCacheBaseDir(): string
    {
        $cache = getenv('COMPOSER_CACHE_DIR');
        if (is_string($cache) && trim($cache) !== '') {
            return rtrim($cache, '/\\');
        }

        $composerHome = getenv('COMPOSER_HOME');
        if (is_string($composerHome) && trim($composerHome) !== '') {
            return rtrim($composerHome, '/\\').'/cache';
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $local = getenv('LOCALAPPDATA');
            if (is_string($local) && trim($local) !== '') {
                return rtrim($local, '/\\').'/Composer';
            }
        }

        $home = getenv('HOME');
        if (!is_string($home) || trim($home) === '') {
            $home = sys_get_temp_dir();
        }
        $home = rtrim($home, '/\\');

        if (PHP_OS_FAMILY === 'Darwin') {
            return $home.'/Library/Caches/composer';
        }

        $xdg = getenv('XDG_CACHE_HOME');
        if (is_string($xdg) && trim($xdg) !== '') {
            return rtrim($xdg, '/\\').'/composer';
        }

        return $home.'/.cache/composer';
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = "$dir/$file";
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
