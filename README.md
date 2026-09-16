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

Looks up that exact branch. If the branch has moved to another commit, Fast Composer fetches metadata for the new SHA before solving.

Explicit `dev-*` requirements already present in the root `composer.json` are also revalidated on a plain `fast-composer update`, regardless of TTL.

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

Its VCS refresh path invokes Git directly (`git ls-remote` / targeted fetch), so the VCS URL must already work with your normal Git authentication. SSH URLs work naturally when your SSH agent/key is configured. HTTPS URLs work when Git itself has credentials available through its normal credential mechanism.

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

`fast-composer verify` re-checks managed VCS entries already in an existing lock and reports `OK`, `MISSING`, or `METADATA-MISMATCH`.

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
- external cache + no working-tree scratch files,
- lock-only accelerated updates (no `vendor/` materialization),
- installation as a global Composer binary.

## Scope of v0.1

Fast Composer intentionally optimizes the common local developer path. It is not a replacement for Composer, Satis or Private Packagist, and it does not promise that its lock output will be byte-for-byte identical to an unconstrained `composer update`.

The supported contract is narrower:

> **Fast Composer creates an optimistic targeted lock update, validates changed VCS package metadata at the exact SHA, and leaves final installation authority to standard Composer in CI.**

For unsupported Composer modes where preserving exact semantics is more important than acceleration, Fast Composer delegates to standard Composer instead.
