<?php

namespace FastComposer;

/**
 * A one-sided proof: only identical ordinary ZIP trees are accepted.
 * All other cases are handled by Composer's own downloader/comparer.
 */
final class StatusZipComparison
{
    public static function identical(string $archive, string $path, ?StatusFingerprintCache $cache = null): bool
    {
        // ext-zip exposes encryption metadata only on builds with encryption support.
        if (!defined('ZipArchive::EM_AES_128') || is_link($path) || !is_dir($path)) {
            return false;
        }
        // The archive itself is always rehashed. Never infer validity from size or mtime.
        $digest = $cache !== null ? @hash_file('sha256', $archive) : false;
        $cached = $cache !== null && $digest !== false ? $cache->load($archive, $digest) : null;
        $fingerprints = [];
        $zip = new \ZipArchive();
        if ($zip->open($archive, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) {
            return false;
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 50000) {
                return false;
            }
            $entries = [];
            $caseNames = [];
            $explicit = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    return false;
                }
                $name = $stat['name'];
                $directory = str_ends_with($name, '/');
                $name = $directory ? substr($name, 0, -1) : $name;
                if (isset($explicit[$name]) || !self::ordinaryPath($name)) {
                    return false;
                }
                $explicit[$name] = true;
                $os = 0;
                $attributes = 0;
                if (!$zip->getExternalAttributesIndex($i, $os, $attributes)) {
                    return false;
                }
                if ($os === \ZipArchive::OPSYS_UNIX) {
                    $type = ($attributes >> 16) & 0170000;
                    if ($type !== 0 && $type !== ($directory ? 0040000 : 0100000)) {
                        return false;
                    }
                } elseif ($os !== \ZipArchive::OPSYS_DOS) {
                    return false;
                }
                if (
                    $stat['encryption_method'] !== \ZipArchive::EM_NONE
                    || !in_array($stat['comp_method'], [\ZipArchive::CM_STORE, \ZipArchive::CM_DEFLATE], true)
                    || $stat['size'] < 0 || $stat['size'] > 100 * 1024 * 1024
                    || ($directory && $stat['size'] !== 0)
                    || ($stat['size'] > 0 && $stat['comp_size'] * 100 < $stat['size'])
                ) {
                    return false;
                }
                $total += $stat['size'];
                if ($total > 512 * 1024 * 1024) {
                    return false;
                }
                if (!self::add($entries, $caseNames, $name, $directory ? null : $stat)) {
                    return false;
                }
                $parent = dirname($name);
                while ($parent !== '.') {
                    if (!self::add($entries, $caseNames, $parent, null)) {
                        return false;
                    }
                    $parent = dirname($parent);
                }
            }

            // Composer strips one enclosing directory, not a single root file.
            $top = array_values(array_filter(array_keys($entries), static fn (string $name): bool => !str_contains($name, '/')));
            $prefix = count($top) === 1 && $entries[$top[0]] === null ? $top[0].'/' : '';
            $expected = [];
            foreach ($entries as $name => $entry) {
                if ($prefix !== '' && $name.'/' === $prefix) {
                    continue;
                }
                $expected[substr($name, strlen($prefix))] = $entry;
            }
            if ($expected === []) {
                return false;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $file) {
                $relative = substr($file->getPathname(), strlen(rtrim($path, '/')) + 1);
                if ($file->isLink() || !array_key_exists($relative, $expected)) {
                    return false;
                }
                $entry = $expected[$relative];
                if ($entry === null) {
                    if (!$file->isDir()) {
                        return false;
                    }
                } else {
                    if (!$file->isFile() || $file->getSize() !== $entry['size']) {
                        return false;
                    }
                    $name = $entry['name'];
                    $fingerprint = null;
                    if ($cached !== null && isset($cached[$name])) {
                        $actual = hash_file('sha256', $file->getPathname());
                        if ($actual === false || !hash_equals($cached[$name], $actual)) {
                            return false;
                        }
                        $fingerprint = $actual;
                    } elseif (!self::sameFile($zip, $name, $file->getPathname(), $entry['size'], $entry['crc'], $fingerprint)) {
                        return false;
                    }
                    if ($fingerprint !== null) {
                        $fingerprints[$name] = $fingerprint;
                    }
                }
                unset($expected[$relative]);
            }
            if ($expected !== []) {
                return false;
            }
            if ($cache !== null && $digest !== false) {
                // Reject results if Composer replaced the archive during comparison.
                if (hash_file('sha256', $archive) !== $digest) {
                    return false;
                }
                if ($cached === null || $fingerprints !== $cached) {
                    $cache->save($archive, $digest, $fingerprints);
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            $zip->close();
        }
    }

    private static function ordinaryPath(string $path): bool
    {
        foreach (explode('/', $path) as $part) {
            if (
                !preg_match('/^[A-Za-z0-9_.@+ -]+$/D', $part)
                || $part === '.' || $part === '..' || $part === '.DS_Store'
                || str_ends_with($part, '.') || str_ends_with($part, ' ')
                || ctype_digit($part)
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, array|null> $entries
     * @param array<string, string> $caseNames
     */
    private static function add(array &$entries, array &$caseNames, string $name, ?array $entry): bool
    {
        $lower = strtolower($name);
        if (isset($caseNames[$lower]) && $caseNames[$lower] !== $name) {
            return false;
        }
        if (array_key_exists($name, $entries) && ($entries[$name] !== null || $entry !== null)) {
            return false;
        }
        $caseNames[$lower] = $name;
        $entries[$name] = $entry;
        return true;
    }

    private static function sameFile(\ZipArchive $zip, string $name, string $path, int $size, int $crc, ?string &$fingerprint): bool
    {
        $archive = $zip->getStream($name);
        if ($archive === false) {
            return false;
        }
        $local = @fopen($path, 'rb');
        try {
            if ($local === false) {
                return false;
            }
            $hash = hash_init('crc32b');
            $contentHash = hash_init('sha256');
            $remaining = $size;
            while ($remaining > 0) {
                $length = min(65536, $remaining);
                $bytes = fread($archive, $length);
                if ($bytes === false || strlen($bytes) !== $length || fread($local, $length) !== $bytes) {
                    return false;
                }
                hash_update($hash, $bytes);
                hash_update($contentHash, $bytes);
                $remaining -= $length;
            }
            $fingerprint = hash_final($contentHash);
            return fread($archive, 1) === '' && fread($local, 1) === ''
                && hash_final($hash) === sprintf('%08x', $crc);
        } finally {
            fclose($archive);
            if (is_resource($local)) {
                fclose($local);
            }
        }
    }
}
