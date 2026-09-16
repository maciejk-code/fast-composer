# Changelog

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
