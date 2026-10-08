<?php

namespace FastComposer;

use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;

/**
 * Compare source composer.json to a lock entry using Composer's own package normalization.
 *
 * In particular, Composer omits empty metadata, normalizes dependency names, and converts
 * scalar bin values into lists. A raw JSON comparison flags those as false mismatches.
 */
final class LockMetadataComparison
{
    private const FIELDS = [
        'type', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'suggest',
        'autoload', 'autoload-dev', 'include-path', 'target-dir', 'bin', 'extra',
    ];

    /**
     * Metadata paths that differ, without dumping possibly sensitive field values.
     *
     * The caller must independently validate package identity and remote SHA.
     * @return list<string>
     */
    public static function differences(array $locked, array $source): array
    {
        if (!ComposerPackages::available()) {
            throw new \RuntimeException('Composer classes are required for metadata verification');
        }

        $loader = new ArrayLoader();
        $dumper = new ArrayDumper();

        // GitDriver/VcsRepository derive name and version from the repository/ref, not from
        // composer.json. Identity is checked separately by LockValidator.
        $source['name'] = $locked['name'];
        $source['version'] = $locked['version'];
        if (isset($locked['version_normalized'])) {
            $source['version_normalized'] = $locked['version_normalized'];
        } else {
            unset($source['version_normalized']);
        }

        $expected = $dumper->dump($loader->load($source));
        $actual = $dumper->dump($loader->load($locked));

        $wanted = array_fill_keys(self::FIELDS, true);
        $expected = JsonFile::canonical(array_intersect_key($expected, $wanted));
        $actual = JsonFile::canonical(array_intersect_key($actual, $wanted));

        $paths = [];
        self::collect($expected, $actual, '', $paths);
        return $paths;
    }

    /** @param list<string> $paths */
    private static function collect(mixed $expected, mixed $actual, string $prefix, array &$paths): void
    {
        if ($expected === $actual) {
            return;
        }

        if (!is_array($expected) || !is_array($actual)) {
            $paths[] = $prefix;
            return;
        }

        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $key) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (!array_key_exists($key, $expected) || !array_key_exists($key, $actual)) {
                $paths[] = $name;
                continue;
            }
            self::collect($expected[$key], $actual[$key], $name, $paths);
        }
    }
}
