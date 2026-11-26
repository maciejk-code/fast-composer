<?php

namespace FastComposer;

use Composer\Downloader\GitDownloader;
use Composer\Package\PackageInterface;
use Composer\Util\Git;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\Process as ChildProcess;

/** Speculative local-only Git checks. All reporting and remote checks stay in Composer. */
final class StatusGitDownloader extends GitDownloader
{
    /** @var list<string> */
    private array $pending = [];
    /** @var array<string, true> */
    private array $clean = [];
    /** @var array<string, string> HEAD of clean checkouts with no unpushed changes */
    private array $synced = [];

    /** @param list<string> $paths */
    public function setStatusPaths(array $paths): void
    {
        $this->pending = $paths;
    }

    public function getLocalChanges(PackageInterface $package, string $path): ?string
    {
        if ($this->pending !== []) {
            $paths = $this->pending;
            $this->pending = [];
            try {
                $this->scan($paths);
            } catch (\Throwable) {
                $this->clean = [];
                $this->synced = [];
            }
        }
        if (isset($this->clean[$path])) {
            unset($this->clean[$path]);
            return null;
        }
        return parent::getLocalChanges($package, $path);
    }

    /**
     * The native status command guesses the version again whenever this method
     * returns a reference. If the first native guess already equals the locked
     * reference, the second guess cannot report a variation in a stable checkout.
     * This adapter is installed exclusively for status, not install/update.
     */
    public function getVcsReference(PackageInterface $package, string $path): ?string
    {
        $locked = match ($package->getInstallationSource()) {
            'source' => $package->getSourceReference(),
            'dist' => $package->getDistReference(),
            default => null,
        };
        if (
            getenv('FAST_COMPOSER_STATUS_VERSION') !== '0'
            && $locked !== null && ($this->synced[$path] ?? null) === $locked
        ) {
            if (getenv('FAST_COMPOSER_STATUS_TRACE') === '1') {
                $this->io->writeError('[fast-composer] proved locked Git HEAD without version guess: '.$package->getName());
            }
            return null;
        }
        $reference = parent::getVcsReference($package, $path);
        if (
            getenv('FAST_COMPOSER_STATUS_VERSION') !== '0'
            && $reference !== null && $locked !== null && $reference === $locked
            && preg_match('/^[a-f0-9]{40}$/D', $reference)
            && !is_link($path) && is_dir($path.'/.git') && !is_link($path.'/.git')
        ) {
            if (getenv('FAST_COMPOSER_STATUS_TRACE') === '1') {
                $this->io->writeError('[fast-composer] skipped duplicate version guess: '.$package->getName());
            }
            return null;
        }
        return $reference;
    }

    public function getUnpushedChanges(PackageInterface $package, string $path): ?string
    {
        if (isset($this->synced[$path])) {
            unset($this->synced[$path]);
            if (getenv('FAST_COMPOSER_STATUS_TRACE') === '1') {
                $this->io->writeError('[fast-composer] proved synchronized Git refs: '.$package->getName());
            }
            return null;
        }
        return parent::getUnpushedChanges($package, $path);
    }

    /** @param list<string> $paths */
    private function scan(array $paths): void
    {
        Git::cleanEnv($this->process);
        // Do not run concurrently against a shared/redirected Git index or worktree.
        foreach (['GIT_INDEX_FILE', 'GIT_COMMON_DIR', 'GIT_OBJECT_DIRECTORY', 'GIT_CONFIG_PARAMETERS', 'GIT_CONFIG_COUNT', 'GIT_NAMESPACE', 'GIT_REPLACE_REF_BASE', 'GIT_ALTERNATE_OBJECT_DIRECTORIES'] as $name) {
            if (getenv($name) !== false) {
                return;
            }
        }
        $jobs = getenv('COMPOSER_MAX_PARALLEL_PROCESSES');
        $limit = is_numeric($jobs) ? max(1, min(8, (int) $jobs)) : 8;
        if ($limit < 2 || count($paths) < 2) {
            return;
        }
        foreach (array_chunk($paths, $limit) as $batch) {
            $running = [];
            try {
                foreach ($batch as $path) {
                    if (is_link($path) || !is_dir($path.'/.git') || is_link($path.'/.git')) {
                        continue;
                    }
                    $child = new ChildProcess(
                        ['git', 'status', '--porcelain', '--untracked-files=no'],
                        $path,
                        null,
                        null,
                        ProcessExecutor::getTimeout()
                    );
                    $running[$path] = $child;
                    $child->start();
                }
                foreach ($running as $path => $child) {
                    if ($child->wait() === 0 && trim($child->getOutput()) === '' && trim($child->getErrorOutput()) === '') {
                        $this->clean[$path] = true;
                    }
                }
            } finally {
                foreach ($running as $child) {
                    if ($child->isRunning()) {
                        $child->stop(0.1);
                    }
                }
            }
        }
        if (getenv('FAST_COMPOSER_STATUS_REFS') !== '0') {
            $this->scanRefs(array_keys($this->clean), $limit);
        }
        if (getenv('FAST_COMPOSER_STATUS_TRACE') === '1') {
            $this->io->writeError(sprintf('[fast-composer] parallel Git status proved %d clean checkouts', count($this->clean)));
        }
    }

    /** @param list<string> $paths */
    private function scanRefs(array $paths, int $limit): void
    {
        foreach (array_chunk($paths, max(1, $limit)) as $batch) {
            $running = [];
            try {
                foreach ($batch as $path) {
                    $child = new ChildProcess(
                        ['git', 'show-ref', '--head', '-d'],
                        $path,
                        null,
                        null,
                        ProcessExecutor::getTimeout()
                    );
                    $running[$path] = $child;
                    $child->start();
                }
                foreach ($running as $path => $child) {
                    if ($child->wait() !== 0 || trim($child->getErrorOutput()) !== '') {
                        continue;
                    }
                    $head = StatusGitRefs::synchronizedHead($child->getOutput(), $path);
                    if ($head !== null) {
                        $this->synced[$path] = $head;
                    }
                }
            } finally {
                foreach ($running as $child) {
                    if ($child->isRunning()) {
                        $child->stop(0.1);
                    }
                }
            }
        }
    }
}
