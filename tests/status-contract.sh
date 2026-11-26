#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$(mktemp -d)"
trap 'rm -rf "$BASE"' EXIT
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
export COMPOSER_HOME="$BASE/home" COMPOSER_CACHE_DIR="$BASE/cache" FAST_COMPOSER_CACHE_DIR="$BASE/fast-cache"
mkdir -p "$BASE/pkg/src" "$BASE/project"
printf '{"name":"fixture/status","version":"1.0.0"}\n' > "$BASE/pkg/composer.json"
printf 'original\n' > "$BASE/pkg/src/file.php"
printf 'other\n' > "$BASE/pkg/src/other.php"
(cd "$BASE" && zip -qr package.zip pkg)
cd "$BASE/project"
php -r '$p=["name"=>"fixture/root","repositories"=>[["packagist.org"=>false],["type"=>"package","package"=>["name"=>"fixture/status","version"=>"1.0.0","dist"=>["type"=>"zip","url"=>$argv[1]]]]],"require"=>["fixture/status"=>"1.0.0"]];file_put_contents("composer.json",json_encode($p));' "$BASE/package.zip"
composer update --no-audit --no-interaction --no-plugins --no-scripts
cp composer.json "$BASE/before.json"
cp composer.lock "$BASE/before.lock"

parity() {
  local expected=0 actual=0
  composer status --no-ansi "$@" >"$BASE/standard.out" 2>"$BASE/standard.err" || expected=$?
  php "$ROOT/bin/fast-composer" composer-status --no-ansi "$@" >"$BASE/fast.out" 2>"$BASE/fast.err" || actual=$?
  test "$expected" = "$actual"
  diff -u "$BASE/standard.out" "$BASE/fast.out"
  diff -u "$BASE/standard.err" "$BASE/fast.err"
  cmp composer.json "$BASE/before.json"
  cmp composer.lock "$BASE/before.lock"
}
parity
parity -v
case "$(composer --version --no-ansi 2>&1)" in
  *"Composer version 2.8.12 "*|*"Composer version 2.9.5 "*|*"Composer version 2.10.2 "*|*"Composer version 2.10.3 "*)
    if php -r 'exit(extension_loaded("zip") && in_array(PHP_OS_FAMILY,["Linux","Darwin"],true)?0:1);'; then
      FAST_COMPOSER_STATUS_TRACE=1 php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/trace.out" 2>"$BASE/trace.err"
      grep -F 'compared cached ZIP without extraction: fixture/status' "$BASE/trace.err" || { cat "$BASE/trace.err" >&2; exit 1; }
    fi
    ;;
esac
printf 'modified\n' > vendor/fixture/status/src/file.php
parity
parity -v
printf 'original\n' > vendor/fixture/status/src/file.php
rm vendor/fixture/status/src/other.php
parity -v
printf 'other\n' > vendor/fixture/status/src/other.php
printf 'added\n' > vendor/fixture/status/src/added.php
parity -v
rm vendor/fixture/status/src/added.php
ln -s file.php vendor/fixture/status/src/link.php
parity -v
rm vendor/fixture/status/src/link.php
FAST_COMPOSER_STATUS=0 parity -v
composer clear-cache --no-ansi
parity -v
code=0
php "$ROOT/bin/fast-composer" status >"$BASE/snapshot.out" || code=$?
test "$code" = 1
grep -F 'snapshot: missing' "$BASE/snapshot.out"
