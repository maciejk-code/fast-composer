#!/usr/bin/env bash
set -euo pipefail

FC_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
export COMPOSER_HOME="$WORK/composer-home"
export FAST_COMPOSER_CACHE_DIR="$WORK/cache"
mkdir -p "$COMPOSER_HOME"

FIX="$WORK/fixture"
mkdir -p "$FIX"
git -C "$FIX" init -q -b main
git -C "$FIX" config user.email fast-composer-ci@example.invalid
git -C "$FIX" config user.name fast-composer-ci
cat > "$FIX/composer.json" <<'JSON'
{
  "name": "acme/self-heal",
  "type": "library",
  "require": {"php": ">=8.2"},
  "autoload": {"psr-4": {"Acme\\SelfHeal\\": "src/"}},
  "extra": {"marker": "source"}
}
JSON
mkdir -p "$FIX/src"
printf '%s\n' '<?php namespace Acme\SelfHeal; final class Marker {}' > "$FIX/src/Marker.php"
git -C "$FIX" add .
git -C "$FIX" commit -q -m initial
SOURCE_SHA="$(git -C "$FIX" rev-parse HEAD)"

ROOT="$WORK/root"
mkdir -p "$ROOT"
cat > "$ROOT/composer.json" <<JSON
{
  "name": "acme/root",
  "repositories": [{"type": "vcs", "url": "$FIX"}],
  "require": {"acme/self-heal": "dev-main"},
  "minimum-stability": "dev",
  "prefer-stable": true
}
JSON

cd "$ROOT"
composer update --no-install --no-interaction --no-plugins --no-scripts --no-audit -q
php "$FC_ROOT/bin/fast-composer" refresh >/dev/null

SNAPSHOT="$(find "$FAST_COMPOSER_CACHE_DIR/projects" -name snapshot.json -print -quit)"
if [ -z "$SNAPSHOT" ]; then
  echo "self-heal fixture snapshot not found" >&2
  exit 1
fi

# Simulate the dangerous state: package metadata in the snapshot belongs to some stale source,
# while source.reference still points at the real commit. A normal targeted refresh sees the same
# refs and deliberately keeps the cached package records, so validation must trigger self-healing.
php -r '
$p=$argv[1];$sha=$argv[2];
$s=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
$changed=0;
foreach($s["repos"] as &$repo){
  if(!isset($repo["packages"]) || !is_array($repo["packages"])) continue;
  foreach($repo["packages"] as &$pkg){
    if(($pkg["name"]??null)==="acme/self-heal" && ($pkg["source"]["reference"]??null)===$sha){
      $pkg["extra"]["marker"]="stale-snapshot";
      $changed++;
    }
  }
  unset($pkg);
}
unset($repo);
if($changed===0) throw new RuntimeException("fixture package not found in snapshot");
file_put_contents($p,json_encode($s,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
' "$SNAPSHOT" "$SOURCE_SHA"

LOG="$WORK/self-heal.log"
php "$FC_ROOT/bin/fast-composer" update acme/self-heal --no-install --no-interaction --no-plugins --no-scripts --no-audit -q >"$LOG" 2>&1
cat "$LOG"

grep -q 'retrying dependency graph solve after targeted VCS metadata repair' "$LOG" || {
  echo "REGRESSION: metadata repair did not rerun the solver" >&2
  exit 1
}

php -r '
$l=json_decode(file_get_contents("composer.lock"),true,512,JSON_THROW_ON_ERROR);
foreach($l["packages"] as $pkg){
  if(($pkg["name"]??null)==="acme/self-heal"){
    if(($pkg["extra"]["marker"]??null)!=="source") throw new RuntimeException("stale metadata survived self-heal");
    if(($pkg["source"]["reference"]??null)!==$argv[1]) throw new RuntimeException("source SHA changed unexpectedly");
    exit(0);
  }
}
throw new RuntimeException("self-heal fixture missing from lock");
' "$SOURCE_SHA"

php "$FC_ROOT/bin/fast-composer" verify >/dev/null

echo "metadata-self-heal: PASS"
