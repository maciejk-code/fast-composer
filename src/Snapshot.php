<?php
namespace FastComposer;

final class Snapshot
{
    public const FORMAT = 4;
    public const DEFAULT_TTL = 300;
    private const MIRROR_MARKER = 'fast-composer-synced';
    private const MIRROR_HEAD = 'refs/fast-composer/HEAD';

    private const KEEP = [
        'name','description','type','keywords','homepage','license','authors','support','funding',
        'require','require-dev','conflict','replace','provide','suggest','autoload','include-path',
        'target-dir','bin','extra','time',
    ];

    private const VERIFY = [
        'type','require','require-dev','conflict','replace','provide','suggest','autoload',
        'include-path','target-dir','bin','extra',
    ];

    private string $root;
    private string $baseDir;
    private string $cacheDir;
    private string $workStem;
    /** @var array<string,?array> Exact-SHA source metadata read during this process only (null: no composer.json). */
    private array $operationMetadata = [];
    /** @var array<string,true> URL+SHA pairs obtained from the remote during this process. */
    private array $reachable = [];
    /** @var array<string,true> Repositories whose tips were fetched during this process. */
    private array $fetchedTips = [];
    /** @var null|callable(string):void */
    private $logger = null;

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        $this->root = $resolved !== false ? $resolved : rtrim($root, DIRECTORY_SEPARATOR);
        $this->baseDir = $this->cacheBaseDir();
        $this->cacheDir = $this->baseDir.'/projects/'.substr(hash('sha256', $this->root), 0, 24);
        $this->workStem = '.fast-composer-'.getmypid().'-'.bin2hex(random_bytes(4));
    }

    /** @param callable(string):void $logger receives human-readable progress lines */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }

    /**
     * Run Git commands concurrently and report per-repository progress plus a heartbeat naming
     * what is still running, so a slow or stuck remote is visible instead of silent.
     *
     * @param array<array-key,array{0:list<string>,1:?string}> $commands
     * @param callable(array-key):string $labelOf
     */
    private function runGit(array $commands, string $what, callable $labelOf): array
    {
        if ($commands === []) {
            return [];
        }
        $total = count($commands);
        $this->log(sprintf('%s: %d (up to %d in parallel)', $what, $total, min($total, Process::defaultJobs())));

        return Process::runMany($commands, null, function (string $event, $subject, ?array $result, float $seconds, int $done, int $total) use ($labelOf): void {
            if ($event === 'done') {
                [$code, , $err] = $result;
                $status = $code === 0 ? 'ok' : 'FAILED: '.strtok(trim($err) ?: 'exit '.$code, "\n");
                $this->log(sprintf('  [%d/%d] %s %s (%.1fs)', $done, $total, $labelOf($subject), $status, $seconds));
                return;
            }
            $labels = array_map($labelOf, $subject);
            $shown = implode(', ', array_slice($labels, 0, 3)).(count($labels) > 3 ? sprintf(' (+%d more)', count($labels) - 3) : '');
            $this->log(sprintf('  ... %d/%d done after %.0fs, still waiting on: %s', $done, $total, $seconds, $shown));
            if ($seconds >= 15) {
                $this->log('      no progress for a long time usually means Git/SSH is waiting for an unreachable host, a VPN, or an SSH passphrase/host-key confirmation (run `ssh -T <host>` once, or load the key into ssh-agent)');
            }
        });
    }

    /** Add a hint to Git errors caused by missing credentials. */
    private function gitError(string $err, string $fallback): string
    {
        $message = trim($err) ?: $fallback;
        if (preg_match('/terminal prompts disabled|could not read (Username|Password)|Permission denied \(publickey|Host key verification failed/i', $message)) {
            $message .= "\nHint: Fast Composer runs Git non-interactively. Make sure `git ls-remote <url>` works without prompting (SSH key in ssh-agent, known host accepted, or an HTTPS credential helper).";
        }
        return $message;
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
            json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"
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
        $snapshot['format'] = self::FORMAT;
        $snapshot['generated_at'] ??= time();
        $snapshot['repos'] ??= [];
        $snapshot['packages'] ??= [];

        $declared = array_flip($this->managedRepositories($rootConfig));
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

        $errors = $this->fetchMirrors($pending);
        $unnamed = [];
        foreach ($pending as $url) {
            if (isset($errors[$url])) {
                throw new \RuntimeException("Cannot synchronize VCS repository $url: ".$errors[$url]);
            }
            if (empty($snapshot['repos'][$url]['name'])) {
                $unnamed[] = $url;
            }
        }
        if ($unnamed !== []) {
            $this->discoverRepositoryNames($snapshot, $unnamed);
        }
        foreach ($pending as $url) {
            $this->hydrateFromMirror($snapshot, $url, $snapshot['repos'][$url]['name']);
        }

        $snapshot['repo_config_hash'] = $this->repoConfigHash($rootConfig);
        $this->save($snapshot);
        return count($pending);
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

        $urls = [];
        foreach ($snapshot['repos'] ?? [] as $url => $repo) {
            if (($repo['managed'] ?? false) === true) {
                $urls[] = (string) $url;
            }
        }

        // One network round-trip per repository, overlapped across repositories.
        $errors = $this->fetchMirrors($urls);

        $count = 0;
        foreach ($urls as $url) {
            if (isset($errors[$url])) {
                throw new \RuntimeException($errors[$url]);
            }
            $name = $snapshot['repos'][$url]['name'] ?? null;
            if (!is_string($name) || $name === '') {
                $this->discoverRepositoryName($snapshot, $url);
                $name = $snapshot['repos'][$url]['name'] ?? null;
            }
            if (!is_string($name) || $name === '') {
                throw new \RuntimeException("Cannot determine package name for VCS repository $url");
            }
            $this->hydrateFromMirror($snapshot, $url, $name);
            $count++;
        }

        $snapshot['repo_config_hash'] = $this->repoConfigHash($rootConfig);
        $this->save($snapshot);
        return $count;
    }

    /** @param list<string> $patterns */
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

        $errors = $this->fetchMirrors(array_keys($matches));
        foreach ($matches as $url => $name) {
            if (isset($errors[$url])) {
                throw new \RuntimeException($errors[$url]);
            }
            $this->hydrateFromMirror($snapshot, $url, $name);
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
        $commands = [];
        $urls = [];
        foreach ($requests as $i => [$package, $branch]) {
            $url = $this->urlForPackage($snapshot, $package);
            if (!$url) {
                throw new \RuntimeException("No VCS repository mapping for $package. Run a normal Composer update once, then fast-composer refresh.");
            }
            $urls[$i] = $url;
            if (isset($this->fetchedTips[$this->normalizeGitUrl($url)])) {
                // All branch tips were fetched from the remote during this invocation.
                continue;
            }
            $ref = 'refs/heads/'.$branch;
            $commands[$i] = [array_merge(
                $this->gitMirrorPrefix($this->ensureMirror($url)),
                ['fetch', '-q', '--depth=1', '--no-tags', '--no-write-fetch-head', '--', $url, '+'.$ref.':'.$ref]
            ), $this->root];
        }

        $locks = $this->lockMirrors(array_values(array_intersect_key($urls, $commands)));
        try {
            $results = $this->runGit($commands, 'fetching explicit dev branches', static fn ($i): string => $requests[$i][0].':dev-'.$requests[$i][1]);
        } finally {
            $this->unlockMirrors($locks);
        }
        $packages = [];
        foreach ($requests as $i => [$package, $branch]) {
            [$code, , $err] = $results[$i] ?? [0, '', ''];
            $notFound = "Branch $branch not found for $package";
            if ($code !== 0) {
                throw new \RuntimeException($notFound.($err !== '' ? ': '.$this->gitError($err, '') : ''));
            }
            $url = $urls[$i];
            [$sha, $meta] = $this->readBranch($url, $branch, $notFound);

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
            $packages[$i] = $pkg;
        }

        if ($requests !== []) {
            $this->save($snapshot);
        }
        return $packages;
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
            json_encode(['packages' => $this->groupPackages($packages)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"
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
            ComposerJson::encode($config)
        );
        return $path;
    }

    public function validateChangedPackages(array $beforeLock, array $afterLock, array $rootConfig): void
    {
        $before = $this->packagesByName($beforeLock);
        $after = $this->packagesByName($afterLock);

        $changed = [];
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
            $changed[$name] = $package;
        }

        $this->prefetchExact($changed);
        foreach ($changed as $name => $package) {
            $source = $package['source'];
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
        $raw = is_file($lockPath) ? file_get_contents($lockPath) : false;
        if ($raw === false) {
            throw new \RuntimeException("Invalid lock file: $lockPath");
        }
        $lock = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($lock) || !isset($lock['packages'])) {
            throw new \RuntimeException("Invalid lock file: $lockPath");
        }

        // Patch the hash in place: re-encoding would turn Composer's empty objects ({}) into
        // arrays ([]) and create lock-file noise.
        $hash = $this->contentHash($rootConfig);
        $patched = preg_replace('/("content-hash"\s*:\s*)"[^"]*"/', '${1}"'.$hash.'"', $raw, 1, $count);
        if (!is_string($patched) || $count !== 1) {
            $lock['content-hash'] = $hash;
            $patched = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        }
        $this->atomicWrite($lockPath, $patched);
    }

    public function verifyLock(array $rootConfig): array
    {
        $lock = $this->readLock();
        $managed = [];

        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            $source = $package['source'] ?? [];
            if (($source['type'] ?? null) !== 'git' || empty($source['url']) || empty($source['reference'])) {
                continue;
            }
            if ($this->managedRepoUrl($source['url'], $rootConfig) === null) {
                continue;
            }
            $managed[$package['name']] = $package;
        }

        $this->prefetchExact($managed, false);

        $results = [];
        foreach ($managed as $name => $package) {
            $source = $package['source'];
            try {
                $meta = $this->composerAt($source['url'], $source['reference']);
                $results[$name] = [
                    'sha' => $source['reference'],
                    'reachable' => true,
                    'metadata_match' => $this->metadataMatches($package, $meta),
                ];
            } catch (\Throwable) {
                $results[$name] = [
                    'sha' => $source['reference'],
                    'reachable' => false,
                    'metadata_match' => false,
                ];
            }
        }

        return $results;
    }

    /**
     * Rebuild a repository's versions from its local mirror after fetchMirrors() refreshed it.
     */
    private function hydrateFromMirror(array &$snapshot, string $url, string $package): void
    {
        $remote = $this->mirrorVersions($url);
        $existing = $snapshot['packages'][$package] ?? [];
        $next = [];
        $pending = [];

        foreach ($remote['versions'] as $version => $ref) {
            $current = $existing[$version] ?? null;
            if (is_array($current) && ($current['source']['reference'] ?? null) === $ref['sha']) {
                $next[$version] = $current;
                continue;
            }
            $pending[$version] = $ref;
        }

        if ($pending !== []) {
            // Every advertised tip is already in the mirror: read all composer.json files at once.
            $this->readMirrorMetadata($url, array_column($pending, 'sha'));
            foreach ($pending as $version => $ref) {
                $key = $this->metadataKey($url, $ref['sha']);
                if (!array_key_exists($key, $this->operationMetadata) || $this->operationMetadata[$key] === null) {
                    // Like Composer, refs without a readable composer.json simply provide no version.
                    continue;
                }
                $meta = $this->operationMetadata[$key];
                if (($meta['name'] ?? null) !== $package) {
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

    /**
     * Synchronize branch/tag tips of each repository into a persistent shallow mirror.
     *
     * This is a single network operation per repository that both lists refs and downloads
     * any new tips, replacing the previous ls-remote + throwaway-clone fetch pair. Fetches for
     * different repositories run concurrently.
     *
     * @param list<string> $urls
     * @return array<string,string> error message per failed URL
     */
    private function fetchMirrors(array $urls): array
    {
        $commands = [];
        foreach (array_values(array_unique($urls)) as $url) {
            if (isset($this->fetchedTips[$this->normalizeGitUrl($url)])) {
                // Already synchronized from the remote during this invocation.
                continue;
            }
            $dir = $this->ensureMirror($url);
            // The first fetch only takes the tips (--depth=1). Later fetches are incremental
            // against those tips; a repeated --depth would force an extra pack round even when
            // nothing changed.
            $depth = is_file($dir.'/'.self::MIRROR_MARKER) ? [] : ['--depth=1'];
            $commands[$url] = [array_merge(
                $this->gitMirrorPrefix($dir),
                ['fetch', '-q', '--prune', '--no-tags'],
                $depth,
                ['--', $url, '+refs/heads/*:refs/heads/*', '+refs/tags/*:refs/tags/*']
            ), $this->root];
        }
        if ($commands === []) {
            return [];
        }

        $locks = $this->lockMirrors(array_keys($commands));
        try {
            $results = $this->runGit($commands, 'fetching VCS repositories', static fn ($url): string => (string) $url);
        } finally {
            $this->unlockMirrors($locks);
        }

        $errors = [];
        foreach ($results as $url => [$code, $out, $err]) {
            if ($code !== 0) {
                $errors[$url] = $this->gitError($err !== '' ? $err : $out, "Cannot read refs from $url");
                continue;
            }
            @touch($this->mirrorDir($url).'/'.self::MIRROR_MARKER);
            // Tips fetched just now are proven reachable on the remote during this invocation.
            $this->fetchedTips[$this->normalizeGitUrl($url)] = true;
        }
        return $errors;
    }

    /** @return array{versions:array<string,array{sha:string,kind:string,ref:string}>,refs:array{heads:array<string,string>,tags:array<string,string>}} */
    private function mirrorVersions(string $url): array
    {
        $out = Process::must(array_merge(
            $this->gitMirrorPrefix($this->mirrorDir($url)),
            ['for-each-ref', '--format=%(objectname) %(*objectname) %(refname)', 'refs/heads', 'refs/tags']
        ), $this->root);

        $heads = [];
        $tags = [];
        foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
            $parts = explode(' ', $line, 3);
            if (count($parts) !== 3) {
                continue;
            }
            [$sha, $peeled, $ref] = $parts;
            if (str_starts_with($ref, 'refs/heads/')) {
                $heads[substr($ref, strlen('refs/heads/'))] = $sha;
            } elseif (str_starts_with($ref, 'refs/tags/')) {
                $tags[substr($ref, strlen('refs/tags/'))] = $peeled !== '' ? $peeled : $sha;
            }
        }

        $versions = [];
        foreach ($heads as $branch => $sha) {
            $versions[$this->branchVersion((string) $branch)] = ['sha' => $sha, 'kind' => 'branch', 'ref' => (string) $branch];
        }
        foreach ($tags as $tag => $sha) {
            $version = $this->tagVersion((string) $tag);
            if ($version === null) {
                continue;
            }
            $versions[$version] = ['sha' => $sha, 'kind' => 'tag', 'ref' => (string) $tag];
        }

        if (isset($this->fetchedTips[$this->normalizeGitUrl($url)])) {
            foreach ($versions as $ref) {
                $this->reachable[$this->metadataKey($url, $ref['sha'])] = true;
            }
        }

        return ['versions' => $versions, 'refs' => ['heads' => $heads, 'tags' => $tags]];
    }

    private function discoverRepositoryName(array &$snapshot, string $url): void
    {
        $this->discoverRepositoryNames($snapshot, [$url]);
    }

    /**
     * Package names from composer.json at each remote HEAD, as Composer determines them. Only
     * needed for repositories that composer.lock does not map yet; fetched in parallel.
     *
     * @param list<string> $urls
     */
    private function discoverRepositoryNames(array &$snapshot, array $urls): void
    {
        $errors = $this->fetchMirrors($urls);
        $commands = [];
        foreach ($urls as $url) {
            if (isset($errors[$url])) {
                throw new \RuntimeException($errors[$url]);
            }
            $commands[$url] = [array_merge(
                $this->gitMirrorPrefix($this->mirrorDir($url)),
                ['fetch', '-q', '--depth=1', '--no-tags', '--no-write-fetch-head', '--', $url, '+HEAD:'.self::MIRROR_HEAD]
            ), $this->root];
        }
        $locks = $this->lockMirrors($urls);
        try {
            $results = $this->runGit($commands, 'reading package names from remote HEAD', static fn ($url): string => (string) $url);
        } finally {
            $this->unlockMirrors($locks);
        }

        foreach ($urls as $url) {
            $candidates = [self::MIRROR_HEAD.':composer.json'];
            if (($results[$url][0] ?? 1) !== 0) {
                // Dangling remote HEAD: fall back to the conventional default branches.
                $candidates = ['refs/heads/main:composer.json', 'refs/heads/master:composer.json'];
            }
            $name = null;
            foreach ($this->catFile($this->mirrorDir($url), $candidates) as $object) {
                $name = $this->decodeComposerJson($object)['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    break;
                }
            }
            if (!is_string($name) || $name === '') {
                throw new \RuntimeException("composer.json at $url HEAD has no package name");
            }
            $snapshot['repos'][$url]['name'] = $name;
        }
    }

    private function metadataKey(string $url, string $sha): string
    {
        return hash('sha256', $this->normalizeGitUrl($url)).':'.$sha;
    }

    /**
     * composer.json at an exact SHA that was obtained from the remote during this invocation.
     */
    private function composerAt(string $url, string $sha): array
    {
        $key = $this->metadataKey($url, $sha);
        if (!isset($this->reachable[$key])) {
            $this->fetchExact([$url => [$sha]]);
        }
        if (!array_key_exists($key, $this->operationMetadata)) {
            $this->readMirrorMetadata($url, [$sha]);
        }

        $data = $this->operationMetadata[$key] ?? null;
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid composer.json at '.$sha);
        }
        return $data;
    }

    /**
     * Fetch the exact source SHAs of the given lock packages in parallel (one fetch per repo).
     *
     * @param array<string,array> $packages
     */
    private function prefetchExact(array $packages, bool $throw = true): void
    {
        $wanted = [];
        foreach ($packages as $package) {
            $url = $package['source']['url'];
            $sha = $package['source']['reference'];
            if (!isset($this->reachable[$this->metadataKey($url, $sha)])) {
                $wanted[$url][] = $sha;
            }
        }
        $errors = $this->fetchExact($wanted, false);
        if ($throw && $errors !== []) {
            throw new \RuntimeException(reset($errors));
        }
    }

    /**
     * @param array<string,list<string>> $shasByUrl
     * @return array<string,string> error message per failed URL
     */
    private function fetchExact(array $shasByUrl, bool $throw = true): array
    {
        $commands = [];
        $chunks = [];
        foreach ($shasByUrl as $url => $shas) {
            $shas = array_values(array_unique(array_filter($shas, static fn ($sha): bool => is_string($sha) && $sha !== '')));
            if ($shas === []) {
                continue;
            }
            $dir = $this->ensureMirror($url);
            // Keep command lines bounded while amortizing SSH/TLS setup across many SHAs.
            foreach (array_chunk($shas, 64) as $i => $chunk) {
                $commands[$url.'#'.$i] = [array_merge(
                    $this->gitMirrorPrefix($dir),
                    ['fetch', '-q', '--depth=1', '--no-tags', '--no-write-fetch-head', '--', $url],
                    $chunk
                ), $this->root];
                $chunks[$url.'#'.$i] = [$url, $chunk];
            }
        }

        $locks = $this->lockMirrors(array_values(array_unique(array_column($chunks, 0))));
        try {
            $results = $this->runGit($commands, 'fetching exact locked commits', static fn ($id): string => $chunks[$id][0].' ('.count($chunks[$id][1]).' SHA)');
        } finally {
            $this->unlockMirrors($locks);
        }

        $errors = [];
        foreach ($results as $id => [$code, $out, $err]) {
            [$url, $chunk] = $chunks[$id];
            if ($code !== 0) {
                $errors[$url] = $this->gitError($err !== '' ? $err : $out, 'Cannot fetch '.implode(', ', $chunk)." from $url");
                continue;
            }
            foreach ($chunk as $sha) {
                $this->reachable[$this->metadataKey($url, $sha)] = true;
            }
        }

        if ($throw && $errors !== []) {
            throw new \RuntimeException(reset($errors));
        }
        return $errors;
    }

    /**
     * Branch tip and its composer.json, read from the mirror right after fetching the branch.
     *
     * @return array{0:string,1:array}
     */
    private function readBranch(string $url, string $branch, string $notFoundMessage): array
    {
        $ref = 'refs/heads/'.$branch;
        $objects = $this->catFile($this->mirrorDir($url), [$ref.'^{commit}', $ref.':composer.json']);
        $sha = $objects[0]['oid'] ?? null;
        if (!is_string($sha) || ($objects[0]['type'] ?? null) !== 'commit') {
            throw new \RuntimeException($notFoundMessage);
        }

        $key = $this->metadataKey($url, $sha);
        $this->reachable[$key] = true;
        $this->operationMetadata[$key] = $this->withReleaseDate($this->decodeComposerJson($objects[1] ?? null), $objects[0]);
        if ($this->operationMetadata[$key] === null) {
            throw new \RuntimeException('Invalid composer.json at '.$sha);
        }
        return [$sha, $this->operationMetadata[$key]];
    }

    /** @param list<string> $shas */
    private function readMirrorMetadata(string $url, array $shas): void
    {
        $shas = array_values(array_unique(array_filter(
            $shas,
            fn ($sha): bool => is_string($sha) && $sha !== '' && !array_key_exists($this->metadataKey($url, $sha), $this->operationMetadata)
        )));
        if ($shas === []) {
            return;
        }

        $specs = [];
        foreach ($shas as $sha) {
            $specs[] = $sha.':composer.json';
            $specs[] = $sha.'^{commit}';
        }
        $objects = $this->catFile($this->mirrorDir($url), $specs);
        foreach ($shas as $i => $sha) {
            $this->operationMetadata[$this->metadataKey($url, $sha)] = $this->withReleaseDate(
                $this->decodeComposerJson($objects[2 * $i] ?? null),
                $objects[2 * $i + 1] ?? null
            );
        }
    }

    /**
     * Same release date Composer's GitDriver records: the commit author date, unless
     * composer.json declares its own "time".
     */
    private function withReleaseDate(?array $meta, ?array $commit): ?array
    {
        if ($meta === null || (isset($meta['time']) && is_string($meta['time']))) {
            return $meta;
        }
        if ($commit !== null && $commit['type'] === 'commit'
            && preg_match('/^author .* (\d+) [+-]\d{4}$/m', $commit['content'], $m)) {
            $meta['time'] = (new \DateTimeImmutable('@'.$m[1]))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_RFC3339);
        }
        return $meta;
    }

    /**
     * Read many objects with one `git cat-file --batch` process.
     *
     * @param list<string> $specs
     * @return list<?array{oid:string,type:string,content:string}>
     */
    private function catFile(string $dir, array $specs): array
    {
        [$code, $out, $err] = Process::runWithInput(
            array_merge($this->gitMirrorPrefix($dir), ['cat-file', '--batch']),
            implode("\n", $specs)."\n",
            $this->root
        );
        if ($code !== 0) {
            throw new \RuntimeException(trim($err) ?: 'git cat-file failed in '.$dir);
        }

        $result = [];
        $offset = 0;
        $length = strlen($out);
        foreach ($specs as $i => $_) {
            $eol = strpos($out, "\n", $offset);
            if ($eol === false) {
                $result[$i] = null;
                continue;
            }
            $header = substr($out, $offset, $eol - $offset);
            $offset = $eol + 1;
            if (!preg_match('/^([0-9a-f]{40,64}) (\S+) (\d+)$/', $header, $m)) {
                // "<spec> missing" / "<spec> ambiguous": no object for this spec.
                $result[$i] = null;
                continue;
            }
            $size = (int) $m[3];
            $result[$i] = ['oid' => $m[1], 'type' => $m[2], 'content' => (string) substr($out, $offset, $size)];
            $offset = min($length, $offset + $size + 1);
        }
        return $result;
    }

    private function decodeComposerJson(?array $object): ?array
    {
        if ($object === null || $object['type'] !== 'blob') {
            return null;
        }
        try {
            $data = json_decode($object['content'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    /**
     * Mirrors are shared by every project that uses the same repository URL, so a new clone or
     * worktree of a project does not start from zero.
     */
    private function mirrorDir(string $url): string
    {
        return $this->baseDir.'/mirrors/'.substr(hash('sha256', $this->normalizeGitUrl($url)), 0, 24).'.git';
    }

    private function ensureMirror(string $url): string
    {
        $dir = $this->mirrorDir($url);
        if (!is_file($dir.'/HEAD')) {
            $parent = dirname($dir);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new \RuntimeException("Cannot create mirror directory $parent");
            }
            Process::must(['git', 'init', '-q', '--bare', $dir], $this->root);
        }
        return $dir;
    }

    /**
     * Serialize fetches into a shared mirror across processes (other projects may use it).
     * Locks are taken in a stable order so concurrent processes cannot deadlock.
     *
     * @param list<string> $urls
     * @return list<resource>
     */
    private function lockMirrors(array $urls): array
    {
        $dirs = array_values(array_unique(array_map(fn (string $url): string => $this->mirrorDir($url), $urls)));
        sort($dirs);
        $handles = [];
        foreach ($dirs as $dir) {
            $handle = @fopen($dir.'.lock', 'c');
            if ($handle === false) {
                continue;
            }
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                $this->log('waiting for another fast-composer process that is fetching into '.basename($dir).' ...');
                flock($handle, LOCK_EX);
            }
            $handles[] = $handle;
        }
        return $handles;
    }

    /** @param list<resource> $handles */
    private function unlockMirrors(array $handles): void
    {
        foreach ($handles as $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return list<string> */
    private function gitMirrorPrefix(string $dir): array
    {
        // Mirrors are private metadata caches: never trigger background maintenance or gc.
        return ['git', '-c', 'maintenance.auto=false', '-c', 'gc.auto=0', '-c', 'fetch.writeCommitGraph=false', '--git-dir='.$dir];
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

        // Deliberately outside Composer's cache-dir: `composer clear-cache` must not throw away
        // the snapshot and mirrors.
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

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }
}
