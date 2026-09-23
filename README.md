# fast-composer

Fast local lock-file updates for Composer projects that use many `type: vcs` repositories.

> **Contract:** Fast Composer performs an optimistic targeted lock-file update. Standard Composer remains the authority for installation in CI.

Fast Composer does **not** implement a dependency solver. It snapshots VCS package metadata, refreshes only the refs that matter, exposes that snapshot as a temporary local Composer repository, and invokes the real Composer solver with `--no-install`.

## Requirements

- PHP 8.2+
- Composer 2.3+ installed as the regular phar (`composer` on `PATH`): Fast Composer uses Composer's own classes to interpret VCS repositories. With a non-phar Composer (e.g. a distribution package) it warns and runs regular Composer.
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

If no compatible snapshot exists yet, Fast Composer does **not** run a regular Composer solve. It fetches every declared `type: vcs` repository into a local shallow mirror in parallel (one `git fetch` per repository, `FAST_COMPOSER_JOBS` at a time), indexes every branch/tag `composer.json` from those mirrors locally, and then continues on the normal fast path.

- **New clone or worktree of a project:** mirrors are shared by repository URL across projects, so it only needs an incremental fetch per repository.
- **Changed `repositories`:** the snapshot is updated incrementally — only added repositories are synchronized, removed ones are dropped.
- **`composer clear-cache`** does not touch Fast Composer's cache (see [Runtime behavior](#runtime-behavior)).

## Benchmarks

`benchmarks/compare.sh` runs Composer and Fast Composer from the same warm state (Composer's VCS mirrors and metadata cache populated; Fast Composer's snapshot and mirrors populated) and reports the median of 5 runs, the number of network Git operations, and whether the lock file is **byte-identical** to the one Composer produced. Repositories are served by a local `git daemon`, so Composer takes its normal remote-VCS path (cached mirror + `git remote update`). A Git shim adds a fixed delay to every network Git operation.

The position of the updated package in `repositories` matters for Composer: it initializes VCS repositories in order until it finds the package, so a package in the first repository is Composer's best case and one in the last repository its worst. Fast Composer does not depend on the position.

Measured on a 4-core Linux container, PHP 8.4.19, Composer 2.8.12, Git 2.43.0. These are synthetic fixtures (one-file repositories); validate on your real private-VCS workload before quoting general numbers.

### Private-VCS-like workload: 40 repositories, 40 ms per network Git operation

| Scenario | Composer | Fast Composer | Speed-up | Composer Git net ops | Fast Git net ops | Same lock as Composer |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| targeted no-op, first repository | 0.433 s | 0.309 s | **1.40x** | 1 | 1 | 5/5 |
| targeted no-op, middle repository | 5.074 s | 0.256 s | **19.82x** | 20 | 1 | 5/5 |
| targeted no-op, last repository | 9.952 s | 0.308 s | **32.31x** | 40 | 1 | 5/5 |
| broad no-op, TTL expired | 10.034 s | 0.898 s | **11.17x** | 40 | 40 | 5/5 |
| broad no-op, within TTL | 9.945 s | 0.328 s | **30.32x** | 40 | 2 | 5/5 |
| targeted new tag, middle repository | 5.283 s | 0.325 s | **16.26x** | 20 | 1 | 5/5 |
| moved dev branch, first repository | 0.520 s | 0.323 s | **1.61x** | 1 | 1 | 5/5 |
| moved dev branch, last repository | 10.334 s | 0.311 s | **33.23x** | 40 | 1 | 5/5 |
| first invocation, new clone (shared mirrors warm), last repository | 10.236 s | 1.253 s | **8.17x** | 40 | 40 | 5/5 |
| first invocation (empty cache), last repository | 10.164 s | 2.192 s | **4.64x** | 40 | 40 | 5/5 |

### Zero-latency control: 30 repositories, no added delay

| Scenario | Composer | Fast Composer | Speed-up | Composer Git net ops | Fast Git net ops | Same lock as Composer |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| targeted no-op, first repository | 0.418 s | 0.264 s | **1.58x** | 1 | 1 | 5/5 |
| targeted no-op, middle repository | 3.231 s | 0.216 s | **14.96x** | 15 | 1 | 5/5 |
| targeted no-op, last repository | 6.287 s | 0.265 s | **23.72x** | 30 | 1 | 5/5 |
| broad no-op, TTL expired | 6.242 s | 0.576 s | **10.84x** | 30 | 30 | 5/5 |
| broad no-op, within TTL | 6.417 s | 0.278 s | **23.08x** | 30 | 2 | 5/5 |
| targeted new tag, middle repository | 3.385 s | 0.283 s | **11.96x** | 15 | 1 | 5/5 |
| moved dev branch, first repository | 0.462 s | 0.270 s | **1.71x** | 1 | 1 | 5/5 |
| moved dev branch, last repository | 6.753 s | 0.272 s | **24.83x** | 30 | 1 | 5/5 |
| first invocation, new clone (shared mirrors warm), last repository | 6.510 s | 0.819 s | **7.95x** | 30 | 30 | 5/5 |
| first invocation (empty cache), last repository | 6.566 s | 1.674 s | **3.92x** | 30 | 30 | 5/5 |

### Before / after this optimization round (same harness, 40 ms workload)

| Scenario | Fast Composer v0.1 | Fast Composer now | v0.1 same lock as Composer | now |
| --- | ---: | ---: | ---: | ---: |
| targeted no-op, first repository | 0.436 s | 0.309 s | 0/5 | 5/5 |
| targeted no-op, middle repository | 0.469 s | 0.256 s | 0/5 | 5/5 |
| broad no-op, TTL expired | 9.455 s (80 ops) | 0.898 s (40 ops, parallel) | 0/5 | 5/5 |
| broad no-op, within TTL | 5.743 s (40 ops) | 0.328 s (2 ops) | 0/5 | 5/5 |
| targeted new tag, middle repository | 0.482 s (2 ops) | 0.325 s (1 op) | 0/5 | 5/5 |
| first invocation (empty cache) | 14.021 s (80 ops) | 2.192 s (40 ops) | 5/5 | 5/5 |
| first invocation, new clone of the project | 14.021 s | 1.253 s | 5/5 | 5/5 |

v0.1 locks differed from Composer's only cosmetically (dropped package `time`, `{}` rewritten as `[]`), but that produced diff noise on every update.

Reproduce:

```bash
bash benchmarks/private-vcs-like.sh   # 40 repos, 40 ms per network Git op
bash benchmarks/run.sh                # zero-latency control
bash benchmarks/remote-latency.sh     # real loopback latency via tc netem (Linux, sudo)
BENCH_REPOS=60 BENCH_GIT_DELAY_MS=150 bash benchmarks/compare.sh   # custom
```

Knobs: `BENCH_REPOS`, `BENCH_EXTRA_BRANCHES`, `BENCH_RUNS`, `BENCH_GIT_DELAY_MS`, `BENCH_NETEM_MS`. With `GITHUB_STEP_SUMMARY` set, the table is appended to the job summary.

## Progress output and troubleshooting

Every line is prefixed with the time since the command started, e.g. `[fast-composer  12.1s]`. Network Git work reports each repository as it finishes (`[3/40] <url> ok (0.4s)`) and, every 5 seconds, which repositories it is still waiting on. A repository that never finishes usually means Git/SSH is waiting for an unreachable host/VPN or for a passphrase/host-key confirmation; run `git ls-remote <url>` once by hand.

- `FAST_COMPOSER_DEBUG=1` prints every external command with its exit code and duration (stderr).
- Parallel Git operations run with `GIT_TERMINAL_PROMPT=0`: a missing HTTPS credential fails with a hint instead of hanging on an invisible prompt.
- The step "solving dependency graph with Composer" is Composer itself. Its security audit queries packagist.org after every update/require; for quick local iterations pass `--no-audit` (CI still audits).
- Several packages at once work like in Composer: `fast-composer require firma/plugin:^1.0 firma/plugin2:^1.0` (all their repositories are refreshed in one parallel batch).
- Lock-only updates never install, so Composer never asks "Do you trust this plugin?". Fast Composer warns when the lock contains a `composer-plugin` not listed in `config.allow-plugins`, because a non-interactive `composer install` would refuse it; decide with `composer config allow-plugins.vendor/plugin true`.

## Performance tuning

- `FAST_COMPOSER_JOBS` (default 8): concurrent network Git operations for the first-run sync, broad refreshes, dev-branch revalidation and `verify`.
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

Snapshot/cache data lives outside the project working tree in `~/.cache/fast-composer` (`$XDG_CACHE_HOME/fast-composer`, `~/Library/Caches/fast-composer` on macOS, `%LOCALAPPDATA%/fast-composer` on Windows; override with `FAST_COMPOSER_CACHE_DIR`). It is deliberately outside Composer's `cache-dir`, so `composer clear-cache` keeps it. Snapshots are per project path; Git mirrors are shared per repository URL. Temporary Composer files are cleaned after every operation, including failures. The project should be left with only the intended `composer.json` / `composer.lock` changes.

## Authentication and secrets

Fast Composer talks to VCS repositories with Git. For every network Git call it uses:

1. **The credentials Composer would use** for an HTTP(S) URL — `auth.json` of the project and of `COMPOSER_HOME`, and `COMPOSER_AUTH` (`github-oauth`, `gitlab-token`/`gitlab-oauth`, `http-basic`, `bearer`), read with Composer's own configuration code and shaped like Composer's Git utility does. They are passed to Git as an `Authorization` header through `GIT_CONFIG_*` environment variables of that one process: never written to disk and not visible in the process list (Composer itself puts them into the URL).
2. **Git's own authentication** — SSH keys/agent for `git@…` URLs, the credential helper for HTTPS. If Composer's credentials are rejected, the call is retried this way.

Git runs non-interactively (`GIT_TERMINAL_PROMPT=0`), so missing credentials fail with a hint instead of hanging. Fast Composer never stores or logs credentials.

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
- byte-identical results to plain Composer for default branches, branch aliases, unparseable and `v`-prefixed tags, renamed packages, repository `exclude`, and `require` formatting (`tests/parity-contract.sh`),
- HTTPS credentials from `auth.json`, fallback to Git's own authentication, and a clear failure without credentials (`tests/auth-contract.sh`).

Run everything locally with `composer check` (PHPStan, unit tests, all contracts).

## Code layout

| Class | Responsibility |
| --- | --- |
| `Application` | CLI commands and the order of steps (sync → refresh → solve → validate → publish) |
| `Snapshot` | Per-project snapshot state: sync, TTL/targeted refresh, dev branches, one local `composer` repository per VCS repository (same position and options) |
| `ComposerPackages`, `MirrorDriver` | Package data is produced by **Composer's own `VcsRepository`**, reading from the local mirror through a Composer VCS driver — version names, skipped tags, package names, `default-branch`, aliases and release dates are Composer's logic |
| `GitMirror` | All network Git access: shared shallow mirrors, parallel fetches with progress, locks, `cat-file` reads, reachability proven during this run |
| `LockValidator` | Safety contract: changed/locked VCS packages must match `composer.json` at their exact SHA |
| `ComposerSolver`, `InProcessComposer` | Running the real Composer solver (in-process when possible) |
| `RootConfig`, `LockFile`, `CommandLine` | Reading the root config, the lock file and command-line arguments |
| `JsonFile`, `ComposerJson`, `GitUrl`, `Process` | Low-level helpers |

Unit tests live in `tests/unit/` (one file per area, each run with a fresh temporary cache by `tests/run.php`); `tests/*.sh` are end-to-end contracts against real Composer.

## Scope of v0.1

Fast Composer intentionally optimizes the common local developer path. It is not a replacement for Composer, Satis or Private Packagist, and it does not promise that its lock output will be byte-for-byte identical to an unconstrained `composer update`.

The supported contract is narrower:

> **Fast Composer creates an optimistic targeted lock update, validates changed VCS package metadata at the exact SHA, and leaves final installation authority to standard Composer in CI.**

For unsupported Composer modes where preserving exact semantics is more important than acceleration, Fast Composer delegates to standard Composer instead.
