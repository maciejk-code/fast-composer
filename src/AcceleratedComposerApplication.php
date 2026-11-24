<?php

namespace FastComposer;

use Composer\Composer;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;

/** Attach acceleration only to Composer's validated install transaction. */
final class AcceleratedComposerApplication extends Application
{
    private ?Composer $registered = null;
    private ?InstallCache $cache = null;
    private ?DeferredPackageFilesystem $removal = null;

    /** @param list<string> $args */
    public function __construct(private readonly string $root, private readonly array $args)
    {
        parent::__construct();
        register_shutdown_function([$this, 'finish']);
    }

    public function getComposer(bool $required = true, ?bool $disablePlugins = null, ?bool $disableScripts = null): ?Composer
    {
        $composer = parent::getComposer($required, $disablePlugins, $disableScripts);
        if ($composer !== null && $this->registered !== $composer) {
            $this->registered = $composer;
            $composer->getEventDispatcher()->addListener(InstallerEvents::PRE_OPERATIONS_EXEC, [$this, 'accelerate'], PHP_INT_MIN);
        }
        return $composer;
    }

    public function accelerate(InstallerEvent $event): void
    {
        if (!$event->isExecutingOperations() || $event->getTransaction() === null) {
            return;
        }
        $composer = $event->getComposer();
        $operations = $event->getTransaction()->getOperations();
        $names = [];
        $removals = [];
        foreach ($operations as $operation) {
            if ($operation instanceof InstallOperation || $operation instanceof UpdateOperation) {
                $target = $operation instanceof UpdateOperation ? $operation->getTargetPackage() : $operation->getPackage();
                if ($target->getType() === 'composer-plugin' && !empty($target->getExtra()['plugin-modifies-downloads'])) {
                    // Preserve Composer's own download-plugin batch ordering.
                    return;
                }
                $names[] = $target->getName();
            }
            if ($operation instanceof UpdateOperation) {
                $removals[] = $operation->getInitialPackage();
            } elseif ($operation instanceof UninstallOperation) {
                $removals[] = $operation->getPackage();
            }
        }

        if ($names !== [] && getenv('FAST_COMPOSER_PREFETCH') !== '0') {
            try {
                $config = $composer->getConfig();
                $this->cache = InstallCache::open($this->root, $config);
                if ($this->cache !== null) {
                    $result = SourcePrefetch::run($this->root, $this->args, $config, $this->cache->path, $names);
                    if ($result !== null && $result[0] > 0 && $result[1] === 0) {
                        $config->merge(['config' => ['cache-vcs-dir' => $this->cache->path]], 'fast-composer private install cache');
                        $event->getIO()->writeError(sprintf('[fast-composer] prefetched %d Git mirrors', $result[0]));
                    }
                }
            } catch (\Throwable) {
                $event->getIO()->writeError('[fast-composer] prefetch unavailable; continuing with Composer');
            }
        }

        if ($removals !== [] && getenv('FAST_COMPOSER_ASYNC_REMOVE') !== '0' && !in_array('--download-only', $this->args, true)) {
            try {
                $this->removal = AsyncPackageRemoval::attach($this->root, $composer, $event->getIO(), $removals);
            } catch (\Throwable) {
                $event->getIO()->writeError('[fast-composer] deferred removal unavailable; continuing with Composer');
            }
        }
    }

    public function finish(): void
    {
        $this->removal?->finish();
        $this->cache?->close();
    }
}
