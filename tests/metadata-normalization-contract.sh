#!/usr/bin/env bash
set -euo pipefail

FC_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
export COMPOSER_HOME="$WORK/composer-home"
export FAST_COMPOSER_CACHE_DIR="$WORK/cache"
mkdir -p "$COMPOSER_HOME" "$WORK/source" "$WORK/root"
git -C "$WORK/source" init -q -b main
git -C "$WORK/source" config user.email fast-composer-ci@example.invalid
git -C "$WORK/source" config user.name fast-composer-ci
git -C "$WORK/source" config commit.gpgsign false
cat > "$WORK/source/composer.json" <<'JSON'
{
  "name": "acme/normalization-case",
  "type": "library",
  "require": {"php": ">=8.2"},
  "require-dev": {},
  "autoload": {"psr-4": {"Acme\\Normalization\\": "src/"}},
  "autoload-dev": {},
  "extra": {},
  "bin": "bin/command"
}
JSON
mkdir -p "$WORK/source/bin" "$WORK/source/src"
printf '#!/usr/bin/env php\n<?php echo "ok";\n' > "$WORK/source/bin/command"
git -C "$WORK/source" add .
git -C "$WORK/source" commit -qm initial

cat > "$WORK/root/composer.json" <<JSON
{
  "name": "acme/root",
  "repositories": [{"type": "vcs", "url": "$WORK/source"}, {"packagist.org": false}],
  "require": {"acme/normalization-case": "dev-main"},
  "minimum-stability": "dev"
}
JSON
cd "$WORK/root"
composer update --no-install --no-interaction --no-plugins --no-scripts --no-audit -q
php "$FC_ROOT/bin/fast-composer" refresh >/dev/null

echo second > "$WORK/source/another-file.txt"
git -C "$WORK/source" add .
git -C "$WORK/source" commit -qm updated

php "$FC_ROOT/bin/fast-composer" update acme/normalization-case --no-interaction --no-plugins --no-scripts --no-audit -q > "$WORK/fast.log" 2>&1 || {
  cat "$WORK/fast.log"
  exit 1
}
grep -q 'lock verified and published' "$WORK/fast.log" || { cat "$WORK/fast.log"; exit 1; }
cp composer.lock "$WORK/fast.lock"
composer update acme/normalization-case --no-install --no-interaction --no-plugins --no-scripts --no-audit -q
cmp "$WORK/fast.lock" composer.lock || {
  echo 'Fast Composer lock differs from standard Composer on benign metadata normalization' >&2
  diff -u "$WORK/fast.lock" composer.lock || true
  exit 1
}
php "$FC_ROOT/bin/fast-composer" verify >/dev/null
[ ! -d vendor ] || { echo 'lock-only update unexpectedly installed vendor' >&2; exit 1; }
echo 'metadata-normalization-parity-and-validation: PASS'
