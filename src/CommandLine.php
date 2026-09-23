<?php
namespace FastComposer;

/** Interpreting the Composer-style arguments passed to fast-composer. */
final class CommandLine
{
    public static function hasFlag(array $args, string $flag): bool
    {
        foreach ($args as $arg) {
            if ($arg === $flag || str_starts_with((string) $arg, $flag.'=')) {
                return true;
            }
        }
        return false;
    }

    /** Fast operations are lock-only. */
    public static function lockOnly(array $args): array
    {
        if (!self::hasFlag($args, '--no-install')) {
            $args[] = '--no-install';
        }
        return $args;
    }

    /**
     * Package arguments ("vendor/name", "vendor/name:constraint", wildcards), options excluded.
     *
     * @return list<string>
     */
    public static function packageArguments(array $args): array
    {
        $result = [];
        foreach ($args as $arg) {
            if (!is_string($arg) || $arg === '' || str_starts_with($arg, '-')) {
                continue;
            }
            $name = preg_split('/[:= ]/', $arg, 2)[0];
            if (str_contains($name, '/')) {
                $result[] = $arg;
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * Split "vendor/name:constraint" (also "=" or a space, as Composer accepts).
     *
     * @return array{0:string,1:?string}
     */
    public static function splitPackageSpec(string $spec): array
    {
        $parts = preg_split('/[:= ]/', trim($spec), 2) ?: [$spec];
        $constraint = isset($parts[1]) ? trim($parts[1]) : null;
        return [strtolower($parts[0]), $constraint === '' ? null : $constraint];
    }

    /** The branch of an explicit "dev-<branch>" constraint, if it is one. */
    public static function explicitDevBranch(string $constraint): ?string
    {
        $constraint = trim($constraint);
        if (!str_starts_with($constraint, 'dev-')) {
            return null;
        }

        $token = preg_split('/\s+/', $constraint, 2)[0];
        $token = preg_replace('/@[^@]+$/', '', $token);
        if (!is_string($token) || !str_starts_with($token, 'dev-') || strlen($token) <= 4) {
            return null;
        }
        return substr($token, 4);
    }
}
