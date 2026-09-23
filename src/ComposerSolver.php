<?php
namespace FastComposer;

/** Runs Composer (the real solver) against the temporary root composer.json. */
final class ComposerSolver
{
    /**
     * @param list<string> $args Composer arguments, e.g. ['update', 'vendor/pkg', '--no-install']
     * @param list<string> $packageFiles the snapshot's packages.json files
     * @return int Composer's exit code
     */
    public static function run(array $args, string $root, string $composerFile, array $rootConfig, array $packageFiles): int
    {
        $env = ['COMPOSER' => $composerFile] + self::environment($rootConfig, $root, $packageFiles);

        $code = InProcessComposer::run($args, $root, $env);
        if ($code !== null) {
            return $code;
        }

        $previous = [];
        try {
            foreach ($env as $name => $value) {
                $previous[$name] = getenv($name);
                putenv($name.'='.$value);
            }
            [$code] = Process::run(array_merge(['composer'], $args), $root, true);
            return $code;
        } finally {
            foreach ($previous as $name => $value) {
                $value === false ? putenv($name) : putenv($name.'='.$value);
            }
        }
    }

    /**
     * Environment that removes work from the inner Composer run without changing its result.
     *
     * @return array<string,string>
     */
    private static function environment(array $rootConfig, string $root, array $packageFiles): array
    {
        $env = [];

        // Composer guesses the root package version by probing git/hg/fossil/svn in the project,
        // which costs several subprocesses. The lock file never records the root version, so it
        // only affects the solve when something references the root package itself. Pin it
        // only when nothing can: no "self.version" constraints and no package mentioning the
        // root package name.
        if (getenv('COMPOSER_ROOT_VERSION') === false && !isset($rootConfig['version'])) {
            $rootName = is_string($rootConfig['name'] ?? null) ? strtolower($rootConfig['name']) : null;
            $composerJson = (string) @file_get_contents($root.'/composer.json');
            $safe = !str_contains($composerJson, 'self.version');
            if ($safe && $rootName !== null) {
                foreach (array_merge($packageFiles, [$root.'/composer.lock']) as $path) {
                    $contents = is_file($path) ? @file_get_contents($path) : '';
                    if ($contents === false || str_contains(strtolower($contents), '"'.$rootName.'"')) {
                        $safe = false;
                        break;
                    }
                }
            }
            if ($safe) {
                $env['COMPOSER_ROOT_VERSION'] = 'dev-main';
            }
        }

        // Without a terminal, Symfony Console shells out to `stty` twice to size output.
        if (getenv('COLUMNS') === false && !(function_exists('stream_isatty') && @stream_isatty(STDOUT))) {
            $env['COLUMNS'] = '120';
            $env['LINES'] = '50';
        }

        return $env;
    }
}
