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
  "require": {"php": ">=8.1"},
  "autoload": {"psr-4": {"Acme\\Fixture\\": "src/"}},
  "extra": {"marker": "A"}
}
JSON
mkdir -p "$FIX/src"
printf '%s\n' '<?php namespace Acme\Fixture; final class Marker {}' > "$FIX/src/Marker.php"
git -C "$FIX" add .
git -C "$FIX" commit -q -m A
SHA_A="$(git -C "$FIX" rev-parse HEAD)"

git -C "$FIX" checkout -q -b B
cat > "$FIX/composer.json" <<'JSON'
{
  "name": "acme/fixture",
  "type": "library",
  "require": {
    "php": ">=8.1",
    "psr/log": "^3.0"
  },
  "require-dev": {"acme/dev-only": "^1.0"},
  "conflict": {"psr/cache": "<3.0"},
  "provide": {"virtual/logger": "1.0"},
  "replace": {"acme/legacy-logger": "self.version"},
  "suggest": {"ext-json": "Useful for JSON features"},
  "autoload": {"psr-4": {"Acme\\Fixture\\": "lib/"}},
  "extra": {"marker": "B"},
  "bin": ["bin/fixture"]
}
JSON
mkdir -p "$FIX/lib" "$FIX/bin"
printf '%s\n' '<?php namespace Acme\Fixture; final class Marker {}' > "$FIX/lib/Marker.php"
printf '%s\n' '#!/usr/bin/env php' '<?php echo "fixture\n";' > "$FIX/bin/fixture"
chmod +x "$FIX/bin/fixture"
git -C "$FIX" add .
git -C "$FIX" commit -q -m B
SHA_B="$(git -C "$FIX" rev-parse HEAD)"

ROOT="$WORK/root"
mkdir -p "$ROOT"
cat > "$ROOT/composer.json" <<JSON
{
  "name": "acme/root",
  "repositories": [{"type": "vcs", "url": "$FIX"}],
  "require": {"acme/fixture": "dev-A"},
  "minimum-stability": "dev",
  "prefer-stable": true
}
JSON

cd "$ROOT"
composer update --no-install --no-interaction --no-plugins --no-scripts --no-audit -q
php "$FC_ROOT/bin/fast-composer" refresh >/dev/null
php "$FC_ROOT/bin/fast-composer" require acme/fixture:dev-B --no-install --no-interaction --no-plugins --no-scripts --no-audit -q

php -r '
$l=json_decode(file_get_contents("composer.lock"),true);
$p=null; foreach($l["packages"] as $row){if($row["name"]==="acme/fixture"){$p=$row;break;}}
if(!$p) throw new RuntimeException("fixture missing from lock");
$checks=[
  ["require.psr/log",$p["require"]["psr/log"]??null,"^3.0"],
  ["require-dev",$p["require-dev"]["acme/dev-only"]??null,"^1.0"],
  ["conflict",$p["conflict"]["psr/cache"]??null,"<3.0"],
  ["provide",$p["provide"]["virtual/logger"]??null,"1.0"],
  ["replace",$p["replace"]["acme/legacy-logger"]??null,"self.version"],
  ["suggest",$p["suggest"]["ext-json"]??null,"Useful for JSON features"],
  ["autoload",$p["autoload"]["psr-4"]["Acme\\Fixture\\"]??null,"lib/"],
  ["extra",$p["extra"]["marker"]??null,"B"],
  ["bin",$p["bin"][0]??null,"bin/fixture"],
];
foreach($checks as [$name,$actual,$expected]) if($actual!==$expected) throw new RuntimeException("metadata mismatch $name: ".var_export($actual,true));
$hasLog=false; foreach($l["packages"] as $row){if($row["name"]==="psr/log")$hasLog=true;} if(!$hasLog) throw new RuntimeException("branch B dependency psr/log was not resolved");
' 

echo "metadata-regression: PASS"

# Repair content-hash to the real composer.json before testing Composer install behavior.
CONTENT_HASH="$(php -r '
$c=json_decode(file_get_contents("composer.json"),true);
$keys=["name","version","require","require-dev","conflict","replace","provide","minimum-stability","prefer-stable","repositories","extra"];
$r=[]; foreach(array_intersect($keys,array_keys($c)) as $k){$r[$k]=$c[$k];}
if(isset($c["config"]["platform"])){$r["config"]["platform"]=$c["config"]["platform"];}
ksort($r); echo md5(json_encode($r));
')"
php -r '$p="composer.lock";$l=json_decode(file_get_contents($p),true);$l["content-hash"]=$argv[1];file_put_contents($p,json_encode($l,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$CONTENT_HASH"

# Deliberately corrupt the lock: keep source.reference=B but replace B metadata with A-like metadata
# and remove psr/log from the locked set. This simulates the dangerous class of fast-composer bug.
php -r '
$p="composer.lock";$l=json_decode(file_get_contents($p),true);
$out=[];
foreach($l["packages"] as $row){
  if($row["name"]==="psr/log") continue;
  if($row["name"]==="acme/fixture"){
    $row["require"]=["php"=>">=8.1"];
    foreach(["require-dev","conflict","provide","replace","suggest","bin"] as $k) unset($row[$k]);
    $row["autoload"]=["psr-4"=>["Acme\\Fixture\\"=>"src/"]];
    $row["extra"]=["marker"=>"A"];
  }
  $out[]=$row;
}
$l["packages"]=$out;
file_put_contents($p,json_encode($l,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
'

rm -rf vendor
set +e
composer install --no-interaction --no-plugins --no-scripts --no-audit > "$WORK/install.log" 2>&1
INSTALL_CODE=$?
set -e
cat "$WORK/install.log"
if [ "$INSTALL_CODE" -ne 0 ]; then
  echo "standard-composer-install-rejected-corrupt-metadata: PASS"
  exit 0
fi

echo "standard-composer-install-accepted-corrupt-metadata: OBSERVED"

# Regression expectation: fast-composer must reject a reachable SHA whose locked metadata
# does not match composer.json at that exact SHA.
set +e
php "$FC_ROOT/bin/fast-composer" verify >/tmp/fast-composer-verify.log 2>&1
VERIFY_CODE=$?
set -e
cat /tmp/fast-composer-verify.log
if [ "$VERIFY_CODE" -eq 0 ]; then
  echo "REGRESSION: fast-composer failed to detect lock/source metadata mismatch" >&2
  exit 1
fi

echo "metadata-source-verification: PASS"
echo "A=$SHA_A B=$SHA_B"
