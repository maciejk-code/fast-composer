<?php
namespace FastComposer;

final class Snapshot
{
    public const DIR = '.fast-composer';

    private const KEEP = [
        'name','description','type','keywords','homepage','license','authors','support','funding',
        'require','require-dev','conflict','replace','provide','suggest','autoload','include-path',
        'target-dir','bin','extra',
    ];

    public function __construct(private string $root) {}

    public function dir(): string
    {
        return $this->root.'/'.self::DIR;
    }

    public function load(): array
    {
        $path = $this->dir().'/snapshot.json';
        return is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }

    public function save(array $snapshot): void
    {
        if (!is_dir($this->dir())) {
            mkdir($this->dir(), 0777, true);
        }
        file_put_contents(
            $this->dir().'/snapshot.json',
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
    }

    public function readLock(string $path = 'composer.lock'): array
    {
        if (!str_starts_with($path, '/')) {
            $path = $this->root.'/'.$path;
        }
        return $this->readJson($path, []);
    }

    public function buildFromLockAndCache(array $rootConfig): array
    {
        $snapshot = ['generated_at' => time(), 'repos' => [], 'packages' => []];
        $lock = $this->readLock();

        foreach (($rootConfig['repositories'] ?? []) as $repo) {
            if (is_array($repo) && ($repo['type'] ?? null) === 'vcs' && is_string($repo['url'] ?? null)) {
                $snapshot['repos'][$repo['url']] = ['url' => $repo['url']];
            }
        }

        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            $source = $package['source'] ?? [];
            if (($source['type'] ?? null) !== 'git' || empty($source['url']) || empty($package['name'])) {
                continue;
            }
            $snapshot['repos'][$source['url']]['name'] = $package['name'];
            $snapshot['packages'][$package['name']][$package['version']] = $this->cleanPackage($package);
        }

        foreach (array_keys($snapshot['repos']) as $url) {
            $this->addCachedTags($snapshot, $url);
        }

        $this->save($snapshot);
        return $snapshot;
    }

    public function ensureBranch(array &$snapshot, string $package, string $branch): array
    {
        $url = $this->urlForPackage($snapshot, $package);
        if (!$url) {
            throw new \RuntimeException("No VCS repository mapping for $package. Run a normal Composer update once, then fast-composer refresh.");
        }

        $ref = 'refs/heads/'.$branch;
        $out = Process::must(['git', 'ls-remote', $url, $ref], $this->root);
        $line = trim($out);
        if ($line === '') {
            throw new \RuntimeException("Branch $branch not found for $package");
        }

        $sha = preg_split('/\s+/', $line)[0];
        $meta = $this->composerAt($url, $sha);
        if (($meta['name'] ?? null) !== $package) {
            throw new \RuntimeException("Repository package name mismatch: expected $package");
        }

        $pkg = $this->cleanPackage($meta);
        $pkg['name'] = $package;
        $pkg['version'] = 'dev-'.$branch;
        $pkg['source'] = ['type' => 'git', 'url' => $url, 'reference' => $sha];

        $snapshot['packages'][$package][$pkg['version']] = $pkg;
        $snapshot['repos'][$url]['name'] = $package;
        $snapshot['repos'][$url]['branches'][$branch] = $sha;
        $this->save($snapshot);

        return $pkg;
    }

    public function writeFastComposer(array $rootConfig, array $snapshot): string
    {
        if (!is_dir($this->dir())) {
            mkdir($this->dir(), 0777, true);
        }

        $packages = [];
        foreach ($snapshot['packages'] ?? [] as $versions) {
            foreach ($versions as $package) {
                $packages[] = $package;
            }
        }

        file_put_contents(
            $this->dir().'/packages.json',
            json_encode(['packages' => $this->groupPackages($packages)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );

        $config = $rootConfig;
        $other = [];
        foreach (($config['repositories'] ?? []) as $repo) {
            if (!(is_array($repo) && ($repo['type'] ?? null) === 'vcs')) {
                $other[] = $repo;
            }
        }
        $config['repositories'] = array_merge([['type' => 'composer', 'url' => $this->dir()]], $other);

        $path = $this->root.'/.fast-composer.json';
        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        return $path;
    }

    /**
     * Mandatory fast-path safety check. Only Git packages whose lock entry changed are re-read
     * from the exact locked SHA. This keeps the common path targeted while ensuring the lock never
     * contains package metadata that differs from composer.json at that SHA.
     */
    public function validateChangedPackages(array $beforeLock, array $afterLock): void
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

            $meta = $this->composerAt($source['url'], $source['reference']);
            if (!$this->metadataMatches($package, $meta)) {
                throw new \RuntimeException(
                    "Lock metadata mismatch for $name at {$source['reference']}; refusing to write composer.lock"
                );
            }
        }
    }

    /**
     * The temporary .fast-composer.json deliberately has different repositories, so Composer's
     * generated content-hash is not valid for the real composer.json. Replace it with the hash
     * Composer itself defines for the real root configuration.
     */
    public function fixContentHash(string $lockPath, array $rootConfig): void
    {
        $lock = $this->readJson($lockPath, []);
        if (!isset($lock['packages'])) {
            throw new \RuntimeException("Invalid lock file: $lockPath");
        }
        $lock['content-hash'] = $this->contentHash($rootConfig);
        file_put_contents($lockPath, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    public function verifyLock(): array
    {
        $lock = $this->readLock();
        $results = [];

        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            $source = $package['source'] ?? [];
            if (($source['type'] ?? null) !== 'git' || empty($source['url']) || empty($source['reference'])) {
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

    private function addCachedTags(array &$snapshot, string $url): void
    {
        if (!preg_match('~github\.com[/:]([^/]+)/([^/]+?)(?:\.git)?$~', $url, $m)) {
            return;
        }

        $owner = $m[1];
        $repo = preg_replace('/\.git$/', '', $m[2]);
        [$code, $out] = Process::run(['git', 'ls-remote', '--tags', $url], $this->root);
        if ($code !== 0) {
            return;
        }

        $tags = [];
        $peeled = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (!$line) continue;
            [$sha, $ref] = preg_split('/\s+/', $line, 2);
            $tag = substr($ref, 10);
            if (str_ends_with($tag, '^{}')) {
                $peeled[substr($tag, 0, -3)] = $sha;
            } else {
                $tags[$tag] = $sha;
            }
        }
        $tags = array_replace($tags, $peeled);

        $cache = $this->composerCacheRepoDir();
        foreach ($tags as $tag => $sha) {
            $version = preg_replace('/^v/', '', $tag);
            if (!preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
                continue;
            }

            $paths = [
                "$cache/github.com/".strtolower($owner)."/$repo/$sha",
                "$cache/github.com/$owner/$repo/$sha",
            ];
            $meta = null;
            foreach ($paths as $path) {
                if (is_file($path)) {
                    $meta = json_decode(file_get_contents($path), true);
                    if (is_array($meta)) break;
                }
            }
            if (!$meta) continue;

            $name = $snapshot['repos'][$url]['name'] ?? ($meta['name'] ?? null);
            if (!$name) continue;

            $pkg = $this->cleanPackage($meta);
            $pkg['name'] = $name;
            $pkg['version'] = $version;
            $pkg['source'] = ['type' => 'git', 'url' => $url, 'reference' => $sha];
            $snapshot['packages'][$name][$version] = $pkg;
        }
    }

    private function composerCacheRepoDir(): string
    {
        [$code, $out] = Process::run(['composer', 'config', 'cache-repo-dir', '--absolute'], $this->root);
        return $code === 0
            ? trim($out)
            : (getenv('COMPOSER_CACHE_DIR') ?: getenv('HOME').'/.cache/composer').'/repo';
    }

    private function composerAt(string $url, string $sha): array
    {
        if (!is_dir($this->dir())) {
            mkdir($this->dir(), 0777, true);
        }
        $tmp = $this->dir().'/fetch-'.bin2hex(random_bytes(4));
        mkdir($tmp);

        try {
            Process::must(['git', 'init', '-q'], $tmp);
            Process::must(['git', 'remote', 'add', 'origin', $url], $tmp);
            Process::must(['git', 'fetch', '-q', '--depth=1', 'origin', $sha], $tmp);
            $json = Process::must(['git', 'show', $sha.':composer.json'], $tmp);
            $data = json_decode($json, true);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid composer.json at '.$sha);
            }
            return $data;
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
        $result = [];
        foreach (self::KEEP as $key) {
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

        return hash('md5', json_encode($relevant));
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

    private function urlForPackage(array $snapshot, string $name): ?string
    {
        foreach ($snapshot['repos'] ?? [] as $url => $repo) {
            if (($repo['name'] ?? null) === $name) {
                return $url;
            }
        }
        return null;
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

    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON: $path");
        }
        return $data;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = "$dir/$file";
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
