<?php

namespace FastComposer;

use Composer\Composer;
use Composer\Console\Application;
use Composer\Downloader\GitDownloader;
use Composer\Downloader\ZipDownloader;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\Util\Filesystem;
use Composer\Util\Git;

/** Runtime capability checks before loading classes extending Composer internals. */
final class InstallCompatibility
{
    public static function supportsApplication(): bool
    {
        // A new major or prerelease may change semantics even with identical signatures.
        if (!preg_match('/^2\.\d+\.\d+$/D', Composer::getVersion())) {
            return false;
        }
        try {
            $class = new \ReflectionClass(Application::class);
            $method = $class->getMethod('getComposer');
            return !$class->isFinal()
                && self::canOverride($method, ['bool', '?bool', '?bool'], '?Composer\Composer')
                && defined(InstallerEvents::class.'::PRE_OPERATIONS_EXEC')
                && self::callableMethod(InstallerEvent::class, 'isExecutingOperations', 0)
                && self::callableMethod(InstallerEvent::class, 'getTransaction', 0)
                && self::callableMethod(InstallerEvent::class, 'getComposer', 0)
                && self::callableMethod(InstallerEvent::class, 'getIO', 0);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function supportsPrefetch(): bool
    {
        return self::supportsApplication()
            && self::callableMethod(Git::class, 'fetchRefOrSyncMirror', 4)
            && self::callableMethod(Git::class, 'cleanEnv', 0)
            && self::callableMethod(Git::class, 'setHttpDownloader', 1);
    }

    public static function supportsRemoval(): bool
    {
        try {
            $class = new \ReflectionClass(Filesystem::class);
            return self::supportsApplication() && !$class->isFinal()
                && self::canOverride($class->getMethod('removeDirectory'), ['string'], 'bool', true)
                && self::canOverride($class->getMethod('removeDirectoryAsync'), ['string'], 'React\Promise\PromiseInterface', true);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function supportsGitDownloader(): bool
    {
        return self::supportsRemoval() && self::callableMethod(GitDownloader::class, '__construct', 4);
    }

    public static function supportsZipDownloader(): bool
    {
        return self::supportsRemoval() && self::callableMethod(ZipDownloader::class, '__construct', 7);
    }

    public static function callableMethod(string $class, string $name, int $arguments): bool
    {
        try {
            $method = new \ReflectionMethod($class, $name);
            return $method->isPublic()
                && $method->getNumberOfRequiredParameters() <= $arguments
                && ($method->isVariadic() || $method->getNumberOfParameters() >= $arguments);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Deliberately require the known parameter types; a matching arity alone
     * cannot prevent a fatal incompatible override during class declaration.
     *
     * @param list<string> $types
     */
    public static function canOverride(\ReflectionMethod $method, array $types, string $return, bool $allowUntypedReturn = false): bool
    {
        if (
            !$method->isPublic() || $method->isFinal() || $method->isStatic() || $method->returnsReference()
            || $method->getNumberOfParameters() !== count($types)
            || ($method->getNumberOfRequiredParameters() !== (count($types) === 3 ? 0 : count($types)))
        ) {
            return false;
        }
        foreach ($method->getParameters() as $index => $parameter) {
            if ($parameter->isPassedByReference() || $parameter->isVariadic() || (string) $parameter->getType() !== $types[$index]) {
                return false;
            }
        }
        return (string) $method->getReturnType() === $return || ($allowUntypedReturn && !$method->hasReturnType());
    }
}
