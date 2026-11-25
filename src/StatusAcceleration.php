<?php

namespace FastComposer;

use Composer\Cache;
use Composer\Composer;
use Composer\Downloader\ZipDownloader;
use Composer\Downloader\GitDownloader;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;

/** Scope optimization to reviewed versions and unmodified downloader pipelines. */
final class StatusAcceleration
{
    public static function supported(): bool
    {
        return in_array(Composer::getVersion(), ['2.8.12', '2.9.5', '2.10.3'], true)
            && InstallCompatibility::supportsApplication()
            && in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true);
    }

    public static function attach(Composer $composer, IOInterface $io): void
    {
        // Plugins can change cache keys, downloads, paths or hooks.
        if (
            !self::supported() || $io->isDebug()
            || $composer->getPluginManager()->getRegisteredPlugins() !== []
            || $composer->getPackage()->getScripts() !== []
        ) {
            return;
        }
        $manager = $composer->getDownloadManager();
        $config = $composer->getConfig();
        $process = $composer->getLoop()->getProcessExecutor();
        if ($process === null) {
            return;
        }
        self::attachGit($composer, $io);
        if (
            !class_exists(\ZipArchive::class)
            || get_class($manager->getDownloader('zip')) !== ZipDownloader::class
            || $config->get('cache-files-ttl') <= 0
        ) {
            return;
        }
        $cache = new Cache($io, (string) $config->get('cache-files-dir'), 'a-z0-9_./');
        $cache->setReadOnly((bool) $config->get('cache-read-only'));
        $manager->setDownloader('zip', new StatusZipDownloader(
            $io,
            $config,
            $composer->getLoop()->getHttpDownloader(),
            $composer->getEventDispatcher(),
            $cache,
            new Filesystem($process),
            $process
        ));
    }

    private static function attachGit(Composer $composer, IOInterface $io): void
    {
        $manager = $composer->getDownloadManager();
        $process = $composer->getLoop()->getProcessExecutor();
        if (
            $process === null || getenv('FAST_COMPOSER_STATUS_GIT') === '0'
            || get_class($manager->getDownloader('git')) !== GitDownloader::class
        ) {
            return;
        }
        $paths = [];
        $realPaths = [];
        foreach ($composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {
            if ($manager->getDownloaderForPackage($package) !== $manager->getDownloader('git')) {
                continue;
            }
            $path = $composer->getInstallationManager()->getInstallPath($package);
            if ($path === null || is_link($path) || !is_dir($path.'/.git') || is_link($path.'/.git')) {
                continue;
            }
            $real = realpath($path);
            if ($real === false) {
                continue;
            }
            foreach ($realPaths as $other) {
                if ($real === $other || str_starts_with($real, $other.'/') || str_starts_with($other, $real.'/')) {
                    // Shared/nested paths could observe each other's Git operations.
                    return;
                }
            }
            $paths[] = $path;
            $realPaths[] = $real;
        }
        if (count($paths) < 2) {
            return;
        }
        $git = new StatusGitDownloader($io, $composer->getConfig(), $process, new Filesystem($process));
        $git->setStatusPaths($paths);
        $manager->setDownloader('git', $git);
    }
}
