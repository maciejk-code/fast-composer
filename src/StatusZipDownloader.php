<?php

namespace FastComposer;

use Composer\Downloader\ZipDownloader;
use Composer\Package\PackageInterface;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\Process as ChildProcess;

/** Keep Composer's reporting; only short-circuit a proven clean ZIP. */
final class StatusZipDownloader extends ZipDownloader
{
    /** @var list<array{PackageInterface, string}> */
    private array $pending = [];
    /** @var array<string, array{string, bool}> */
    private array $checked = [];

    /** @param list<array{PackageInterface, string}> $packages */
    public function setStatusPackages(array $packages): void
    {
        $this->pending = $packages;
    }

    public function getLocalChanges(PackageInterface $package, string $path): ?string
    {
        try {
            if ($this->pending !== []) {
                $pending = $this->pending;
                $this->pending = [];
                try {
                    $this->scan($pending);
                } catch (\Throwable) {
                    $this->checked = [];
                }
            }
            $archive = $this->candidate($package, $path);
            $checked = $this->checked[$path] ?? null;
            unset($this->checked[$path]);
            $identical = $archive !== null && ($checked !== null && $checked[0] === $archive
                ? $checked[1] : $this->compare($archive, $path));
            if ($identical) {
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

    /**
     * Run isolated, read-only comparisons in a small number of PHP workers.
     * Unknown, dirty or failed candidates always use the original sequential path.
     * Concurrent external changes to vendor/ or Composer's cache are unsupported.
     *
     * @param list<array{PackageInterface, string}> $packages
     */
    private function scan(array $packages): void
    {
        $setting = getenv('COMPOSER_MAX_PARALLEL_PROCESSES');
        $limit = is_numeric($setting) ? max(1, min(4, (int) $setting)) : 4;
        if (getenv('FAST_COMPOSER_STATUS_ZIP_PARALLEL') === '0' || $limit < 2 || count($packages) < 8) {
            return;
        }
        $tasks = [];
        $paths = [];
        foreach ($packages as [$package, $path]) {
            $archive = $this->candidate($package, $path);
            $real = realpath($path);
            if ($real === false) {
                continue;
            }
            foreach ($paths as $other) {
                if (
                    $real === $other || str_starts_with($real, $other.'/') || str_starts_with($other, $real.'/')
                    || $real.'_compare' === $other || $other.'_compare' === $real
                ) {
                    return;
                }
            }
            $paths[] = $real;
            if ($archive !== null) {
                $tasks[] = [$archive, $path];
            }
        }
        if (count($tasks) < 8) {
            return;
        }
        $cacheDir = $this->fingerprintDirectory();
        $running = [];
        try {
            foreach (array_chunk($tasks, (int) ceil(count($tasks) / $limit)) as $batch) {
                $child = new ChildProcess(
                    [PHP_BINARY, __DIR__.'/../bin/status-zip-worker.php'],
                    null,
                    null,
                    json_encode(['tasks' => $batch, 'cache' => $cacheDir], JSON_THROW_ON_ERROR),
                    ProcessExecutor::getTimeout()
                );
                $running[] = [$child, $batch];
                $child->start();
            }
            foreach ($running as [$child, $batch]) {
                if ($child->wait() !== 0 || trim($child->getErrorOutput()) !== '') {
                    continue;
                }
                $results = json_decode($child->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($results) || count($results) !== count($batch)) {
                    continue;
                }
                foreach ($batch as $i => [$archive, $path]) {
                    if (is_bool($results[$i] ?? null)) {
                        $this->checked[$path] = [$archive, $results[$i]];
                    }
                }
            }
        } finally {
            foreach ($running as [$child]) {
                if ($child->isRunning()) {
                    $child->stop(0.1);
                }
            }
        }
        if (getenv('FAST_COMPOSER_STATUS_TRACE') === '1') {
            $this->io->writeError(sprintf('[fast-composer] parallel ZIP proved %d clean packages', count(array_filter($this->checked, static fn (array $entry): bool => $entry[1]))));
        }
    }

    private function candidate(PackageInterface $package, string $path): ?string
    {
        $path = rtrim($path, '/');
        if (
            $this->cache === null || !$this->cache->isEnabled() || $this->io->isDebug()
            || !is_dir($path) || is_link($path) || !is_writable(dirname($path))
            || !is_writable((string) $this->config->get('vendor-dir').'/composer')
            || file_exists($path.'_compare') || is_link($path.'_compare')
            || !preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}D', $package->getName())
        ) {
            return null;
        }
        $urls = $package->getDistUrls();
        if (!isset($urls[0])) {
            return null;
        }
        // FileDownloader cache-key format for the allowlisted Composer versions.
        $url = $this->processUrl($package, $urls[0]);
        $key = $package->getName().'/'.sha1($url).'.zip';
        $archive = $this->cache->getRoot().$key;
        if (!is_file($archive) || is_link($archive) || !is_readable($archive)) {
            return null;
        }
        $checksum = $package->getDistSha1Checksum();
        if ($checksum !== null && $checksum !== '' && $this->cache->sha1($key) !== $checksum) {
            return null;
        }
        return $archive;
    }

    private function fingerprintDirectory(): ?string
    {
        return getenv('FAST_COMPOSER_STATUS_FINGERPRINTS') !== '0' && !$this->config->get('cache-read-only')
            ? (string) $this->config->get('cache-dir')
            : null;
    }

    private function compare(string $archive, string $path): bool
    {
        $fingerprints = null;
        $directory = $this->fingerprintDirectory();
        if ($directory !== null) {
            try {
                $fingerprints = StatusFingerprintCache::open($directory);
            } catch (\Throwable) {
                // The streaming comparison remains available without a private cache.
            }
        }
        return StatusZipComparison::identical($archive, $path, $fingerprints);
    }
}
