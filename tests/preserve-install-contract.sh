#!/usr/bin/env bash
set -euo pipefail

FC_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
export COMPOSER_HOME="$WORK/composer-home"
export COMPOSER_CACHE_DIR="$WORK/composer-cache"
mkdir -p "$COMPOSER_HOME" "$WORK/repos" "$WORK/project"

make_repo() {
  local name="$1" repo="$WORK/repos/$1"
  mkdir -p "$repo"
  git -C "$repo" init -q -b main
  git -C "$repo" config user.email fast-composer-test@example.invalid
  git -C "$repo" config user.name 'Fast Composer Test'
  git -C "$repo" config commit.gpgsign false
  printf '{"name":"acme/%s","type":"library","require":{"php":">=8.2"}}\n' "$name" > "$repo/composer.json"
  echo initial > "$repo/data.txt"
  git -C "$repo" add .
  git -C "$repo" commit -qm initial
}
make_repo dirty-library
make_repo unpublished-library
make_repo clean-library

cat > "$WORK/project/composer.json" <<JSON
{
  "name": "acme/preserve-install-contract",
  "repositories": [
    {"type": "vcs", "url": "$WORK/repos/dirty-library"},
    {"type": "vcs", "url": "$WORK/repos/unpublished-library"},
    {"type": "vcs", "url": "$WORK/repos/clean-library"},
    {"packagist.org": false}
  ],
  "require": {
    "acme/dirty-library": "dev-main",
    "acme/unpublished-library": "dev-main",
    "acme/clean-library": "dev-main"
  },
  "minimum-stability": "dev",
  "config": {"preferred-install": "source", "allow-plugins": false}
}
JSON

cd "$WORK/project"
composer update --no-interaction --no-plugins --no-scripts --no-audit -q

# Protect an uncommitted checkout and a clean branch containing an unpushed commit.
echo 'local uncommitted edit' >> vendor/acme/dirty-library/data.txt
git -C vendor/acme/unpublished-library config user.email fast-composer-test@example.invalid
git -C vendor/acme/unpublished-library config user.name 'Fast Composer Test'
git -C vendor/acme/unpublished-library config commit.gpgsign false
git -C vendor/acme/unpublished-library switch -qc unpublished
echo 'locally committed edit' >> vendor/acme/unpublished-library/data.txt
git -C vendor/acme/unpublished-library add data.txt
git -C vendor/acme/unpublished-library commit -qm unpublished

# Update the upstream Git revisions but keep metadata (and root constraints) identical.
for name in dirty-library unpublished-library clean-library; do
  repo="$WORK/repos/$name"
  echo updated > "$repo/data.txt"
  git -C "$repo" add data.txt
  git -C "$repo" commit -qm updated
done
composer update --no-install --no-interaction --no-plugins --no-scripts --no-audit -q
before="$(sha256sum composer.lock | cut -d' ' -f1)"

php "$FC_ROOT/bin/fast-composer" install --skip-dirty-packages --no-interaction --no-plugins --no-scripts --no-audit -q > "$WORK/install.log" 2>&1 || {
  cat "$WORK/install.log"
  exit 1
}
cat "$WORK/install.log"
grep -q 'preserving local Git package acme/dirty-library' "$WORK/install.log"
grep -q 'preserving local Git package acme/unpublished-library' "$WORK/install.log"
grep -q 'intentionally differs from composer.lock' "$WORK/install.log"
grep -q 'local uncommitted edit' vendor/acme/dirty-library/data.txt
grep -q 'locally committed edit' vendor/acme/unpublished-library/data.txt
grep -q 'updated' vendor/acme/clean-library/data.txt
[ "$before" = "$(sha256sum composer.lock | cut -d' ' -f1)" ]
if compgen -G '.fast-composer-preserve-*' >/dev/null; then
  echo 'temporary files left in project' >&2
  exit 1
fi
echo 'preserve-dirty-git-install: PASS'

# When package metadata differs, installing the other packages would be unsafe.
php -r '
$p="composer.lock";$l=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
foreach($l["packages"] as &$pkg){if($pkg["name"]==="acme/dirty-library"){$pkg["require"]["ext-zip"]="*";break;}}
file_put_contents($p,json_encode($l,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
'
if php "$FC_ROOT/bin/fast-composer" install --skip-dirty-packages --no-interaction --no-plugins --no-scripts --no-audit -q > "$WORK/refused.log" 2>&1; then
  echo 'expected metadata mismatch to fail closed' >&2
  exit 1
fi
grep -q 'locked require differs from installed metadata' "$WORK/refused.log"
grep -q 'local uncommitted edit' vendor/acme/dirty-library/data.txt
echo 'preserve-install-metadata-refusal: PASS'
