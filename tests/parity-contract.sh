#!/usr/bin/env bash
# Composer parity: for each scenario run plain Composer and Fast Composer from the same start
# and require byte-identical results (composer.lock, and composer.json for require), or the same
# failure. Every scenario is a Composer behaviour Fast Composer once got wrong.
set -euo pipefail

FC_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export COMPOSER_HOME="$WORK/composer-home"
export COMPOSER_ALLOW_SUPERUSER=1
unset COMPOSER COMPOSER_ROOT_VERSION FAST_COMPOSER_TTL
mkdir -p "$COMPOSER_HOME"
FC=(php "$FC_ROOT/bin/fast-composer")
FLAGS=(--no-interaction --no-plugins --no-scripts --no-audit -q)
FAILED=0

g() { git -c commit.gpgsign=false -c tag.gpgsign=false "$@"; }

# acme/lib: default branch main with a branch alias; tags a Composer must skip (0.3-no-vendor),
# keep with their "v" (v1.2.0), and a duplicate (1.0 vs 1.0.0).
LIB="$WORK/lib"
mkdir -p "$LIB"
g -C "$LIB" init -q -b main
printf '{"name":"acme/lib","type":"library","extra":{"branch-alias":{"dev-main":"2.x-dev"}}}\n' > "$LIB/composer.json"
g -C "$LIB" add composer.json
g -C "$LIB" commit -q -m 1
for tag in 1.0 1.0.0 0.3-no-vendor v1.2.0; do g -C "$LIB" tag "$tag"; done
g -C "$LIB" branch feature

# acme/renamed: an old tag carries another package name (renamed package).
REN="$WORK/renamed"
mkdir -p "$REN"
g -C "$REN" init -q -b main
printf '{"name":"acme/old-name","type":"library"}\n' > "$REN/composer.json"
g -C "$REN" add composer.json
g -C "$REN" commit -q -m 1
g -C "$REN" tag 1.0.0
printf '{"name":"acme/renamed","type":"library"}\n' > "$REN/composer.json"
g -C "$REN" commit -q -am 2
g -C "$REN" tag 2.0.0

# $1 name, $2 composer.json, $3.. command (update ... / require ...)
scenario() {
  local name="$1" json="$2"; shift 2
  local dir="$WORK/case-$name"
  mkdir -p "$dir/composer" "$dir/fast"
  printf '%s\n' "$json" > "$dir/composer/composer.json"
  printf '%s\n' "$json" > "$dir/fast/composer.json"

  local composer_code=0 fast_code=0
  (cd "$dir/composer" && composer "$@" --no-install "${FLAGS[@]}" >"$dir/composer.log" 2>&1) || composer_code=$?
  (cd "$dir/fast" && FAST_COMPOSER_CACHE_DIR="$dir/cache" "${FC[@]}" "$@" "${FLAGS[@]}" >"$dir/fast.log" 2>&1) || fast_code=$?

  local ok=1
  if [ "$composer_code" -ne 0 ] || [ "$fast_code" -ne 0 ]; then
    [ "$composer_code" -ne 0 ] && [ "$fast_code" -ne 0 ] || ok=0
  else
    cmp -s "$dir/composer/composer.lock" "$dir/fast/composer.lock" || ok=0
    cmp -s "$dir/composer/composer.json" "$dir/fast/composer.json" || ok=0
  fi

  if [ "$ok" -eq 1 ]; then
    echo "parity $name: PASS (composer exit $composer_code, fast exit $fast_code)"
  else
    FAILED=1
    echo "parity $name: FAIL (composer exit $composer_code, fast exit $fast_code)" >&2
    diff "$dir/composer/composer.lock" "$dir/fast/composer.lock" >&2 || true
    diff "$dir/composer/composer.json" "$dir/fast/composer.json" >&2 || true
    tail -5 "$dir/fast.log" >&2 || true
  fi
}

# packagist.org is disabled so the scenarios never depend on the network.
NOPACKAGIST='{"packagist.org": false}'
REPOS="\"repositories\": [{\"type\": \"vcs\", \"url\": \"$LIB\"}, {\"type\": \"vcs\", \"url\": \"$REN\"}, $NOPACKAGIST]"

scenario default-branch "{\"name\": \"acme/root\", \"minimum-stability\": \"dev\", $REPOS, \"require\": {\"acme/lib\": \"dev-main\"}}" update
scenario branch-alias "{\"name\": \"acme/root\", \"minimum-stability\": \"dev\", $REPOS, \"require\": {\"acme/lib\": \"^2.0@dev\"}}" update
scenario tags "{\"name\": \"acme/root\", $REPOS, \"require\": {\"acme/lib\": \"^1.0\"}}" update
scenario renamed-package "{\"name\": \"acme/root\", $REPOS, \"require\": {\"acme/renamed\": \"^1.0\"}}" update
scenario repository-exclude "{\"name\": \"acme/root\", \"repositories\": [{\"type\": \"vcs\", \"url\": \"$LIB\", \"exclude\": [\"acme/lib\"]}, $NOPACKAGIST], \"require\": {\"acme/lib\": \"^1.0\"}}" update

# require: composer.json must be edited exactly like Composer does (tabs, inline arrays kept),
# including an empty "require": {} and several packages at once.
TABBED="$(printf '{\n\t"name": "acme/root",\n\t"keywords": ["wordpress", "cms"],\n\t%s,\n\t"require": {}\n}' "$REPOS")"
scenario require-formatting "$TABBED" require acme/lib:^1.0 acme/renamed:^2.0

exit "$FAILED"
