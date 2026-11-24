<?php

namespace FastComposer;

use Composer\Cache;
use Composer\Composer;
use Composer\Downloader\GitDownloader;
use Composer\Downloader\ZipDownloader;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

/** Install the scoped filesystem only in the supported native downloaders. */
final class AsyncPackageRemoval
{
    /**
     * @param list<PackageInterface> $packages
     * @param list<PackageInterface> $installs
     */
    public static function attach(string $root, Composer $composer, IOInterface $io, array $packages, array $installs = []): ?DeferredPackageFilesystem
    {
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) || !is_executable('/bin/rm')) {
            return null;
        }
        $root = realpath($root);
        $process = $composer->getLoop()->getProcessExecutor();
        if ($root === false || $root === '/' || $process === null) {
            return null;
        }
        $filesystem = new DeferredPackageFilesystem($root, $process);
        $manager = $composer->getDownloadManager();
        $config = $composer->getConfig();
        $git = null;
        $zip = null;
        // Do not replace custom downloaders registered by plugins.
        if (InstallCompatibility::supportsGitDownloader() && get_class($manager->getDownloader('git')) === GitDownloader::class) {
            $git = new GitDownloader($io, $config, $process, $filesystem);
        }
        if (InstallCompatibility::supportsZipDownloader() && get_class($manager->getDownloader('zip')) === ZipDownloader::class) {
            $cache = null;
            if ($config->get('cache-files-ttl') > 0) {
                $cache = new Cache($io, (string) $config->get('cache-files-dir'), 'a-z0-9_./');
                $cache->setReadOnly((bool) $config->get('cache-read-only'));
            }
            $zip = new ZipDownloader(
                $io,
                $config,
                $composer->getLoop()->getHttpDownloader(),
                $composer->getEventDispatcher(),
                $cache,
                $filesystem,
                $process
            );
        }
        foreach ([[$packages, true], [$installs, false]] as [$candidates, $wholePackage]) {
            foreach ($candidates as $package) {
                try {
                    $path = $composer->getInstallationManager()->getInstallPath($package);
                    if (is_string($path) && $path !== '') {
                        // For new installs, only optimize node_modules removal, not the
                        // whole untracked package path. Composer chooses what to remove.
                        $filesystem->allow($path, $wholePackage);
                    }
                } catch (\Throwable) {
                    // An installer plugin may not be available until later in the transaction.
                    // Do not guess a path or disable acceleration for unrelated packages.
                }
            }
        }
        if ($git !== null) {
            $manager->setDownloader('git', $git);
        }
        if ($zip !== null) {
            $manager->setDownloader('zip', $zip);
        }
        return $filesystem;
    }
}
