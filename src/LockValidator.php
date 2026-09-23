<?php
namespace FastComposer;

/**
 * Checks lock entries of managed VCS packages against the composer.json at their exact locked
 * SHA, fetched from the remote. `composer install` trusts lock metadata, so this is what stops
 * a lock whose dependency/autoload metadata does not match its source from being published.
 */
final class LockValidator
{
    private const VERIFY = [
        'type', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'suggest', 'autoload',
        'include-path', 'target-dir', 'bin', 'extra',
    ];

    public function __construct(private GitMirror $mirror)
    {
    }

    /** Refuse (throw) unless every managed VCS package that changed matches its source. */
    public function validateChanged(array $beforeLock, array $afterLock, array $rootConfig): void
    {
        $before = LockFile::packagesByName($beforeLock);
        $changed = [];
        foreach (LockFile::packagesByName($afterLock) as $name => $package) {
            $previous = $before[$name] ?? null;
            if ($previous === null || JsonFile::canonical($previous) !== JsonFile::canonical($package)) {
                $changed[] = $package;
            }
        }

        $changed = LockFile::managedGitPackages($changed, $rootConfig);
        $this->prefetch($changed, true);
        foreach ($changed as $name => $package) {
            $source = $package['source'];
            $meta = $this->mirror->composerAt($source['url'], $source['reference']);
            if (!$this->matches($package, $meta)) {
                throw new \RuntimeException(
                    "Lock metadata mismatch for $name at {$source['reference']}; refusing to write composer.lock"
                );
            }
        }
    }

    /**
     * Check every managed VCS package in a lock (`fast-composer verify`).
     *
     * @return array<string,array{sha:string,reachable:bool,metadata_match:bool}>
     */
    public function verify(array $lock, array $rootConfig): array
    {
        $managed = LockFile::managedGitPackages(LockFile::packages($lock), $rootConfig);
        $this->prefetch($managed, false);

        $results = [];
        foreach ($managed as $name => $package) {
            $source = $package['source'];
            try {
                $meta = $this->mirror->composerAt($source['url'], $source['reference']);
                $results[$name] = ['sha' => $source['reference'], 'reachable' => true, 'metadata_match' => $this->matches($package, $meta)];
            } catch (\Throwable) {
                $results[$name] = ['sha' => $source['reference'], 'reachable' => false, 'metadata_match' => false];
            }
        }
        return $results;
    }

    /**
     * Fetch the exact source SHAs of all packages up front, one parallel fetch per repository.
     *
     * @param array<string,array> $packages
     */
    private function prefetch(array $packages, bool $throw): void
    {
        $wanted = [];
        foreach ($packages as $package) {
            $wanted[$package['source']['url']][] = $package['source']['reference'];
        }
        $errors = $this->mirror->fetchExact($wanted, false);
        if ($throw && $errors !== []) {
            throw new \RuntimeException(reset($errors));
        }
    }

    private function matches(array $lockedPackage, array $sourceComposer): bool
    {
        $lockedName = strtolower((string) ($lockedPackage['name'] ?? ''));
        if ($lockedName !== strtolower((string) ($sourceComposer['name'] ?? ''))) {
            // A version may carry an old/other "name"; Composer then uses the name from the
            // repository's default branch. Accept exactly that, nothing else.
            $url = $lockedPackage['source']['url'] ?? null;
            if (!is_string($url) || $lockedName === ''
                || $lockedName !== strtolower($this->mirror->defaultBranchNames([$url])[$url])) {
                return false;
            }
        }
        return $this->comparable($lockedPackage) === $this->comparable($sourceComposer);
    }

    private function comparable(array $package): array
    {
        $result = ['type' => $package['type'] ?? 'library'];
        foreach (self::VERIFY as $key) {
            if ($key !== 'type' && array_key_exists($key, $package)) {
                $result[$key] = $package[$key];
            }
        }
        return JsonFile::canonical($result);
    }
}
