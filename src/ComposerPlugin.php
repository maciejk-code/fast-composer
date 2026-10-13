<?php

namespace FastComposer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;

/**
 * Opt-in injection of warmed VCS metadata into the REAL Composer update command.
 *
 * Unlike the two-phase wrapper, Composer still owns the update solver, installation,
 * plugin events and update lifecycle scripts. If safety checks fail, it runs normally.
 */
final class ComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [PluginEvents::COMMAND => 'onCommand'];
    }

    public function onCommand(CommandEvent $event): void
    {
        if ($event->getCommandName() !== 'update' || getenv('FAST_COMPOSER_PLUGIN') !== '1') {
            return;
        }

        // Do not handle alternate root manifests: snapshots are keyed to the normal project.
        if (getenv('COMPOSER') !== false && getenv('COMPOSER') !== '') {
            return;
        }

        $root = getcwd();
        if ($root === false || !is_file($root.'/composer.json')) {
            return;
        }

        try {
            $config = JsonFile::read($root.'/composer.json');
            if (!self::vcsFirst($config)) {
                $this->debug('VCS repositories are not a leading contiguous block; using normal Composer');
                return;
            }

            $declared = RootConfig::vcsRepositories($config);
            if ($declared === []) {
                return;
            }

            $snapshot = new Snapshot($root);
            $state = $snapshot->load();
            $maxAge = $this->maximumAge();
            if (!$state || !$snapshot->isCompatible($state, $config)
                || !$snapshot->isFresh($state, $maxAge)
            ) {
                $this->debug('No compatible fresh VCS snapshot; using normal Composer');
                return;
            }

            $packages = [];
            $packageOrigins = [];
            foreach ($declared as $repo) {
                // Repository filters change precedence/canonical behavior; do not accelerate them.
                if (array_diff(array_keys($repo), ['type', 'url']) !== []) {
                    $this->debug('VCS repository has advanced options; using normal Composer');
                    return;
                }
                $url = $repo['url'];
                $record = $state['repos'][$url] ?? null;
                if (!is_array($record) || ($record['managed'] ?? false) !== true
                    || empty($record['checked_at']) || empty($record['packages'])
                ) {
                    $this->debug('Incomplete VCS snapshot; using normal Composer');
                    return;
                }
                foreach ($record['packages'] as $package) {
                    if (!is_array($package) || !is_string($package['name'] ?? null)
                        || !is_string($package['version'] ?? null)
                    ) {
                        $this->debug('Invalid VCS package metadata; using normal Composer');
                        return;
                    }
                    $name = $package['name'];
                    if (isset($packageOrigins[$name]) && $packageOrigins[$name] !== $url) {
                        $this->debug('Overlapping VCS package names; using normal Composer');
                        return;
                    }
                    $packageOrigins[$name] = $url;
                    $packages[] = $package;
                }
            }

            if ($packages === []) {
                return;
            }

            // A package repository is canonical. Composer will not query lower-priority VCS
            // repositories for managed package names, while its normal update lifecycle stays intact.
            $manager = $this->composer->getRepositoryManager();
            $repository = $manager->createRepository('package', ['package' => $packages], 'fast-composer-snapshot');
            $manager->prependRepository($repository);
            $this->debug(sprintf('Injected %d locally cached VCS versions (%d repositories)', count($packages), count($declared)));
        } catch (\Throwable $e) {
            // Never interrupt a standard Composer operation due to unavailable optional cache.
            $this->debug('Snapshot unavailable ('.get_class($e).'); using normal Composer');
        }
    }

    private static function vcsFirst(array $config): bool
    {
        $seenOther = false;
        foreach (($config['repositories'] ?? []) as $repo) {
            $isVcs = is_array($repo) && ($repo['type'] ?? null) === 'vcs';
            if ($isVcs && $seenOther) {
                return false;
            }
            if (!$isVcs) {
                $seenOther = true;
            }
        }
        return true;
    }

    private function maximumAge(): int
    {
        $value = getenv('FAST_COMPOSER_PLUGIN_TTL');
        if ($value === false || $value === '') {
            return 1800;
        }
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException('FAST_COMPOSER_PLUGIN_TTL must be a nonnegative number');
        }
        return min(86400, (int) $value);
    }

    private function debug(string $message): void
    {
        $this->io->writeError('[fast-composer plugin] '.$message, true, IOInterface::VERBOSE);
    }
}
