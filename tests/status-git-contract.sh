#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$(mktemp -d)"
trap 'rm -rf "$BASE"' EXIT
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
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
case "$(composer --version --no-ansi | head -n1)" in
  "Composer version 2.8.12 "*|"Composer version 2.9.5 "*|"Composer version 2.10.3 "*)
    FAST_COMPOSER_STATUS_TRACE=1 COMPOSER_MAX_PARALLEL_PROCESSES=4 php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/trace.out" 2>"$BASE/trace.err"
    grep -F 'parallel Git status proved 3 clean checkouts' "$BASE/trace.err"
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
