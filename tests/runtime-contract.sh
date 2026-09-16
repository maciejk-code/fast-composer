#!/usr/bin/env bash
set -euo pipefail

FC_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export COMPOSER_HOME="$WORK/composer-home"
export FAST_COMPOSER_CACHE_DIR="$WORK/fast-composer-cache"
mkdir -p "$COMPOSER_HOME"

git config --global user.email fast-composer-ci@example.invalid
git config --global user.name fast-composer-ci

FC=(php "$FC_ROOT/bin/fast-composer")

assert_clean_root() {
  local root="$1"
  if [ -d "$root/vendor" ]; then
    echo "fast-composer unexpectedly installed vendor/" >&2
    exit 1
  fi
  if [ -e "$root/.fast-composer" ] || compgen -G "$root/.fast-composer-*" >/dev/null; then
    echo "fast-composer left working files in the project root" >&2
    find "$root" -maxdepth 1 -name '.fast-composer*' -print >&2 || true
    exit 1
  fi
}

FIX="$WORK/fixture"
mkdir -p "$FIX"
git -C "$FIX" init -q -b main
cat > "$FIX/composer.json" <<'JSON'
{
  "name": "acme/runtime-fixture",
  "type": "library",
  "require": {"php": ">=8.2"},
  "extra": {"marker": "1.0"}
}
JSON
git -C "$FIX" add composer.json
git -C "$FIX" commit -q -m '1.0'
git -C "$FIX" tag 1.0.0

# Targeted update must bypass the broad TTL and discover a new stable tag.
ROOT_TAG="$WORK/root-tag"
mkdir -p "$ROOT_TAG"
cat > "$ROOT_TAG/composer.json" <<JSON
{
  "name": "acme/root-tag",
  "repositories": [{"type": "vcs", "url": "$FIX"}],
  "require": {"acme/runtime-fixture": "^1.0"}
}
JSON
cd "$ROOT_TAG"
composer update --no-install --no-interaction --no-plugins --no-scripts --no-audit -q
"${FC[@]}" refresh >/dev/null

cat > "$FIX/composer.json" <<'JSON'
{
  "name": "acme/runtime-fixture",
  "type": "library",
  "require": {"php": ">=8.2"},
  "extra": {"marker": "1.1"}
}
JSON
git -C "$FIX" add composer.json
git -C "$FIX" commit -q -m '1.1'
TAG_SHA="$(git -C "$FIX" rev-parse HEAD)"
git -C "$FIX" tag 1.1.0

FAST_COMPOSER_TTL=99999 "${FC[@]}" update acme/runtime-fixture --no-interaction --no-plugins --no-scripts --no-audit -q >/dev/null
php -r '
$l=json_decode(file_get_contents("composer.lock"),true,512,JSON_THROW_ON_ERROR);
$p=null;foreach($l["packages"] as $row){if($row["name"]==="acme/runtime-fixture"){$p=$row;break;}}
if(!$p)throw new RuntimeException("fixture missing");
if($p["version"]!=="1.1.0")throw new RuntimeException("new tag not selected: ".$p["version"]);
if(($p["extra"]["marker"]??null)!=="1.1")throw new RuntimeException("new tag metadata missing");
if(($p["source"]["reference"]??null)!==$argv[1])throw new RuntimeException("new tag SHA mismatch");
' "$TAG_SHA"
assert_clean_root "$ROOT_TAG"
echo "targeted-new-tag: PASS"

# A root-pinned mutable dev branch must be revalidated even while the broad TTL is fresh.
git -C "$FIX" checkout -q -b feature
cat > "$FIX/composer.json" <<'JSON'
{
  "name": "acme/runtime-fixture",
  "type": "library",
  "require": {"php": ">=8.2"},
  "autoload": {"psr-4": {"Acme\\Runtime\\": "src-v1/"}},
  "extra": {"marker": "feature-1"}
}
JSON
git -C "$FIX" add composer.json
git -C "$FIX" commit -q -m 'feature 1'

ROOT_DEV="$WORK/root-dev"
mkdir -p "$ROOT_DEV"
cat > "$ROOT_DEV/composer.json" <<JSON
{
  "name": "acme/root-dev",
  "repositories": [{"type": "vcs", "url": "$FIX"}],
  "require": {"acme/runtime-fixture": "dev-feature"},
  "minimum-stability": "dev"
}
JSON
cd "$ROOT_DEV"
composer update --no-install --no-interaction --no-plugins --no-scripts --no-audit -q
"${FC[@]}" refresh >/dev/null

cat > "$FIX/composer.json" <<'JSON'
{
  "name": "acme/runtime-fixture",
  "type": "library",
  "require": {"php": ">=8.2"},
  "autoload": {"psr-4": {"Acme\\Runtime\\": "src-v2/"}},
  "extra": {"marker": "feature-2"}
}
JSON
git -C "$FIX" add composer.json
git -C "$FIX" commit -q -m 'feature 2'
DEV_SHA="$(git -C "$FIX" rev-parse HEAD)"

FAST_COMPOSER_TTL=99999 "${FC[@]}" update --no-interaction --no-plugins --no-scripts --no-audit -q >/dev/null
php -r '
$l=json_decode(file_get_contents("composer.lock"),true,512,JSON_THROW_ON_ERROR);
$p=null;foreach($l["packages"] as $row){if($row["name"]==="acme/runtime-fixture"){$p=$row;break;}}
if(!$p)throw new RuntimeException("fixture missing");
if(($p["source"]["reference"]??null)!==$argv[1])throw new RuntimeException("dev branch SHA was not refreshed");
if(($p["extra"]["marker"]??null)!=="feature-2")throw new RuntimeException("dev branch metadata was not refreshed");
if(($p["autoload"]["psr-4"]["Acme\\Runtime\\"]??null)!=="src-v2/")throw new RuntimeException("dev branch autoload metadata was not refreshed");
' "$DEV_SHA"
assert_clean_root "$ROOT_DEV"
echo "mutable-dev-branch: PASS"

if ! find "$FAST_COMPOSER_CACHE_DIR/projects" -name snapshot.json -type f | grep -q .; then
  echo "snapshot was not persisted in the external Fast Composer cache" >&2
  exit 1
fi

echo "external-cache-and-lock-only: PASS"
echo "runtime-contract: PASS"
