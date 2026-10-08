<?php

namespace FastComposer;

/**
 * Checks lock entries of managed VCS packages against the composer.json at their exact locked
 * SHA, fetched from the remote. `composer install` trusts lock metadata, so this is what stops
 * a lock whose dependency/autoload metadata does not match its source from being published.
 */
final class LockValidator
{
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
            $differences = $this->differences($package, $meta);
            if ($differences !== []) {
                throw new LockMetadataMismatch(
                    $name,
                    (string) $source['url'],
                    (string) $source['reference'],
                    $differences
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
                $results[$name] = ['sha' => $source['reference'], 'reachable' => true, 'metadata_match' => $this->differences($package, $meta) === []];
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

    /** @return list<string> metadata paths that differ */
    private function differences(array $lockedPackage, array $sourceComposer): array
    {
        $lockedName = strtolower((string) ($lockedPackage['name'] ?? ''));
        if ($lockedName !== strtolower((string) ($sourceComposer['name'] ?? ''))) {
            // Historical tags may have an old package name. Composer uses the default
            // branch name for those tags; do not weaken that existing exception.
            $url = $lockedPackage['source']['url'] ?? null;
            if (
                !is_string($url) || $lockedName === ''
                || $lockedName !== strtolower($this->mirror->defaultBranchNames([$url])[$url] ?? '')
            ) {
                return ['name'];
            }
        }

        return LockMetadataComparison::differences($lockedPackage, $sourceComposer);
    }
}
