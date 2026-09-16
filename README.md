# fast-composer

Experimental local accelerator for Composer projects that use many `type: vcs` repositories.

## Contract

**Fast Composer performs an optimistic targeted lock-file update. Standard Composer remains the authority for installation in CI.**

Fast Composer does not implement a dependency solver. It builds a local Composer repository from cached VCS metadata, refreshes explicitly requested mutable refs when needed, and then invokes the real Composer solver.

Recommended workflow:

```bash
fast-composer require company/package:dev-feature
# or
fast-composer update company/package

git add composer.json composer.lock
git commit
```

CI continues to run the normal command:

```bash
composer install
```

`fast-composer install` deliberately delegates to standard Composer.

## Safety boundary

A normal `composer install` validates the dependency state represented by `composer.lock`, including dependency constraints and platform requirements, but it trusts package metadata stored in the lock file. It does **not** guarantee that fields such as `require`, `conflict`, `provide`, `replace`, `autoload`, `extra`, or `bin` match the `composer.json` that actually exists at a locked VCS SHA.

Because of that, Fast Composer performs one mandatory targeted check before publishing a generated lock file:

1. Find root-declared `type: vcs` packages whose lock entry changed.
2. Fetch the exact locked commit SHA for those packages only.
3. Read `composer.json` at that SHA.
4. Compare its package metadata with the generated lock entry.
5. Refuse to replace the real `composer.lock` on any mismatch or unreachable SHA.
6. Rewrite the lock `content-hash` for the real project `composer.json`, not the temporary Fast Composer configuration.

This keeps the fast path targeted while covering the class of errors that standard `composer install` cannot detect by itself.

`fast-composer verify` can explicitly re-check the managed VCS entries in an existing lock file and reports `OK`, `MISSING`, or `METADATA-MISMATCH`.

## Metadata checked

For a changed managed VCS package, the source/lock comparison currently covers:

- `require` and `require-dev`
- `conflict`
- `provide` and `replace`
- `suggest`
- `autoload`
- `extra`
- `bin`
- package identity/descriptive fields retained by the snapshot

The source reference itself must also be fetchable.

## What CI Composer still verifies

Regression tests confirm that ordinary `composer install` rejects:

- a locked dependency set missing a dependency required by locked metadata,
- an unavailable VCS commit when it must materialize that source,
- missing platform requirements such as an `ext-*` dependency.

Changed dependency/conflict metadata is fed to the real Composer solver during the local fast update. An impossible conflict therefore fails before Fast Composer touches the real `composer.json` or `composer.lock`.

## Status

MVP. CI currently tests PHP 8.2, 8.3, and 8.4 with Composer 2.10.3, plus dedicated safety-contract regressions.

The current design intentionally optimizes for minimal complexity: local metadata acceleration + targeted source verification + standard Composer as the install authority.
