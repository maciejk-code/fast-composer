<?php

namespace FastComposer;

/**
 * Cache original archive fingerprints, never installed file metadata. A cache hit
 * requires the SHA-256 of the entire current archive and a private, owner-only manifest.
 */
final class StatusFingerprintCache
{
    private const MAX_BYTES = 16 * 1024 * 1024;

    private function __construct(private readonly string $directory)
    {
    }

    public static function open(string $base): ?self
    {
        if (!function_exists('posix_geteuid') || $base === '' || !is_dir($base)) {
            return null;
        }
        $base = realpath($base);
        if ($base === false || $base === '/' || !self::safe($base, false)) {
            return null;
        }
        $directory = $base.'/fast-composer-status-v1';
        if (!is_dir($directory) && !@mkdir($directory, 0700)) {
            return null;
        }
        return self::safe($directory, true) ? new self($directory) : null;
    }

    /**
     * @return array<string, string>|null SHA-256 of each uncompressed original file
     */
    public function load(string $archive, string $digest): ?array
    {
        $path = $this->path($archive);
        try {
            if (!self::safe($this->directory, true) || !self::safe($path, true, true)) {
                return null;
            }
            $size = filesize($path);
            if ($size === false || $size > self::MAX_BYTES) {
                return null;
            }
            $raw = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
            if ($raw === false || strlen($raw) > self::MAX_BYTES) {
                return null;
            }
            $data = JsonFile::decode($raw);
            if (($data['archive'] ?? null) !== $digest || !is_array($data['hashes'] ?? null)) {
                return null;
            }
            $hashes = $data['hashes'];
            if (
                count($hashes) > 50000
                || ($data['integrity'] ?? null) !== hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR))
            ) {
                return null;
            }
            foreach ($hashes as $name => $hash) {
                if (!is_string($name) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    return null;
                }
            }
            return $hashes;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, string> $hashes */
    public function save(string $archive, string $digest, array $hashes): void
    {
        try {
            if (!self::safe($this->directory, true) || hash_file('sha256', $archive) !== $digest) {
                return;
            }
            $contents = json_encode([
                'archive' => $digest,
                'hashes' => $hashes,
                'integrity' => hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR)),
            ], JSON_THROW_ON_ERROR);
            if (strlen($contents) <= self::MAX_BYTES) {
                JsonFile::atomicWrite($this->path($archive), $contents, true);
            }
        } catch (\Throwable) {
            // A cache write is never a prerequisite for native status.
        }
    }

    private function path(string $archive): string
    {
        // Overwrite the previous fingerprint for this cache slot, rather than accumulating
        // a manifest on every change to a mutable dist URL.
        return $this->directory.'/'.hash('sha256', $archive).'.json';
    }

    private static function safe(string $path, bool $private, bool $file = false): bool
    {
        clearstatcache(true, $path);
        if (is_link($path) || ($file ? !is_file($path) : !is_dir($path))) {
            return false;
        }
        $stat = @stat($path);
        return $stat !== false && $stat['uid'] === posix_geteuid()
            && ($stat['mode'] & ($private ? 0077 : 0022)) === 0;
    }
}
