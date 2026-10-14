#!/usr/bin/env bash
# Exercise the REAL Composer CLI with the globally installed optional plugin.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
export COMPOSER_HOME="$WORK/composer-home"
export FAST_COMPOSER_CACHE_DIR="$WORK/fast-cache"
mkdir -p "$COMPOSER_HOME"

git init -q -b main "$WORK/repo"
git -C "$WORK/repo" config user.name fast-composer-ci
git -C "$WORK/repo" config user.email fast-composer-ci@example.invalid
cat > "$WORK/repo/composer.json" <<'JSON'
{"name":"acme/plugin-fixture","type":"library","require":{"php":">=8.2"},"extra":{"marker":"one"}}
JSON
git -C "$WORK/repo" add composer.json
git -C "$WORK/repo" commit -qm one
git -C "$WORK/repo" tag 1.0.0

mkdir -p "$WORK/project"
cat > "$WORK/project/composer.json" <<JSON
{
  "name":"acme/plugin-root",
  "repositories":[{"type":"vcs","url":"$WORK/repo"},{"packagist.org":false}],
  "require":{"acme/plugin-fixture":"^1.0"},
  "scripts":{"pre-update-cmd":"echo update-hook-ran"}
}
JSON

# A path-based global plugin install also exercises the package metadata and activation.
composer global config repositories.fast-composer path "$ROOT"
composer global config allow-plugins.maciejk-code/fast-composer true
composer global require 'maciejk-code/fast-composer:@dev' --no-interaction --no-scripts -q
FC="$COMPOSER_HOME/vendor/bin/fast-composer"
test -f "$FC"

cd "$WORK/project"
"$FC" cache warm > "$WORK/warm.log"
test ! -e composer.lock
test ! -e vendor

# Run regular Composer with its own solver, preserving normal update hooks.
FAST_COMPOSER_PLUGIN=1 composer update --no-interaction --no-audit -vvv > "$WORK/first.log" 2>&1 || {
  cat "$WORK/first.log" >&2
  exit 1
}
grep -q 'Injected.*locally cached VCS versions' "$WORK/first.log" || {
  echo "global plugin did not activate on ordinary composer update" >&2
  cat "$WORK/first.log" >&2
  exit 1
}
grep -q 'update-hook-ran' "$WORK/first.log"
test -f vendor/acme/plugin-fixture/composer.json
php -r '
$l=json_decode(file_get_contents("composer.lock"),true,512,JSON_THROW_ON_ERROR);
if (($l["packages"][0]["version"] ?? null) !== "1.0.0") throw new RuntimeException("bad first tag");
'

# A new VCS tag must become visible in STRICT mode WITHOUT explicitly warming.
cat > "$WORK/repo/composer.json" <<'JSON'
{"name":"acme/plugin-fixture","type":"library","require":{"php":">=8.2"},"extra":{"marker":"two"}}
JSON
git -C "$WORK/repo" add composer.json
git -C "$WORK/repo" commit -qm two
git -C "$WORK/repo" tag 1.1.0
FAST_COMPOSER_PLUGIN=1 composer update --no-interaction --no-audit --no-scripts -vvv > "$WORK/second.log" 2>&1 || {
  cat "$WORK/second.log" >&2
  exit 1
}
grep -q 'Injected.*locally cached VCS versions' "$WORK/second.log"
grep -q 'Strict remote verification passed (1 repositories changed)' "$WORK/second.log"
php -r '
$l=json_decode(file_get_contents("composer.lock"),true,512,JSON_THROW_ON_ERROR);
if (($l["packages"][0]["version"] ?? null) !== "1.1.0") throw new RuntimeException("new tag not selected from warmed snapshot");
if (($l["packages"][0]["extra"]["marker"] ?? null) !== "two") throw new RuntimeException("new tag metadata not selected");
'


# Strict mode must track mutable development branch SHAs and changed composer.json metadata.
git -C "$WORK/repo" checkout -qb feature
cat > "$WORK/repo/composer.json" <<'JSON'
{"name":"acme/plugin-fixture","type":"library","require":{"php":">=8.2"},"extra":{"marker":"dev-one"}}
JSON
git -C "$WORK/repo" add composer.json
git -C "$WORK/repo" commit -qm dev-one
php -r '
$p="composer.json";
$j=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
$j["require"]["acme/plugin-fixture"]="dev-feature";
$j["minimum-stability"]="dev";
file_put_contents($p,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
'
"$FC" cache warm --force > "$WORK/dev-warm.log"
FAST_COMPOSER_PLUGIN=1 composer update --no-install --no-interaction --no-scripts --no-audit -vvv > "$WORK/dev-one.log" 2>&1 || {
  cat "$WORK/dev-one.log" >&2
  exit 1
}
git -C "$WORK/repo" rev-parse HEAD > "$WORK/dev-first.sha"

cat > "$WORK/repo/composer.json" <<'JSON'
{"name":"acme/plugin-fixture","type":"library","require":{"php":">=8.2"},"extra":{"marker":"dev-two"}}
JSON
git -C "$WORK/repo" add composer.json
git -C "$WORK/repo" commit -qm dev-two
LATEST_SHA="$(git -C "$WORK/repo" rev-parse HEAD)"
FAST_COMPOSER_PLUGIN=1 composer update --no-install --no-interaction --no-scripts --no-audit -vvv > "$WORK/dev-two.log" 2>&1 || {
  cat "$WORK/dev-two.log" >&2
  exit 1
}
grep -q 'Strict remote verification passed (1 repositories changed)' "$WORK/dev-two.log"
php -r '
$l=json_decode(file_get_contents("composer.lock"),true,512,JSON_THROW_ON_ERROR);
$p=$l["packages"][0];
if (($p["version"] ?? null)!=="dev-feature") throw new RuntimeException("dev version not selected");
if (($p["source"]["reference"] ?? null)!==$argv[1]) throw new RuntimeException("dev SHA stale");
if (($p["extra"]["marker"] ?? null)!=="dev-two") throw new RuntimeException("dev metadata stale");
' "$LATEST_SHA"

# Prove that a real Composer solve can use the warmed VCS metadata without
# contacting the original VCS remote (not merely that a debug line was printed).
mv "$WORK/repo" "$WORK/repo-temporarily-offline"
FAST_COMPOSER_PLUGIN=1 FAST_COMPOSER_PLUGIN_MODE=offline composer update --no-install --no-interaction --no-audit --no-scripts -vvv > "$WORK/offline.log" 2>&1 || {
  cat "$WORK/offline.log" >&2
  exit 1
}
grep -q 'Injected.*locally cached VCS versions' "$WORK/offline.log"
if grep -q 'git remote update' "$WORK/offline.log"; then
  echo "warmed update still caused a remote VCS refresh" >&2
  exit 1
fi
# Strict mode refuses to inject unverified refs when the remote cannot be reached.
FAST_COMPOSER_PLUGIN=1 composer update --no-install --no-interaction --no-audit --no-scripts -vvv > "$WORK/strict-failure.log" 2>&1 || true
if grep -q 'Injected.*locally cached VCS versions' "$WORK/strict-failure.log"; then
  echo "strict mode injected unverifiable remote metadata" >&2
  exit 1
fi
grep -q 'Snapshot unavailable' "$WORK/strict-failure.log"
mv "$WORK/repo-temporarily-offline" "$WORK/repo"

# Balanced mode respects a TTL even while a strict check would refresh it.
FAST_COMPOSER_PLUGIN=1 FAST_COMPOSER_PLUGIN_MODE=balanced FAST_COMPOSER_PLUGIN_TTL=0 composer update --no-interaction --no-audit --no-scripts --no-install -vvv > "$WORK/stale.log" 2>&1
if grep -q 'Injected.*locally cached VCS versions' "$WORK/stale.log"; then
  echo "stale snapshot was incorrectly activated" >&2
  exit 1
fi

echo "composer-plugin-contract: PASS"
