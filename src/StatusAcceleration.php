<?php

namespace FastComposer;

use Composer\Cache;
use Composer\Composer;
use Composer\Downloader\ZipDownloader;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;

/** Scope optimization to reviewed versions and unmodified downloader pipelines. */
final class StatusAcceleration
{
    public static function supported(): bool
    {
        return in_array(Composer::getVersion(), ['2.8.12', '2.9.5', '2.10.3'], true)
            && InstallCompatibility::supportsApplication()
            && class_exists(\ZipArchive::class)
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
        if (
            get_class($manager->getDownloader('zip')) !== ZipDownloader::class
            || $process === null || $config->get('cache-files-ttl') <= 0
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
}
