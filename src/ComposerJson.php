<?php
namespace FastComposer;

/**
 * Encodes decoded composer.json data back to JSON the way Composer expects it.
 *
 * json_decode(..., true) cannot tell `{}` from `[]`, so an empty "require": {} would come back
 * as "require": [] and fail Composer's schema validation. Keys that the schema defines as
 * objects are restored as objects when empty.
 */
final class ComposerJson
{
    private const OBJECT_KEYS = [
        'require', 'require-dev', 'conflict', 'replace', 'provide', 'suggest', 'autoload',
        'autoload-dev', 'extra', 'config', 'scripts', 'scripts-descriptions', 'scripts-aliases',
        'support', 'archive',
    ];

    private const NESTED_OBJECT_KEYS = [
        'autoload' => ['psr-4', 'psr-0'],
        'autoload-dev' => ['psr-4', 'psr-0'],
        'config' => ['platform', 'allow-plugins', 'preferred-install', 'github-oauth', 'gitlab-oauth', 'gitlab-token', 'http-basic', 'bearer'],
    ];

    public static function encode(array $data): string
    {
        foreach (self::OBJECT_KEYS as $key) {
            if (!array_key_exists($key, $data) || !is_array($data[$key])) {
                continue;
            }
            foreach (self::NESTED_OBJECT_KEYS[$key] ?? [] as $nested) {
                if (($data[$key][$nested] ?? null) === []) {
                    $data[$key][$nested] = new \stdClass();
                }
            }
            if ($data[$key] === []) {
                $data[$key] = new \stdClass();
            }
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
