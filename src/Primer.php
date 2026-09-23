<?php
namespace FastComposer;

final class Primer
{
    private const KEEP = [
        'name','description','type','keywords','homepage','license','authors','support','funding',
        'require','require-dev','conflict','replace','provide','suggest','autoload','include-path',
        'target-dir','bin','extra',
    ];

    private string $root;
    private Snapshot $snapshot;
    private ?string $composerCacheRepoDir = null;

    public function __construct(string $root, Snapshot $snapshot)
    {
        $resolved = realpath($root);
        $this->root = $resolved !== false ? $resolved : rtrim($root, DIRECTORY_SEPARATOR);
        $this->snapshot = $snapshot;
    }

    /**
     * Build the initial snapshot without hydrating metadata for every branch/tag.
     *
     * The lock file is the metadata source for versions already selected by Composer.
     * Priming only records remote refs. Repositories that are not represented in the
     * lock may need one HEAD metadata lookup so future targeted operations can map a
     * package name back to its VCS URL.
     *
     * @param null|callable(int,int,?string,string,bool):void $progress
     */
    public function build(array $rootConfig, ?callable $progress = null): array
    {
        $state = [
            'format' => Snapshot::FORMAT,
            'generated_at' => time(),
            'repo_config_hash' => $this->repoConfigHash($rootConfig),
            'repos' => [],
            'packages' => [],
        ];

        foreach ($this->managedRepositories($rootConfig) as $url) {
            $state['repos'][$url] = ['url' => $url, 'managed' => true];
        }

        $this->mergeLock($state, $rootConfig, $this->snapshot->readLock());

        $urls = array_keys($state['repos']);
        $total = count($urls);
        foreach ($urls as $index => $url) {
            $needsName = empty($state['repos'][$url]['name']);
            if ($progress !== null) {
                $progress($index + 1, $total, $state['repos'][$url]['name'] ?? null, $url, $needsName);
            }

            try {
                if ($needsName) {
                    $this->discoverRepositoryName($state, $url);
                }

                $refs = $this->remoteRefs($url);
                $state['repos'][$url]['refs'] = $refs;
                $state['repos'][$url]['checked_at'] = time();
                unset($state['repos'][$url]['last_error']);
            } catch (\Throwable $e) {
                $state['repos'][$url]['last_error'] = $e->getMessage();
            }
        }

        $this->snapshot->save($state);
        return $state;
    }

    private function mergeLock(array &$state, array $rootConfig, array $lock): void
    {
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            $source = $package['source'] ?? [];
            if (($source['type'] ?? null) !== 'git' || empty($source['url']) || empty($package['name'])) {
                continue;
            }

            $managedUrl = $this->managedRepoUrl($source['url'], $rootConfig);
            if ($managedUrl === null) {
                continue;
            }

            $state['repos'][$managedUrl] ??= ['url' => $managedUrl, 'managed' => true];
            $state['repos'][$managedUrl]['name'] = $package['name'];
            $state['packages'][$package['name']][$package['version']] = $this->cleanPackage($package);
        }
    }

    /** @return array{heads:array<string,string>,tags:array<string,string>} */
    private function remoteRefs(string $url): array
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

        return ['heads' => $heads, 'tags' => array_replace($tags, $peeled)];
    }

    private function discoverRepositoryName(array &$state, string $url): void
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
        $state['repos'][$url]['name'] = $name;
    }

    private function metadataAt(string $url, string $sha): array
    {
        return $this->composerCacheMetadata($url, $sha) ?? $this->composerAt($url, $sha);
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
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $meta = json_decode($raw, true);
            if (is_array($meta)) {
                return $meta;
            }
        }
        return null;
    }

    private function composerCacheRepoDir(): string
    {
        if ($this->composerCacheRepoDir !== null) {
            return $this->composerCacheRepoDir;
        }
        [$code, $out] = Process::run(['composer', 'config', 'cache-repo-dir', '--absolute'], $this->root);
        if ($code === 0 && trim($out) !== '') {
            return $this->composerCacheRepoDir = trim($out);
        }
        return $this->composerCacheRepoDir = $this->composerCacheBaseDir().'/repo';
    }

    private function composerAt(string $url, string $sha): array
    {
        $base = $this->snapshot->dir();
        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException("Cannot create Fast Composer cache directory $base");
        }

        $tmp = $base.'/prime-fetch-'.bin2hex(random_bytes(5));
        if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            throw new \RuntimeException("Cannot create temporary directory $tmp");
        }

        try {
            Process::must(['git', 'init', '-q'], $tmp);
            Process::must(['git', 'remote', 'add', 'origin', $url], $tmp);
            Process::must(['git', 'fetch', '-q', '--depth=1', 'origin', $sha], $tmp);
            $json = Process::must(['git', 'show', $sha.':composer.json'], $tmp);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid composer.json at '.$sha);
            }
            return $data;
        } finally {
            $this->rrmdir($tmp);
        }
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
