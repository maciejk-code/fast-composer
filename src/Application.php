<?php
namespace FastComposer;

final class Application
{
    public function run(array $args): int
    {
        $root = getcwd();
        if (!is_file($root.'/composer.json')) {
            fwrite(STDERR, "composer.json not found\n");
            return 2;
        }

        $cmd = $args[0] ?? 'help';
        if (in_array($cmd, ['help', '--help', '-h'], true)) {
            $this->help();
            return 0;
        }

        $rootCfg = json_decode(file_get_contents($root.'/composer.json'), true);
        if (!is_array($rootCfg)) {
            fwrite(STDERR, "Invalid composer.json\n");
            return 2;
        }

        $snap = new Snapshot($root);

        try {
            if ($cmd === 'verify') {
                $bad = 0;
                foreach ($snap->verifyLock() as $name => $result) {
                    if (!$result['reachable']) {
                        $status = 'MISSING';
                        $bad++;
                    } elseif (!$result['metadata_match']) {
                        $status = 'METADATA-MISMATCH';
                        $bad++;
                    } else {
                        $status = 'OK';
                    }
                    printf("%s %s %s\n", $status, $name, substr($result['sha'], 0, 12));
                }
                return $bad ? 1 : 0;
            }

            if ($cmd === 'refresh') {
                $snapshot = $snap->buildFromLockAndCache($rootCfg);
                printf(
                    "snapshot repos=%d packages=%d\n",
                    count($snapshot['repos']),
                    array_sum(array_map('count', $snapshot['packages']))
                );
                return 0;
            }

            // Installation is deliberately not accelerated. The project contract is that normal
            // Composer remains the authority for installing a committed lock file.
            if ($cmd === 'install') {
                [$code] = Process::run(array_merge(['composer'], $args), $root, true);
                return $code;
            }

            if (!in_array($cmd, ['update', 'require'], true)) {
                fwrite(STDERR, "Unsupported command: $cmd\n");
                return 2;
            }

            $snapshot = $snap->load();
            if (!$snapshot) {
                fwrite(STDOUT, "[fast-composer] no snapshot; priming with regular Composer\n");
                [$code] = Process::run(array_merge(['composer'], $args), $root, true);
                if ($code !== 0) {
                    return $code;
                }
                $snap->buildFromLockAndCache(json_decode(file_get_contents($root.'/composer.json'), true));
                return 0;
            }

            if ($cmd === 'require') {
                foreach (array_slice($args, 1) as $spec) {
                    if (str_starts_with($spec, '-') || !str_contains($spec, ':')) {
                        continue;
                    }
                    [$name, $constraint] = explode(':', $spec, 2);
                    if (str_starts_with($constraint, 'dev-')) {
                        $snap->ensureBranch($snapshot, $name, substr($constraint, 4));
                    }
                }
            }

            $beforeLock = $snap->readLock();
            $fast = $snap->writeFastComposer($rootCfg, $snapshot);
            $fastLock = $root.'/.fast-composer.lock';

            if (is_file($root.'/composer.lock')) {
                copy($root.'/composer.lock', $fastLock);
            } elseif (is_file($fastLock)) {
                unlink($fastLock);
            }

            $oldComposerEnv = getenv('COMPOSER');
            putenv('COMPOSER='.basename($fast));
            [$code] = Process::run(array_merge(['composer'], $args), $root, true);
            $oldComposerEnv === false ? putenv('COMPOSER') : putenv('COMPOSER='.$oldComposerEnv);
            if ($code !== 0) {
                return $code;
            }

            if (!is_file($fastLock)) {
                throw new \RuntimeException('Composer did not produce a lock file');
            }

            // composer require edits only the temporary root. Copy the resulting root requirements
            // into the real config before calculating its final content-hash.
            if ($cmd === 'require') {
                $newRoot = json_decode(file_get_contents($fast), true);
                foreach (['require', 'require-dev'] as $key) {
                    if (isset($newRoot[$key])) {
                        $rootCfg[$key] = $newRoot[$key];
                    } else {
                        unset($rootCfg[$key]);
                    }
                }
            }

            $afterLock = $snap->readLock($fastLock);

            // Composer install trusts package metadata stored in composer.lock. Therefore this check
            // is mandatory: re-read only changed Git packages from the exact SHA before publishing
            // the optimistic lock to the real project.
            $snap->validateChangedPackages($beforeLock, $afterLock);

            // Composer created the lock from .fast-composer.json, whose repositories differ from
            // the real project. Rewrite the hash to exactly match the real composer.json semantics.
            $snap->fixContentHash($fastLock, $rootCfg);

            copy($fastLock, $root.'/composer.lock');

            if ($cmd === 'require') {
                file_put_contents(
                    $root.'/composer.json',
                    json_encode($rootCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
                );
            }

            $snap->buildFromLockAndCache($rootCfg);
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, "[fast-composer] ".$e->getMessage()."\n");
            return 1;
        }
    }

    private function help(): void
    {
        echo "fast-composer MVP\n\n";
        echo "Commands:\n";
        echo "  fast-composer update [args...]\n";
        echo "  fast-composer require vendor/package:constraint [args...]\n";
        echo "  fast-composer refresh\n";
        echo "  fast-composer verify\n";
        echo "  fast-composer install [args...]  (delegates to standard Composer)\n\n";
        echo "Fast Composer performs optimistic targeted lock-file updates. Standard Composer remains the authority for installation.\n";
    }
}
