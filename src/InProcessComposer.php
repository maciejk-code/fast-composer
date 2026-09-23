<?php
namespace FastComposer;

/**
 * Runs the Composer phar inside the current PHP process.
 *
 * The solve itself is identical to `composer ...` in a subprocess; this only avoids paying
 * for a second PHP interpreter start-up. It mirrors what Composer's own bin/composer does
 * before handing over to its Console Application, and declines (returns null) whenever the
 * environment is not the plain phar setup that makes that equivalence obvious.
 */
final class InProcessComposer
{
    /** @param list<string> $args Composer arguments, without the leading "composer". */
    public static function run(array $args, string $cwd, array $env): ?int
    {
        $phar = self::composerPhar();
        if ($phar === null || getcwd() !== $cwd) {
            return null;
        }

        $previousEnv = [];
        foreach ($env + ['COMPOSER_BINARY' => $phar] as $name => $value) {
            $previousEnv[$name] = [getenv($name), $_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            putenv($name.'='.$value);
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        }
        $previousLocale = setlocale(LC_ALL, 0);
        $previousReporting = error_reporting();
        $previousMemory = ini_get('memory_limit');
        $previousDisplayErrors = ini_get('display_errors');

        try {
            require_once 'phar://composer.phar/src/bootstrap.php';
            if (!class_exists(\Composer\Console\Application::class)) {
                return null;
            }

            setlocale(LC_ALL, 'C');
            error_reporting(-1);
            self::applyComposerIniDefaults();
            \Composer\Util\ErrorHandler::register();

            try {
                $application = new \Composer\Console\Application();
                $application->setAutoExit(false);
                $application->setCatchExceptions(true);
                return $application->run(new \Symfony\Component\Console\Input\ArgvInput(array_merge(['composer'], $args)));
            } finally {
                restore_error_handler();
            }
        } finally {
            foreach ($previousEnv as $name => [$value, $server, $envValue]) {
                $value === false ? putenv($name) : putenv($name.'='.$value);
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
                if ($envValue === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $envValue;
                }
            }
            if (is_string($previousLocale)) {
                setlocale(LC_ALL, $previousLocale);
            }
            error_reporting($previousReporting);
            if ($previousMemory !== false) {
                @ini_set('memory_limit', $previousMemory);
            }
            if ($previousDisplayErrors !== false) {
                @ini_set('display_errors', $previousDisplayErrors);
            }
        }
    }

    /** Path of the Composer phar found on PATH, or null when in-process execution is not safe. */
    public static function composerPhar(): ?string
    {
        if (getenv('FAST_COMPOSER_IN_PROCESS') === '0'
            || PHP_SAPI !== 'cli'
            || PHP_OS_FAMILY === 'Windows'
            || !extension_loaded('phar')
            // Composer would restart itself without Xdebug; a subprocess keeps that behavior.
            || extension_loaded('xdebug')
            || (!extension_loaded('iconv') && !extension_loaded('mbstring'))
            // Only one Composer may live in a process.
            || class_exists(\Composer\Console\Application::class, false)
        ) {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/').'/composer';
            if (!is_file($candidate) || !is_executable($candidate)) {
                continue;
            }
            $path = realpath($candidate);
            if ($path === false) {
                return null;
            }
            // The first `composer` on PATH is what a subprocess would run: accept it only if
            // it is a genuine Composer phar, otherwise fall back to the subprocess. The alias
            // is the one Composer's own stub maps.
            try {
                $isComposerPhar = \Phar::loadPhar($path, 'composer.phar')
                    && is_file('phar://composer.phar/src/Composer/Console/Application.php')
                    && is_file('phar://composer.phar/src/bootstrap.php');
            } catch (\Throwable) {
                $isComposerPhar = false;
            }
            return $isComposerPhar ? $path : null;
        }

        return null;
    }

    private static function applyComposerIniDefaults(): void
    {
        $logsToSapiDefault = ('' === ini_get('error_log') && (bool) ini_get('log_errors'));
        @ini_set('display_errors', $logsToSapiDefault ? '0' : 'stderr');

        $memoryLimit = getenv('COMPOSER_MEMORY_LIMIT');
        if (is_string($memoryLimit) && $memoryLimit !== '') {
            @ini_set('memory_limit', $memoryLimit);
            return;
        }

        $current = trim((string) ini_get('memory_limit'));
        if ($current !== '-1' && self::bytes($current) < 1024 * 1024 * 1536) {
            @ini_set('memory_limit', '1536M');
        }
    }

    private static function bytes(string $value): int
    {
        $unit = strtolower(substr($value, -1));
        $bytes = (int) $value;
        return match ($unit) {
            'g' => $bytes * 1024 ** 3,
            'm' => $bytes * 1024 ** 2,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }
}
