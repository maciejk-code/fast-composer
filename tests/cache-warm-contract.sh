#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export COMPOSER_HOME="$WORK/composer-home"
export FAST_COMPOSER_CACHE_DIR="$WORK/cache"
mkdir -p "$COMPOSER_HOME"

FC=(php "$ROOT/bin/fast-composer")
REPO="$WORK/package"
PROJECT="$WORK/project"
mkdir -p "$REPO" "$PROJECT"
git -C "$REPO" init -q -b main
git -C "$REPO" config user.name "fast-composer-ci"
git -C "$REPO" config user.email "fast-composer-ci@example.invalid"

cat > "$REPO/composer.json" <<'JSON'
{"name":"acme/cache-fixture","type":"library","require":{"php":">=8.2"},"extra":{"marker":"v1"}}
JSON
git -C "$REPO" add composer.json
git -C "$REPO" commit -q -m "initial"
git -C "$REPO" tag 1.0.0

cat > "$PROJECT/composer.json" <<JSON
{
  "name":"acme/root-cache-warm",
  "repositories":[{"type":"vcs","url":"$REPO"},{"packagist.org":false}],
  "require":{"acme/cache-fixture":"^1.0"}
}
JSON

cd "$PROJECT"
"${FC[@]}" cache warm > "$WORK/first.log"
grep -q 'cache-warm repositories=1 refreshed=0' "$WORK/first.log"
test ! -e composer.lock
test ! -e vendor
test ! -e .fast-composer

SNAPSHOT="$(find "$FAST_COMPOSER_CACHE_DIR/projects" -name snapshot.json -type f -print -quit)"
test -n "$SNAPSHOT"
cp "$SNAPSHOT" "$WORK/before.json"

FAST_COMPOSER_TTL=999999 "${FC[@]}" cache warm > "$WORK/second.log"
grep -q 'cache-warm repositories=1 refreshed=0' "$WORK/second.log"
cmp "$SNAPSHOT" "$WORK/before.json"

# New tag must be discoverable by an explicit cache warm despite unexpired TTL.
cat > "$REPO/composer.json" <<'JSON'
{"name":"acme/cache-fixture","type":"library","require":{"php":">=8.2"},"extra":{"marker":"v2"}}
JSON
git -C "$REPO" add composer.json
git -C "$REPO" commit -q -m "v2"
git -C "$REPO" tag 1.1.0

FAST_COMPOSER_TTL=999999 "${FC[@]}" cache warm --force > "$WORK/forced.log"
grep -q 'cache-warm repositories=1 refreshed=1' "$WORK/forced.log"
php -r '
$s=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$found=false;
foreach ($s["repos"] as $r) {
    foreach (($r["packages"] ?? []) as $p) {
        if (($p["version"] ?? null) === "1.1.0" && ($p["extra"]["marker"] ?? null) === "v2") $found=true;
    }
}
if (!$found) throw new RuntimeException("new tag missing from warmed snapshot");
' "$SNAPSHOT"
test ! -e composer.lock
test ! -e vendor

# Broad update can solve using the warmed data without re-fetching all VCS refs.
FAST_COMPOSER_TTL=999999 "${FC[@]}" update --no-interaction --no-scripts --no-plugins --no-audit -q > "$WORK/update.log"
php -r '
$l=json_decode(file_get_contents("composer.lock"),true,512,JSON_THROW_ON_ERROR);
foreach ($l["packages"] as $p) {
    if ($p["name"] === "acme/cache-fixture" && $p["version"] === "1.1.0") exit(0);
}
throw new RuntimeException("update did not use the warmed tag");
'
test ! -e vendor

# The opt-in two-stage command uses the verified fast lock, then materializes vendor.
FAST_COMPOSER_TTL=999999 "${FC[@]}" composer update --no-interaction --no-plugins --no-scripts --no-audit -q > "$WORK/two-stage.log"
test -f vendor/acme/cache-fixture/composer.json
grep -q 'running standard composer install' "$WORK/two-stage.log"
php -r '
$l=json_decode(file_get_contents("composer.lock"),true,512,JSON_THROW_ON_ERROR);
if ($l["packages"][0]["version"] !== "1.1.0") throw new RuntimeException("two-stage update changed version");
'
# A dry run must not invoke the installation phase.
rm -rf vendor
FAST_COMPOSER_TTL=999999 "${FC[@]}" composer update --dry-run --no-interaction --no-plugins --no-scripts --no-audit -q > "$WORK/dry-run.log"
test ! -e vendor
if grep -q 'running standard composer install' "$WORK/dry-run.log"; then
  echo "dry-run must not install dependencies" >&2
  exit 1
fi

if "${FC[@]}" cache warm --unknown > "$WORK/invalid.log" 2>&1; then
  echo "cache warm should reject unsupported flags" >&2
  exit 1
fi

echo "cache-warm-contract: PASS"
