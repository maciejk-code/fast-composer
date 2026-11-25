<?php

namespace FastComposer;

use Composer\Downloader\ZipDownloader;
use Composer\Package\PackageInterface;

/** Keep Composer's reporting; only short-circuit a proven clean ZIP. */
final class StatusZipDownloader extends ZipDownloader
{
    public function getLocalChanges(PackageInterface $package, string $path): ?string
    {
        try {
            if ($this->isIdenticalToCache($package, $path)) {
                if (getenv('FAST_COMPOSER_STATUS_TRACE') === '1') {
                    $this->io->writeError('[fast-composer] compared cached ZIP without extraction: '.$package->getName());
                }
                return null;
            }
        } catch (\Throwable) {
            // Native Composer owns cache misses, unusual archives, and error reporting.
        }
        return parent::getLocalChanges($package, $path);
    }

    private function isIdenticalToCache(PackageInterface $package, string $path): bool
    {
        $path = rtrim($path, '/');
        if (
            $this->cache === null || !$this->cache->isEnabled() || $this->io->isDebug()
            || !is_dir($path) || is_link($path) || !is_writable(dirname($path))
            || !is_writable((string) $this->config->get('vendor-dir').'/composer')
            || file_exists($path.'_compare') || is_link($path.'_compare')
            || !preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}D', $package->getName())
        ) {
            return false;
        }
        $urls = $package->getDistUrls();
        if (!isset($urls[0])) {
            return false;
        }
        // FileDownloader cache-key format for the allowlisted Composer versions.
        $url = $this->processUrl($package, $urls[0]);
        $key = $package->getName().'/'.sha1($url).'.zip';
        $archive = $this->cache->getRoot().$key;
        if (!is_file($archive) || is_link($archive) || !is_readable($archive)) {
            return false;
        }
        $checksum = $package->getDistSha1Checksum();
        if ($checksum !== null && $checksum !== '' && $this->cache->sha1($key) !== $checksum) {
            return false;
        }
        $fingerprints = null;
        if (getenv('FAST_COMPOSER_STATUS_FINGERPRINTS') !== '0' && !$this->config->get('cache-read-only')) {
            try {
                $fingerprints = StatusFingerprintCache::open((string) $this->config->get('cache-dir'));
            } catch (\Throwable) {
                // The streaming comparison remains available without a private cache.
            }
        }
        return StatusZipComparison::identical($archive, $path, $fingerprints);
    }
}
