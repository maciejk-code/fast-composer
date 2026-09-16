#!/usr/bin/env bash
set -euo pipefail

FC_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
export COMPOSER_HOME="$WORK/composer-home"
mkdir -p "$COMPOSER_HOME"

git config --global user.email fast-composer-ci@example.invalid
git config --global user.name fast-composer-ci

FIX="$WORK/fixture"
mkdir -p "$FIX"
git -C "$FIX" init -q -b A
cat > "$FIX/composer.json" <<'JSON'
{
  "name": "acme/fixture",
  "type": "library",
  "require": {"php": ">=8.1"}
}
JSON
git -C "$FIX" add composer.json
git -C "$FIX" commit -q -m A

git -C "$FIX" checkout -q -b B
cat > "$FIX/composer.json" <<'JSON'
{
  "name": "acme/fixture",
  "type": "library",
  "require": {
    "php": ">=8.1",
    "psr/log": "^3.0"
  },
  "conflict": {"psr/cache": "<3.0"},
  "provide": {"virtual/logger": "1.0"},
  "replace": {"acme/legacy-logger": "self.version"}
}
JSON
git -C "$FIX" add composer.json
git -C "$FIX" commit -q -m B

# A source-level conflict that the real Composer solver should reject during the fast update itself.
git -C "$FIX" checkout -q -b C
cat > "$FIX/composer.json" <<'JSON'
{
  "name": "acme/fixture",
  "type": "library",
  "require": {
    "php": ">=8.1",
    "psr/log": "^3.0"
  },
  "conflict": {"psr/log": "*"},
  "provide": {"virtual/logger": "2.0"},
  "replace": {"acme/legacy-logger": "self.version"}
}
JSON
git -C "$FIX" add composer.json
git -C "$FIX" commit -q -m C

# A branch that can be locked optimistically only when the local developer explicitly ignores
# the platform requirement. CI composer install must reject it on a normal platform check.
git -C "$FIX" checkout -q -b P B
cat > "$FIX/composer.json" <<'JSON'
{
  "name": "acme/fixture",
  "type": "library",
  "require": {
    "php": ">=8.1",
    "psr/log": "^3.0",
    "ext-fast-composer-never": "*"
  },
  "conflict": {"psr/cache": "<3.0"},
  "provide": {"virtual/logger": "1.0"},
  "replace": {"acme/legacy-logger": "self.version"}
}
JSON
git -C "$FIX" add composer.json
git -C "$FIX" commit -q -m P

ROOT="$WORK/root"
mkdir -p "$ROOT"
cat > "$ROOT/composer.json" <<JSON
{
  "name": "acme/root",
  "repositories": [{"type": "vcs", "url": "$FIX"}],
  "require": {"acme/fixture": "dev-B"},
  "minimum-stability": "dev",
  "prefer-stable": true
}
JSON

cd "$ROOT"
composer update --no-install --no-interaction --no-plugins --no-scripts --no-audit -q
php "$FC_ROOT/bin/fast-composer" refresh >/dev/null
cp composer.json "$WORK/valid-b-composer.json"
cp composer.lock "$WORK/valid-b.lock"

# 1. Accurate package metadata says psr/log ^3, but the package is missing from the locked set.
# Composer install must reject this internally inconsistent lock.
php -r '
$p="composer.lock";$l=json_decode(file_get_contents($p),true);
$l["packages"]=array_values(array_filter($l["packages"],fn($x)=>$x["name"]!=="psr/log"));
file_put_contents($p,json_encode($l,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
'
rm -rf vendor
set +e
composer install --no-interaction --no-plugins --no-scripts > "$WORK/inconsistent.log" 2>&1
CODE=$?
set -e
cat "$WORK/inconsistent.log"
if [ "$CODE" -eq 0 ]; then
  echo "REGRESSION: Composer install accepted an internally inconsistent locked dependency set" >&2
  exit 1
fi
echo "composer-install-rejects-inconsistent-locked-set: PASS"

# 2. A managed VCS package points at an unavailable SHA. Explicit fast-composer verify must reject it,
# and a clean standard Composer install must also fail when it tries to materialize the source.
cp "$WORK/valid-b-composer.json" composer.json
cp "$WORK/valid-b.lock" composer.lock
php -r '
$p="composer.lock";$l=json_decode(file_get_contents($p),true);
foreach($l["packages"] as &$x){if($x["name"]==="acme/fixture"){$x["source"]["reference"]=str_repeat("0",40);}} unset($x);
file_put_contents($p,json_encode($l,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
'
set +e
php "$FC_ROOT/bin/fast-composer" verify > "$WORK/stale-verify.log" 2>&1
VERIFY_CODE=$?
set -e
cat "$WORK/stale-verify.log"
if [ "$VERIFY_CODE" -eq 0 ] || ! grep -q '^MISSING acme/fixture ' "$WORK/stale-verify.log"; then
  echo "REGRESSION: fast-composer verify accepted unavailable managed VCS SHA" >&2
  exit 1
fi
echo "fast-verify-rejects-unavailable-sha: PASS"

rm -rf vendor
set +e
composer install --no-interaction --no-plugins --no-scripts > "$WORK/stale-install.log" 2>&1
INSTALL_CODE=$?
set -e
if [ "$INSTALL_CODE" -eq 0 ]; then
  cat "$WORK/stale-install.log"
  echo "REGRESSION: Composer install accepted unavailable VCS SHA" >&2
  exit 1
fi
echo "composer-install-rejects-unavailable-sha: PASS"

# 3. Changed conflict metadata is part of the targeted package metadata. Because Fast Composer still
# invokes the real Composer solver, an impossible require/conflict pair must fail locally and must
# not modify the real composer.json or composer.lock.
cp "$WORK/valid-b-composer.json" composer.json
cp "$WORK/valid-b.lock" composer.lock
php "$FC_ROOT/bin/fast-composer" refresh >/dev/null
BEFORE_JSON="$(sha256sum composer.json | awk '{print $1}')"
BEFORE_LOCK="$(sha256sum composer.lock | awk '{print $1}')"
set +e
php "$FC_ROOT/bin/fast-composer" require acme/fixture:dev-C --no-install --no-interaction --no-plugins --no-scripts --no-audit -q > "$WORK/conflict.log" 2>&1
CONFLICT_CODE=$?
set -e
cat "$WORK/conflict.log"
if [ "$CONFLICT_CODE" -eq 0 ]; then
  echo "REGRESSION: fast-composer accepted impossible conflict from changed VCS metadata" >&2
  exit 1
fi
AFTER_JSON="$(sha256sum composer.json | awk '{print $1}')"
AFTER_LOCK="$(sha256sum composer.lock | awk '{print $1}')"
if [ "$BEFORE_JSON" != "$AFTER_JSON" ] || [ "$BEFORE_LOCK" != "$AFTER_LOCK" ]; then
  echo "REGRESSION: failed fast update modified real project files" >&2
  exit 1
fi
echo "changed-conflict-rejected-without-touching-real-lock: PASS"

# 4. Platform requirement: allow the local optimistic update explicitly, then make ordinary
# composer install act as the CI verifier without ignore-platform-reqs.
cp "$WORK/valid-b-composer.json" composer.json
cp "$WORK/valid-b.lock" composer.lock
php "$FC_ROOT/bin/fast-composer" refresh >/dev/null
php "$FC_ROOT/bin/fast-composer" require acme/fixture:dev-P --ignore-platform-req=ext-fast-composer-never --no-install --no-interaction --no-plugins --no-scripts --no-audit -q
php -r '
$l=json_decode(file_get_contents("composer.lock"),true);
foreach($l["packages"] as $x){if($x["name"]==="acme/fixture" && (($x["require"]["ext-fast-composer-never"]??null)!=="*")) throw new RuntimeException("platform requirement missing from lock");}
'
rm -rf vendor
set +e
composer install --no-interaction --no-plugins --no-scripts > "$WORK/platform.log" 2>&1
PLATFORM_CODE=$?
set -e
cat "$WORK/platform.log"
if [ "$PLATFORM_CODE" -eq 0 ]; then
  echo "REGRESSION: Composer install accepted missing platform extension" >&2
  exit 1
fi
if ! grep -q 'ext-fast-composer-never' "$WORK/platform.log"; then
  echo "REGRESSION: platform failure did not identify expected extension" >&2
  exit 1
fi
echo "composer-install-rejects-missing-platform-requirement: PASS"

echo "ci-verifier-contract: PASS"
