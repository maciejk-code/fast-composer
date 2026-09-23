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

    /**
     * The real composer.json after `require`: the original text with only the changed links
     * (and allow-plugins decisions) edited, exactly like Composer's RequireCommand does with its
     * JsonManipulator, so indentation, key order and inline arrays are kept.
     *
     * @param string $original current composer.json text
     * @param array $before decoded current composer.json
     * @param array $after decoded composer.json Composer produced (require sections, allow-plugins)
     */
    public static function applyRequireChanges(string $original, array $before, array $after, bool $sortPackages): string
    {
        $edited = InProcessComposer::loadClasses() ? self::manipulate($original, $before, $after, $sortPackages) : null;
        if ($edited !== null) {
            return $edited;
        }

        // Composer itself rewrites the whole file when a clean edit is impossible; keep at
        // least the file's indentation.
        $data = $before;
        foreach (['require', 'require-dev'] as $key) {
            if (isset($after[$key])) {
                $data[$key] = $after[$key];
            } else {
                unset($data[$key]);
            }
        }
        if (array_key_exists('allow-plugins', $after['config'] ?? [])) {
            $data['config']['allow-plugins'] = $after['config']['allow-plugins'];
        }
        return self::encode($data, self::detectIndent($original));
    }

    private static function manipulate(string $original, array $before, array $after, bool $sortPackages): ?string
    {
        try {
            $manipulator = new \Composer\Json\JsonManipulator($original);
            foreach (['require' => 'require-dev', 'require-dev' => 'require'] as $key => $otherKey) {
                foreach ($after[$key] ?? [] as $package => $constraint) {
                    if (($before[$key][$package] ?? null) === $constraint) {
                        continue;
                    }
                    if (!$manipulator->addLink($key, $package, $constraint, $sortPackages)) {
                        return null;
                    }
                    if (!$manipulator->removeSubNode($otherKey, $package)) {
                        return null;
                    }
                }
            }
            foreach (['require', 'require-dev'] as $key) {
                foreach (array_keys($before[$key] ?? []) as $package) {
                    if (!isset($after[$key][$package]) && !$manipulator->removeSubNode($key, $package)) {
                        return null;
                    }
                }
                if (!isset($after[$key])) {
                    $manipulator->removeMainKeyIfEmpty($key);
                }
            }
            $allowPlugins = $after['config']['allow-plugins'] ?? null;
            if ($allowPlugins !== null && $allowPlugins !== ($before['config']['allow-plugins'] ?? null)
                && !$manipulator->addConfigSetting('allow-plugins', $allowPlugins)) {
                return null;
            }
            return $manipulator->getContents();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function detectIndent(string $json): string
    {
        return preg_match('/^([ \t]+)"/m', $json, $m) ? $m[1] : '    ';
    }

    public static function encode(array $data, string $indent = '    '): string
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

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($indent !== '    ') {
            $json = (string) preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat($indent, intdiv(strlen($m[0]), 4)), $json);
        }
        return $json."\n";
    }
}
