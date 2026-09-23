<?php
namespace FastComposer;

/** Reading, atomically writing and canonicalizing JSON data files. */
final class JsonFile
{
    public static function read(string $path): array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new \RuntimeException("Cannot read $path");
        }
        return self::decode($raw, $path);
    }

    public static function decode(string $json, string $source = 'JSON'): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON: $source");
        }
        return $data;
    }

    public static function readIfExists(string $path, array $default = []): array
    {
        return is_file($path) ? self::read($path) : $default;
    }

    /**
     * Replace $path atomically (write a sibling temp file, then rename). $private restricts the
     * file to the current user, for cache files.
     */
    public static function atomicWrite(string $path, string $contents, bool $private = false): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory $dir");
        }
        $tmp = $path.'.tmp-fast-composer-'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write $tmp");
        }
        if ($private) {
            @chmod($tmp, 0600);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot replace $path");
        }
    }

    /** Recursively sort object keys so two decoded documents can be compared with ===. */
    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }
        return $value;
    }
}
