<?php
namespace FastComposer;

/**
 * Version names of VCS tags and branches exactly as Composer's VcsRepository derives them.
 *
 * normalize()/normalizeBranch() are ports of composer/semver's VersionParser. A tag Composer
 * cannot parse (e.g. "0.3-no-vendor") must be skipped: if it reached the snapshot, Composer
 * would reject the whole snapshot repository.
 */
final class ComposerVersion
{
    public const DEFAULT_BRANCH_ALIAS = '9999999-dev';

    private const MODIFIER = '[._-]?(?:(stable|beta|b|RC|alpha|a|patch|pl|p)((?:[.-]?\d+)*+)?)?([.-]?dev)?';
    private const STABILITIES = 'stable|RC|beta|alpha|dev';

    /** Composer's normalized version, or null where Composer throws "Invalid version string". */
    public static function normalize(string $version): ?string
    {
        $version = trim($version);

        if (preg_match('{^([^,\s]++) ++as ++([^,\s]++)$}', $version, $match)) {
            $version = $match[1];
        }
        if (preg_match('{@(?:'.self::STABILITIES.')$}i', $version, $match)) {
            $version = substr($version, 0, strlen($version) - strlen($match[0]));
        }
        if (in_array($version, ['master', 'trunk', 'default'], true)) {
            $version = 'dev-'.$version;
        }
        if (stripos($version, 'dev-') === 0) {
            return 'dev-'.substr($version, 4);
        }
        if (preg_match('{^([^,\s+]++)\+[^\s]++$}', $version, $match)) {
            $version = $match[1];
        }

        if (preg_match('{^v?(\d{1,5}+)(\.\d++)?(\.\d++)?(\.\d++)?'.self::MODIFIER.'$}i', $version, $matches)) {
            $version = $matches[1]
                .(!empty($matches[2]) ? $matches[2] : '.0')
                .(!empty($matches[3]) ? $matches[3] : '.0')
                .(!empty($matches[4]) ? $matches[4] : '.0');
            $index = 5;
        } elseif (preg_match('{^v?(\d{4}(?:[.:-]?\d{2}){1,6}(?:[.:-]?\d{1,3}){0,2})'.self::MODIFIER.'$}i', $version, $matches)) {
            $version = (string) preg_replace('{\D}', '.', $matches[1]);
            $index = 2;
        }

        if (isset($index)) {
            if (!empty($matches[$index])) {
                if ($matches[$index] === 'stable') {
                    return $version;
                }
                $version .= '-'.self::expandStability($matches[$index])
                    .(isset($matches[$index + 1]) && $matches[$index + 1] !== '' ? ltrim($matches[$index + 1], '.-') : '');
            }
            if (!empty($matches[$index + 2])) {
                $version .= '-dev';
            }
            return $version;
        }

        if (preg_match('{(.*?)[.-]?dev$}i', $version, $match)) {
            $normalized = self::normalizeBranch($match[1]);
            if (!str_contains($normalized, 'dev-')) {
                return $normalized;
            }
        }

        return null;
    }

    public static function normalizeBranch(string $name): string
    {
        $name = trim($name);
        if (preg_match('{^v?(\d++)(\.(?:\d++|[xX*]))?(\.(?:\d++|[xX*]))?(\.(?:\d++|[xX*]))?$}i', $name, $matches)) {
            $version = '';
            for ($i = 1; $i < 5; ++$i) {
                $version .= isset($matches[$i]) ? str_replace(['*', 'X'], 'x', $matches[$i]) : '.x';
            }
            return str_replace('x', '9999999', $version).'-dev';
        }
        return 'dev-'.$name;
    }

    /**
     * Pretty version and normalized version of a tag, or null when Composer skips the tag.
     *
     * @return array{0:string,1:string}|null
     */
    public static function fromTag(string $tag): ?array
    {
        $tag = str_replace('release-', '', $tag);
        $parsed = self::normalize($tag);
        if ($parsed === null) {
            return null;
        }
        // Tags can not use dev prefixes or suffixes.
        $version = (string) preg_replace('{[.-]?dev$}i', '', $tag);
        $normalized = (string) preg_replace('{(^dev-|[.-]?dev$)}i', '', $parsed);
        return $normalized === $parsed ? [$version, $parsed] : null;
    }

    /** Version of a branch, or null when Composer skips the branch name. */
    public static function fromBranch(string $branch): ?string
    {
        // Composer also rejects names that do not survive constraint parsing.
        if ($branch === '' || preg_match('{[\s,|]}', $branch)) {
            return null;
        }
        $parsed = self::normalizeBranch($branch);
        if (str_starts_with($parsed, 'dev-') || $parsed === self::DEFAULT_BRANCH_ALIAS) {
            return 'dev-'.str_replace('#', '+', $branch);
        }
        $prefix = str_starts_with($branch, 'v') ? 'v' : '';
        return $prefix.preg_replace('{(\.9{7})+}', '.x', $parsed);
    }

    private static function expandStability(string $stability): string
    {
        return match (strtolower($stability)) {
            'a' => 'alpha',
            'b' => 'beta',
            'p', 'pl' => 'patch',
            'rc' => 'RC',
            default => strtolower($stability),
        };
    }
}
