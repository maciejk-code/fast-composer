# Changelog

## Unreleased

### Performance

- VCS refresh uses a persistent shallow mirror per repository (in the Fast Composer cache): one `git fetch` both lists refs and downloads new tips, replacing the `ls-remote` + throw-away clone pair. The first fetch is `--depth=1`; later fetches are incremental.
- `composer.json` of all new refs is read with a single `git cat-file --batch` instead of one `git show` per ref.
- Broad refreshes, priming, explicit `dev-*` revalidation, changed-package validation and `verify` run their network Git operations concurrently (`FAST_COMPOSER_JOBS`, default 8).
- A broad refresh no longer fetches explicit `dev-*` branches a second time.
- The Composer solver runs in-process when `composer` is a regular phar (falls back to a subprocess otherwise, with Xdebug loaded, or with `FAST_COMPOSER_IN_PROCESS=0`). `bin/fast-composer` now uses its own autoloader so foreign global packages cannot leak into that process.
- The inner Composer run skips root-version guessing (`COMPOSER_ROOT_VERSION`) when nothing can reference the root package, and skips `stty` probing when output is not a terminal.
- Removed a `composer config` subprocess per uncached GitHub ref.

### Fixed

- Lock files written by Fast Composer are now byte-identical to Composer's: package `time` is preserved (commit author date, like Composer's GitDriver) and `content-hash` is patched in place instead of re-encoding the lock (which turned `{}` into `[]`).
- Branches/tags without a `composer.json` (e.g. `gh-pages`) are skipped like Composer does instead of failing the refresh.

### Benchmarks

- New `benchmarks/compare.sh` harness: repositories served over `git daemon` (Composer's real remote path), target package at the first/middle/last repository position, new tag, moved dev branch, broad update inside/outside the TTL and first invocation; reports Git network operation counts and whether the lock is byte-identical to Composer's. `run.sh`, `private-vcs-like.sh` and `remote-latency.sh` are presets of it.

### Upgrade note

- Snapshot format bumped to 4: the first invocation after upgrading re-primes once.

## 0.1.0 - 2026-09-16

Initial usable release candidate.

### Added

- `fast-composer update` and targeted package updates.
- `fast-composer require` with explicit `dev-*` branch refresh.
- `fast-composer refresh`, `verify`, `status`, and `--version`.
- External per-project snapshot cache in the user's Composer cache area.
- Broad repository TTL with targeted bypass for explicit packages and mutable development branches.
- Real Composer dependency solving; Fast Composer does not implement its own solver.
- Mandatory source metadata verification for changed root-declared VCS packages before publishing `composer.lock`.
- Atomic publication of `composer.json` / `composer.lock` changes.
- Lock-only accelerated operations (`--no-install`) and cleanup of temporary work files.
- Composer-compatible root `content-hash` generation.
- PHP 8.2, 8.3 and 8.4 CI coverage.
- Global Composer installation smoke test.
- Safety regressions for metadata changes, dependency changes, conflicts, provide/replace, unavailable SHAs, platform requirements, new tags and moving dev branches.

### Safety contract

Fast Composer performs optimistic targeted lock-file updates. Standard Composer remains the final installation authority in CI. Fast Composer itself verifies the source metadata of every changed managed VCS package because Composer install trusts package metadata already stored in the lock file.

### Known limitations

- Private VCS authentication must already work for Git itself (`git ls-remote` / fetch). Fast Composer does not read or persist Composer OAuth credentials or secrets.
- The first invocation without a compatible snapshot falls back to a normal Composer solve and can therefore be slow.
- v0.1 focuses on `type: vcs` Git repositories and intentionally delegates unsupported Composer modes rather than attempting to emulate every Composer behavior.
- Output is not promised to be byte-for-byte identical to an unconstrained `composer update`; the supported contract is a targeted optimistic lock update validated at the changed VCS SHA.
