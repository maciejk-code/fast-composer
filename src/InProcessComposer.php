<?php

namespace FastComposer;

/**
 * Load the installed Composer's own classes for VCS metadata and JSON manipulation.
 * Dependency resolution itself is delegated to Composer's CLI subprocess.
 */
final class InProcessComposer
{
    /** @var null|false|string false means the executable has not yet been inspected. */
    private static $located = false;

    public static function loadClasses(): bool
    {
        if (class_exists(\Composer\Json\JsonManipulator::class, false)) {
            return true;
        }
        if (!extension_loaded('phar') || self::locatePhar() === null) {
            return false;
        }
        try {
            require_once 'phar://composer.phar/src/bootstrap.php';
        } catch (\Throwable) {
            return false;
        }
        return class_exists(\Composer\Json\JsonManipulator::class);
    }

    /** Only use classes from the first Composer on PATH, never a different installation. */
    private static function locatePhar(): ?string
    {
        if (self::$located !== false) {
            return self::$located;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, '/').'/composer';
            if ($dir === '' || !is_file($candidate) || !is_executable($candidate)) {
                continue;
            }
            $path = realpath($candidate);
            if ($path === false) {
                break;
            }
            try {
                if (
                    \Phar::loadPhar($path, 'composer.phar')
                    && is_file('phar://composer.phar/src/Composer/Console/Application.php')
                    && is_file('phar://composer.phar/src/bootstrap.php')
                ) {
                    return self::$located = $path;
                }
            } catch (\Throwable) {
            }
            break; // Never skip a non-phar composer and load classes from a later executable.
        }
        return self::$located = null;
    }
}
