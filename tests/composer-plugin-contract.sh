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

# A new VCS tag is visible after warming, without changing the Composer command.
cat > "$WORK/repo/composer.json" <<'JSON'
{"name":"acme/plugin-fixture","type":"library","require":{"php":">=8.2"},"extra":{"marker":"two"}}
JSON
git -C "$WORK/repo" add composer.json
git -C "$WORK/repo" commit -qm two
git -C "$WORK/repo" tag 1.1.0
"$FC" cache warm --force > "$WORK/re-warm.log"
FAST_COMPOSER_PLUGIN=1 composer update --no-interaction --no-audit --no-scripts -vvv > "$WORK/second.log" 2>&1 || {
  cat "$WORK/second.log" >&2
  exit 1
}
grep -q 'Injected.*locally cached VCS versions' "$WORK/second.log"
php -r '
$l=json_decode(file_get_contents("composer.lock"),true,512,JSON_THROW_ON_ERROR);
if (($l["packages"][0]["version"] ?? null) !== "1.1.0") throw new RuntimeException("new tag not selected from warmed snapshot");
if (($l["packages"][0]["extra"]["marker"] ?? null) !== "two") throw new RuntimeException("new tag metadata not selected");
'

# If the cache is considered stale, the plugin must NOT inject it.
FAST_COMPOSER_PLUGIN=1 FAST_COMPOSER_PLUGIN_TTL=0 composer update --no-interaction --no-audit --no-scripts --no-install -vvv > "$WORK/stale.log" 2>&1
if grep -q 'Injected.*locally cached VCS versions' "$WORK/stale.log"; then
  echo "stale snapshot was incorrectly activated" >&2
  exit 1
fi

echo "composer-plugin-contract: PASS"
