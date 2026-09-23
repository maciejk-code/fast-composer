# fast-composer

Fast local lock-file updates for Composer projects that use many `type: vcs` repositories.

> **Contract:** Fast Composer performs an optimistic targeted lock-file update. Standard Composer remains the authority for installation in CI.

Fast Composer does **not** implement a dependency solver. It snapshots VCS package metadata, refreshes only the refs that matter, exposes that snapshot as a temporary local Composer repository, and invokes the real Composer solver with `--no-install`.

## Requirements

- PHP 8.2+
- Composer 2.x
- Git
- Git access to every private `type: vcs` repository you want Fast Composer to accelerate

CI currently covers PHP 8.2, 8.3 and 8.4 with Composer 2.10.3.

## Install from this private repository

Until the package is published/tagged for normal Packagist installation, install it globally through the Git repository:

```bash
composer global config repositories.fast-composer vcs git@github.com:maciejk-code/fast-composer.git
composer global require maciejk-code/fast-composer:dev-main
```

Check where Composer installs global binaries:

```bash
composer global config bin-dir --absolute
```

Make sure that directory is in your `PATH`, then verify:

```bash
fast-composer --version
```

The installation path is covered by a dedicated global-install smoke test in CI.

## Typical workflow

```bash
# targeted package update
fast-composer update company/package

# newly created or moved development branch
fast-composer require company/package:dev-feature

# inspect cache state
fast-composer status

# explicitly rebuild/refetch the VCS snapshot
fast-composer refresh

# verify managed VCS entries already present in composer.lock
fast-composer verify
```

Commit only the normal Composer files:

```bash
git add composer.json composer.lock
git commit
```

CI stays unchanged:

```bash
composer install
```

`fast-composer install` deliberately delegates to standard Composer.

## First run

If no compatible snapshot exists yet, Fast Composer falls back to a normal Composer solve with `--no-install`, then builds its snapshot. That first run can therefore be as slow as ordinary Composer. Subsequent targeted operations use the snapshot.

Initial priming is lazy: metadata already selected by regular Composer is reused from `composer.lock`, while Fast Composer indexes branch/tag refs without fetching `composer.json` for every ref. Metadata for a new or moved ref is fetched only when it is actually needed.

## Benchmarks

`benchmarks/compare.sh` runs Composer and Fast Composer from the same warm state (Composer's VCS mirrors and metadata cache populated; Fast Composer's snapshot and mirrors populated) and reports the median of 5 runs, the number of network Git operations, and whether the lock file is **byte-identical** to the one Composer produced. Repositories are served by a local `git daemon`, so Composer takes its normal remote-VCS path (cached mirror + `git remote update`). A Git shim adds a fixed delay to every network Git operation.

The position of the updated package in `repositories` matters for Composer: it initializes VCS repositories in order until it finds the package, so a package in the first repository is Composer's best case and one in the last repository its worst. Fast Composer does not depend on the position.

Measured on a 4-core Linux container, PHP 8.4.19, Composer 2.8.12, Git 2.43.0. These are synthetic fixtures (one-file repositories); validate on your real private-VCS workload before quoting general numbers.

### Private-VCS-like workload: 40 repositories, 40 ms per network Git operation

| Scenario | Composer | Fast Composer | Speed-up | Composer Git net ops | Fast Git net ops | Same lock as Composer |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| targeted no-op, first repository | 0.422 s | 0.294 s | **1.44x** | 1 | 1 | 5/5 |
| targeted no-op, middle repository | 4.940 s | 0.306 s | **16.14x** | 20 | 1 | 5/5 |
| targeted no-op, last repository | 9.855 s | 0.295 s | **33.41x** | 40 | 1 | 5/5 |
| broad no-op, TTL expired | 9.798 s | 1.973 s | **4.97x** | 40 | 40 | 5/5 |
| broad no-op, within TTL | 9.917 s | 0.319 s | **31.09x** | 40 | 2 | 5/5 |
| targeted new tag, middle repository | 5.119 s | 0.334 s | **15.33x** | 20 | 1 | 5/5 |
| moved dev branch, first repository | 0.501 s | 0.302 s | **1.66x** | 1 | 1 | 5/5 |
| moved dev branch, last repository | 10.215 s | 0.309 s | **33.06x** | 40 | 1 | 5/5 |
| first invocation (cold snapshot), last repository | 10.172 s | 10.710 s | **0.95x** | 40 | 80 | 5/5 |

### Zero-latency control: 30 repositories, no added delay

| Scenario | Composer | Fast Composer | Speed-up | Composer Git net ops | Fast Git net ops | Same lock as Composer |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| targeted no-op, first repository | 0.385 s | 0.253 s | **1.52x** | 1 | 1 | 5/5 |
| targeted no-op, middle repository | 3.167 s | 0.269 s | **11.77x** | 15 | 1 | 5/5 |
| targeted no-op, last repository | 6.002 s | 0.264 s | **22.73x** | 30 | 1 | 5/5 |
| broad no-op, TTL expired | 6.119 s | 1.349 s | **4.54x** | 30 | 30 | 5/5 |
| broad no-op, within TTL | 5.970 s | 0.252 s | **23.69x** | 30 | 2 | 5/5 |
| targeted new tag, middle repository | 3.243 s | 0.293 s | **11.07x** | 15 | 1 | 5/5 |
| moved dev branch, first repository | 0.451 s | 0.260 s | **1.73x** | 1 | 1 | 5/5 |
| moved dev branch, last repository | 6.302 s | 0.253 s | **24.91x** | 30 | 1 | 5/5 |
| first invocation (cold snapshot), last repository | 6.452 s | 6.662 s | **0.97x** | 30 | 60 | 5/5 |

### Before / after this optimization round (same harness, 40 ms workload)

| Scenario | Fast Composer v0.1 | Fast Composer now | v0.1 same lock as Composer | now |
| --- | ---: | ---: | ---: | ---: |
| targeted no-op, first repository | 0.436 s | 0.294 s | 0/5 | 5/5 |
| targeted no-op, middle repository | 0.469 s | 0.306 s | 0/5 | 5/5 |
| broad no-op, TTL expired | 9.455 s (80 ops) | 1.973 s (40 ops, parallel) | 0/5 | 5/5 |
| broad no-op, within TTL | 5.743 s (40 ops) | 0.319 s (2 ops) | 0/5 | 5/5 |
| targeted new tag, middle repository | 0.482 s (2 ops) | 0.334 s (1 op) | 0/5 | 5/5 |
| first invocation (cold snapshot) | 14.021 s | 10.710 s | 5/5 | 5/5 |

v0.1 locks differed from Composer's only cosmetically (dropped package `time`, `{}` rewritten as `[]`), but that produced diff noise on every update.

**Known weak spot:** the first invocation without a snapshot still runs a regular Composer solve and then indexes refs, so it is slightly slower than plain Composer (0.95x). See [First run](#first-run).

Reproduce:

```bash
bash benchmarks/private-vcs-like.sh   # 40 repos, 40 ms per network Git op
bash benchmarks/run.sh                # zero-latency control
bash benchmarks/remote-latency.sh     # real loopback latency via tc netem (Linux, sudo)
BENCH_REPOS=60 BENCH_GIT_DELAY_MS=150 bash benchmarks/compare.sh   # custom
```

Knobs: `BENCH_REPOS`, `BENCH_EXTRA_BRANCHES`, `BENCH_RUNS`, `BENCH_GIT_DELAY_MS`, `BENCH_NETEM_MS`. With `GITHUB_STEP_SUMMARY` set, the table is appended to the job summary.

## Performance tuning

- `FAST_COMPOSER_JOBS` (default 8): concurrent network Git operations for broad refreshes, priming, dev-branch revalidation and `verify`.
- `FAST_COMPOSER_IN_PROCESS=0`: run the Composer solver as a subprocess. By default it runs in-process when `composer` on `PATH` is a regular Composer phar and Xdebug is not loaded.
- Fast Composer keeps a shallow Git mirror per VCS repository under its cache directory; `fast-composer update` refreshes it with a single `git fetch`.

## What gets refreshed

### Targeted update

```bash
fast-composer update company/package
```

Always refreshes the matching managed VCS repository. It does not wait for the global TTL.

### Targeted dev branch

```bash
fast-composer require company/package:dev-feature
```

Fetches that exact branch shallowly in a single Git network operation, obtaining both its current SHA and `composer.json` metadata before solving. The fetched exact-SHA metadata is reused for the mandatory source validation during the same invocation.

Explicit `dev-*` requirements already present in the root `composer.json` are also revalidated on a plain or targeted `fast-composer update`, regardless of TTL.

### Broad update

```bash
fast-composer update
```

Uses a 300-second broad-ref TTL by default so it does not scan every VCS repository on every invocation. Change it with:

```bash
FAST_COMPOSER_TTL=60 fast-composer update
```

The TTL does not hide explicitly targeted packages or root `dev-*` refs.

## Runtime behavior

Fast operations are lock-only. Fast Composer automatically adds `--no-install` to `update` and `require`, so it does not create/update `vendor/` as part of the accelerated path.

Snapshot/cache data lives outside the project working tree in the user's Composer cache area, keyed per project. Temporary Composer files are cleaned after every operation, including failures. The project should be left with only the intended `composer.json` / `composer.lock` changes.

## Authentication and secrets

Fast Composer does not accept, copy, persist, or log GitHub tokens, SSH private keys, `auth.json`, or other credentials.

Its VCS refresh path invokes Git directly (`git fetch` into a local mirror, `git ls-remote` while priming), so the VCS URL must already work with your normal Git authentication. SSH URLs work naturally when your SSH agent/key is configured. HTTPS URLs work when Git itself has credentials available through its normal credential mechanism.

A Composer-only OAuth token in `auth.json` is not automatically converted into Git credentials by Fast Composer. This is intentionally kept out of v0.1 to avoid duplicating or persisting credentials.

## Safety boundary

A normal `composer install` verifies that the dependency state represented by `composer.lock` is installable on the current platform, but it trusts package metadata stored in the lock file. It does **not** guarantee that fields such as `require`, `conflict`, `provide`, `replace`, `autoload`, `extra`, or `bin` match the `composer.json` that actually exists at a locked VCS SHA.

Because of that, Fast Composer performs a mandatory targeted source check before publishing a generated lock file:

1. Find root-declared `type: vcs` packages whose lock entry changed.
2. Fetch the exact locked commit SHA for those packages only.
3. Read `composer.json` at that SHA.
4. Compare source metadata with the generated lock entry.
5. Refuse to replace the real `composer.lock` on mismatch or unreachable SHA.
6. Write the lock `content-hash` for the real project `composer.json`, not the temporary Fast Composer configuration.

This covers the class of metadata-corruption bugs that `composer install` alone does not catch while keeping the verification targeted.

`fast-composer verify` re-checks managed VCS entries already present in an existing lock and reports `OK`, `MISSING`, or `METADATA-MISMATCH`.

## Metadata verified against the locked SHA

For changed managed VCS packages, source/lock verification covers:

- `require`
- `require-dev`
- `conflict`
- `provide`
- `replace`
- `suggest`
- `autoload`
- `include-path`
- `target-dir`
- `extra`
- `bin`
- package type/name and retained package metadata

The locked source SHA must also be fetchable.

## What standard Composer in CI still verifies

Regression tests confirm that ordinary `composer install` rejects, among other things:

- a locked dependency set missing a dependency required by locked metadata,
- an unavailable VCS commit when the source has to be materialized,
- missing platform requirements such as `ext-*`.

Changed dependency/conflict metadata is fed into the real Composer solver during the local fast update. An impossible dependency/conflict therefore fails before Fast Composer publishes the real lock.

## Important observed Composer behavior

The safety suite deliberately creates a corrupt lock entry whose `source.reference` points to branch B while its dependency/autoload metadata is copied from branch A. Standard `composer install` accepts that internally consistent lock. `fast-composer verify` correctly reports `METADATA-MISMATCH`.

That regression is intentional and documents why Fast Composer's source-metadata verification is mandatory rather than optional.

## CI regression contracts

The repository maintains tests for:

- complete metadata propagation when changing A → B,
- a branch adding a new dependency,
- corrupt lock metadata that standard Composer accepts,
- changed `conflict`, `provide` and `replace`,
- unavailable/stale SHA,
- platform requirements (`PHP` / `ext-*` class of constraints),
- targeted discovery of a newly created tag,
- a mutable development branch moving to a new commit,
- targeted refresh of an explicitly pinned mutable development branch,
- external cache + no working-tree scratch files,
- lock-only accelerated updates (no `vendor/` materialization),
- installation as a global Composer binary.

## Scope of v0.1

Fast Composer intentionally optimizes the common local developer path. It is not a replacement for Composer, Satis or Private Packagist, and it does not promise that its lock output will be byte-for-byte identical to an unconstrained `composer update`.

The supported contract is narrower:

> **Fast Composer creates an optimistic targeted lock update, validates changed VCS package metadata at the exact SHA, and leaves final installation authority to standard Composer in CI.**

For unsupported Composer modes where preserving exact semantics is more important than acceleration, Fast Composer delegates to standard Composer instead.
