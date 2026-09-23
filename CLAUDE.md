# Fast Composer — notes for working on this repository

Fast Composer makes `composer update/require` fast for projects with many `type: vcs`
repositories. **It only changes how VCS data is fetched; Composer decides everything else.**

## Invariants (check every change against these)

1. **Same result as Composer.** `composer.lock` must be byte-identical to what plain Composer
   produces, and `require` must edit `composer.json` exactly like `composer require` (same
   bytes, formatting kept). `tests/parity-contract.sh` enforces this; add a scenario there for
   every Composer behaviour you touch.
2. **Do not re-implement Composer.** Package data comes from Composer's own `VcsRepository`
   running on `MirrorDriver`; composer.json edits use Composer's `JsonManipulator`; credentials
   come from Composer's config. Every past bug came from re-implementing Composer rules
   (version names, package names, default-branch, formatting). Composer's classes are loaded
   from the `composer` phar on PATH (`InProcessComposer::loadClasses()`).
3. **Safety contract.** Before publishing a lock, every changed managed VCS package is
   compared with composer.json at its exact SHA, fetched from the remote during this run
   (`LockValidator`). `composer install` does not check this. Never weaken it.
4. **Never silent, never interactive.** Network Git runs in parallel with progress output and
   `GIT_TERMINAL_PROMPT=0`; errors carry a hint. Credentials never go to disk or argv.

## Layout

See the "Code layout" table in README.md. Flow of `update`/`require`:
`Application` → `Snapshot::sync` (first run / changed repositories) → targeted or TTL refresh
(`GitMirror` fetch + `Snapshot::hydrate` via `ComposerPackages`) → `Snapshot::writeFastComposer`
(one local `composer` repo per VCS repo, same position/options) → `ComposerSolver` (Composer
in-process on a temporary composer.json) → `LockValidator` → `LockFile::fixContentHash` →
publish (`ComposerJson::applyRequireChanges` for require).

## Commands

- `bash tests/ci.sh` — **everything CI runs** (the workflow only calls this script, so add new
  checks here, not in `.github/workflows`). Targets: `all` (default), `unit`, `analyse`,
  `contracts`, or one contract by name (`parity`, `auth`, `safety`, `ci-verifier`, `runtime`,
  `global-install`). New contract: add `tests/<name>-contract.sh` and its name to `CONTRACTS`.
- `composer test` — unit tests only (`tests/unit/*.php`, each isolated with a fresh cache;
  helpers in `tests/support.php`). Fast.
- `composer check` = `bash tests/ci.sh all`; `composer analyse` = PHPStan level 5
  (`phpstan.neon.dist`; needs the composer phar on PATH).
- Benchmarks: `bash benchmarks/private-vcs-like.sh` (40 repos, 40 ms per Git op, ~5 min),
  `BENCH_RUNS=1 BENCH_REPOS=10 bash benchmarks/run.sh` for a quick sanity check. Every row must
  say `5/5` (or `1/1`) in "Same lock as Composer". Do not run benchmarks in parallel with tests.

## Reading Composer's source

Composer's behaviour is the spec. Extract the phar you run against and read it:

```bash
php -d phar.readonly=0 -r '$p=new Phar("composer.phar"); $p->extractTo("/tmp/composer-src");'
```

(copy `$(command -v composer)` to a `.phar` name first). Useful: `Repository/VcsRepository.php`,
`Repository/Vcs/GitDriver.php`, `Util/Git.php`, `Json/JsonManipulator.php`,
`Command/RequireCommand.php`, `vendor/composer/semver/src/VersionParser.php`.

## Environment gotchas (cloud sessions)

- Git commit/tag signing may be configured globally: fixtures use `-c commit.gpgsign=false
  -c tag.gpgsign=false`.
- `.github/workflows/*` cannot be pushed from sessions without the `workflow` token scope —
  describe workflow changes to the user instead.
- packagist.org occasionally times out (`curl error 28`) in plain Composer steps of contracts;
  re-run before suspecting a change. New fixtures should disable packagist
  (`{"packagist.org": false}`).
- Composer downloads from github.com may need auth here; `curl` of release assets works (e.g.
  the PHPStan phar) when `composer require --dev` cannot install.
- `GIT_CONFIG_COUNT` may already be set in the environment; code appending `GIT_CONFIG_*`
  entries must start at the existing count.
- Background `git daemon` in scripts: start it directly (not through a shell function), so `$!`
  is the daemon and cleanup can kill it.

## Conventions

- Keep changes behaviour-preserving unless the parity/safety contracts say otherwise; run
  `composer check` before pushing.
- CHANGELOG.md "Unreleased" gets an entry per user-visible change.
- Commit messages explain the Composer behaviour being matched and how it was verified.
