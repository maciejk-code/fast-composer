<?php
namespace FastComposer;

final class LockFile
{
    /** @return list<array> packages and packages-dev */
    public static function packages(array $lock): array
    {
        return array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);
    }

    /** @return array<string,array> */
    public static function packagesByName(array $lock): array
    {
        $result = [];
        foreach (self::packages($lock) as $package) {
            if (isset($package['name'])) {
                $result[$package['name']] = $package;
            }
        }
        return $result;
    }

    /**
     * Lock entries whose Git source is one of the root's declared VCS repositories.
     *
     * @param iterable<array> $packages
     * @return array<string,array> by package name
     */
    public static function managedGitPackages(iterable $packages, array $rootConfig): array
    {
        $managed = [];
        foreach ($packages as $package) {
            $source = $package['source'] ?? [];
            if (($source['type'] ?? null) !== 'git' || empty($source['url']) || empty($source['reference'])) {
                continue;
            }
            if (RootConfig::managedUrlFor($source['url'], $rootConfig) === null) {
                continue;
            }
            $managed[$package['name']] = $package;
        }
        return $managed;
    }

    /** Set the content-hash for the real root composer.json, keeping the file byte-identical otherwise. */
    public static function fixContentHash(string $lockPath, array $rootConfig): void
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
        $hash = RootConfig::contentHash($rootConfig);
        $patched = preg_replace('/("content-hash"\s*:\s*)"[^"]*"/', '${1}"'.$hash.'"', $raw, 1, $count);
        if (!is_string($patched) || $count !== 1) {
            $lock['content-hash'] = $hash;
            $patched = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        }
        JsonFile::atomicWrite($lockPath, $patched, true);
    }
}
