<?php
namespace FastComposer;

/** Questions about the root composer.json that several components need to answer the same way. */
final class RootConfig
{
    /** @return list<array> declared `type: vcs` repositories that have a URL */
    public static function vcsRepositories(array $rootConfig): array
    {
        $repos = [];
        foreach (($rootConfig['repositories'] ?? []) as $repo) {
            if (is_array($repo) && ($repo['type'] ?? null) === 'vcs' && is_string($repo['url'] ?? null)) {
                $repos[] = $repo;
            }
        }
        return $repos;
    }

    /** @return list<string> */
    public static function vcsUrls(array $rootConfig): array
    {
        return array_column(self::vcsRepositories($rootConfig), 'url');
    }

    /** Every repository entry except `type: vcs` ones (those are replaced by the snapshot). */
    public static function nonVcsRepositories(array $rootConfig): array
    {
        $other = [];
        foreach (($rootConfig['repositories'] ?? []) as $repo) {
            if (!(is_array($repo) && ($repo['type'] ?? null) === 'vcs')) {
                $other[] = $repo;
            }
        }
        return $other;
    }

    /** The declared VCS URL that a lock entry's source URL points to, if any. */
    public static function managedUrlFor(string $sourceUrl, array $rootConfig): ?string
    {
        $source = GitUrl::normalize($sourceUrl);
        foreach (self::vcsUrls($rootConfig) as $url) {
            if (GitUrl::normalize($url) === $source) {
                return $url;
            }
        }
        return null;
    }

    /** Identity of the VCS repository configuration; a change invalidates the snapshot. */
    public static function repositoriesHash(array $rootConfig): string
    {
        $repos = [];
        foreach (self::vcsRepositories($rootConfig) as $repo) {
            $repo['url'] = GitUrl::normalize($repo['url']);
            $repos[] = JsonFile::canonical($repo);
        }
        usort($repos, static fn (array $a, array $b): int => ($a['url'] ?? '') <=> ($b['url'] ?? ''));
        return hash('sha256', json_encode($repos, JSON_THROW_ON_ERROR));
    }

    /** Composer's lock "content-hash" for this root configuration. */
    public static function contentHash(array $rootConfig): string
    {
        $relevantKeys = [
            'name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide',
            'minimum-stability', 'prefer-stable', 'repositories', 'extra',
        ];

        $relevant = [];
        foreach (array_intersect($relevantKeys, array_keys($rootConfig)) as $key) {
            $relevant[$key] = $rootConfig[$key];
        }
        if (isset($rootConfig['config']['platform'])) {
            $relevant['config']['platform'] = $rootConfig['config']['platform'];
        }
        ksort($relevant);

        return hash('md5', json_encode($relevant, JSON_THROW_ON_ERROR));
    }
}
