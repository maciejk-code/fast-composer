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

    /** @phpstan-return ($required is true ? Composer : Composer|null) */
    public function getComposer(bool $required = true, ?bool $disablePlugins = null, ?bool $disableScripts = null): ?Composer
    {
        $composer = parent::getComposer($required, $disablePlugins, $disableScripts);
        if ($composer !== null && $this->registered !== $composer) {
            $this->registered = $composer;
            if (($this->args[0] ?? null) === 'status') {
                try {
                    StatusAcceleration::attach($composer, $this->getIO());
                } catch (\Throwable) {
                    // A missing or changed Composer capability must not break status.
                }
            } else {
                $composer->getEventDispatcher()->addListener(InstallerEvents::PRE_OPERATIONS_EXEC, [$this, 'accelerate'], PHP_INT_MIN);
            }
        }
        return $composer;
    }

    public function accelerate(InstallerEvent $event): void
    {
        $transaction = $event->getTransaction();
        if (!$event->isExecutingOperations() || $transaction === null) {
            return;
        }
        $composer = $event->getComposer();
        $operations = $transaction->getOperations();
        $names = [];
        $removals = [];
        foreach ($operations as $operation) {
            if ($operation instanceof InstallOperation || $operation instanceof UpdateOperation) {
                $target = $operation instanceof UpdateOperation ? $operation->getTargetPackage() : $operation->getPackage();
                if ($target->getType() === 'composer-plugin' && !empty($target->getExtra()['plugin-modifies-downloads'])) {
                    // Preserve Composer's own download-plugin batch ordering.
                    return;
                }
                $names[$target->getName()] = [$target->getSourceUrl(), $target->getSourceReference()];
            }
            if ($operation instanceof UpdateOperation) {
                $removals[] = $operation->getInitialPackage();
            } elseif ($operation instanceof UninstallOperation) {
                $removals[] = $operation->getPackage();
            }
        }

        if ($names !== [] && getenv('FAST_COMPOSER_PREFETCH') !== '0' && InstallCompatibility::supportsPrefetch()) {
            try {
                $config = $composer->getConfig();
                $this->cache = InstallCache::open($this->root, $config);
                if ($this->cache !== null) {
                    $result = SourcePrefetch::run($this->root, $this->args, $config, $this->cache->path, $names);
                    if ($result !== null && ($result[0] > 0 || $result[2] > 0) && $result[1] === 0) {
                        $config->merge(['config' => ['cache-vcs-dir' => $this->cache->path]], 'fast-composer private install cache');
                        if ($result[0] > 0) {
                            $event->getIO()->writeError(sprintf('[fast-composer] prefetched %d Git mirrors', $result[0]));
                        }
                        if ($result[2] > 0) {
                            $event->getIO()->writeError(sprintf('[fast-composer] reused %d warm Git mirrors without workers', $result[2]));
                        }
                        if ($result[3] > 0) {
                            $event->getIO()->writeError(sprintf('[fast-composer] delegated %d remaining mirrors to Composer', $result[3]));
                        }
                    } elseif ($result !== null && $result[1] > 0) {
                        $event->getIO()->writeError('[fast-composer] prefetch workers failed; continuing with Composer');
                    }
                } else {
                    $event->getIO()->writeError('[fast-composer] private VCS cache unavailable; continuing with Composer', true, \Composer\IO\IOInterface::VERBOSE);
                }
            } catch (\Throwable) {
                $event->getIO()->writeError('[fast-composer] prefetch unavailable; continuing with Composer');
            }
        }

        if (
            $removals !== [] && getenv('FAST_COMPOSER_ASYNC_REMOVE') !== '0'
            && !in_array('--download-only', $this->args, true) && InstallCompatibility::supportsRemoval()
        ) {
            try {
                $this->removal = AsyncPackageRemoval::attach($this->root, $composer, $event->getIO(), $removals);
            } catch (\Throwable) {
                $event->getIO()->writeError('[fast-composer] deferred removal unavailable; continuing with Composer');
            }
        }
    }

    public function finish(): bool
    {
        $ok = $this->removal?->finish() ?? true;
        $this->cache?->close();
        $this->removal = null;
        $this->cache = null;
        return $ok;
    }
}
