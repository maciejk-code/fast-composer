#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$(mktemp -d)"
trap 'rm -rf "$BASE"' EXIT
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
export FAST_COMPOSER_STATUS=1 FAST_COMPOSER_IN_PROCESS=1
# Fail with useful diagnostics rather than silently falling back in a coverage-enabled job.
php -r 'printf("PHP %s; xdebug=%s; phar=%s; zip=%s\n", PHP_VERSION, extension_loaded("xdebug")?"yes":"no", extension_loaded("phar")?"yes":"no", extension_loaded("zip")?"yes":"no");'
export COMPOSER_HOME="$BASE/home" COMPOSER_CACHE_DIR="$BASE/cache"
mkdir "$BASE/project"
for name in a b c; do
  repo="$BASE/$name"
  git init -q -b main "$repo"
  git -C "$repo" config user.email fixture@example.invalid
  git -C "$repo" config user.name fixture
  printf '{"name":"fixture/%s"}\n' "$name" >"$repo/composer.json"
  printf 'original\n' >"$repo/code.php"
  git -C "$repo" add .
  git -C "$repo" -c commit.gpgsign=false commit -qm initial
  git -C "$repo" -c tag.gpgsign=false tag 1.0.0
done
cd "$BASE/project"
php -r '$p=["name"=>"fixture/root","repositories"=>[["packagist.org"=>false]],"require"=>[]];foreach(["a","b","c"] as $n){$p["repositories"][]=["type"=>"vcs","url"=>$argv[1]."/".$n];$p["require"]["fixture/".$n]="1.0.0";}file_put_contents("composer.json",json_encode($p));' "$BASE"
composer update --prefer-source --no-audit --no-interaction --no-plugins --no-scripts
parity() {
  local a=0 b=0
  composer status --no-ansi "$@" >"$BASE/a.out" 2>"$BASE/a.err" || a=$?
  php "$ROOT/bin/fast-composer" composer-status --no-ansi "$@" >"$BASE/b.out" 2>"$BASE/b.err" || b=$?
  test "$a" = "$b"
  diff -u "$BASE/a.out" "$BASE/b.out"
  diff -u "$BASE/a.err" "$BASE/b.err"
}
parity -v
case "$(composer --version --no-ansi 2>&1)" in
  *"Composer version 2.8.12 "*|*"Composer version 2.9.5 "*|*"Composer version 2.10.2 "*|*"Composer version 2.10.3 "*)
    FAST_COMPOSER_STATUS_TRACE=1 COMPOSER_MAX_PARALLEL_PROCESSES=4 php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/trace.out" 2>"$BASE/trace.err"
    grep -F 'parallel Git status proved 3 clean checkouts' "$BASE/trace.err" || { cat "$BASE/trace.err" >&2; exit 1; }
    test "$(grep -cF 'proved locked Git HEAD without version guess:' "$BASE/trace.err")" = 3
    test "$(grep -cF 'proved synchronized Git refs:' "$BASE/trace.err")" = 3
    FAST_COMPOSER_STATUS_REFS=0 FAST_COMPOSER_STATUS_TRACE=1 php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/trace.out" 2>"$BASE/trace.err"
    test "$(grep -cF 'skipped duplicate version guess:' "$BASE/trace.err")" = 3
    ;;
esac
printf 'modified\n' >vendor/fixture/a/code.php
printf 'untracked\n' >vendor/fixture/b/new.php
parity -v
FAST_COMPOSER_STATUS_GIT=0 parity -v
COMPOSER_MAX_PARALLEL_PROCESSES=1 parity -v
git -C vendor/fixture/a checkout -- code.php
rm vendor/fixture/b/new.php
parity -v
# Native handling of new local commits and changed references must remain intact.
git -C vendor/fixture/a checkout -b status-local-only
printf 'local commit\n' >> vendor/fixture/a/code.php
git -C vendor/fixture/a add code.php
git -C vendor/fixture/a -c user.email=fixture@example.invalid -c user.name=fixture -c commit.gpgsign=false commit -qm local
parity -v
FAST_COMPOSER_STATUS_VERSION=0 parity -v
# A colliding tag name and a remote branch with a different SHA must stay native.
git -C vendor/fixture/b tag main
parity -v
git -C vendor/fixture/b tag -d main
git -C vendor/fixture/c -c user.email=fixture@example.invalid -c user.name=fixture -c commit.gpgsign=false commit --allow-empty -qm divergent
parity -v
FAST_COMPOSER_STATUS_REFS=0 parity -v
