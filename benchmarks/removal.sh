#!/usr/bin/env bash
# Real Composer ZIP package removal, with synthetic node_modules.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$(mktemp -d)"
trap 'rm -rf "$BASE"' EXIT
COUNT="${BENCH_NODE_FILES:-40000}"
FILE_BYTES="${BENCH_NODE_FILE_BYTES:-7}"
# Both fixtures are created and deleted sequentially, so disk usage is not doubled.
php -r '$n=(int)$argv[1];$s=(int)$argv[2];printf("Fixture: %d files, %.3f GiB logical data\n",$n,$n*$s/1073741824);' "$COUNT" "$FILE_BYTES"
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
mkdir "$BASE/package"
printf '{"name":"bench/package","version":"1.0.0"}\n' > "$BASE/package/composer.json"
(cd "$BASE" && zip -qr package.zip package)
for mode in standard fast; do
  project="$BASE/$mode"
  mkdir "$project"
  (
    cd "$project"
    export COMPOSER_CACHE_DIR="$BASE/cache-$mode"
    php -r '$p=["name"=>"bench/root","repositories"=>[["packagist.org"=>false],["type"=>"package","package"=>["name"=>"bench/package","version"=>"1.0.0","dist"=>["type"=>"zip","url"=>$argv[1]]]]],"require"=>["bench/package"=>"1.0.0"]];file_put_contents("composer.json",json_encode($p));' "$BASE/package.zip"
    composer update --no-audit --no-interaction --no-plugins --no-scripts
    mkdir vendor/bench/package/node_modules
    php -r '$n=(int)$argv[1];$size=(int)$argv[2];$data=random_bytes($size);for($i=0;$i<$n;$i++){if(file_put_contents("vendor/bench/package/node_modules/file-".$i,$data)!==$size){exit(1);}}' "$COUNT" "$FILE_BYTES"
    php -r '$p=json_decode(file_get_contents("composer.json"),true);$p["require"]=(object)[];file_put_contents("composer.json",json_encode($p));'
    composer update --no-install --no-audit --no-interaction --no-plugins --no-scripts
    start="$(php -r 'echo microtime(true);')"
    if [[ "$mode" == fast ]]; then
      php "$ROOT/bin/fast-composer" install --no-interaction --no-plugins --no-scripts 2>&1 | tee "$BASE/remove.log"
      if [[ "${FAST_COMPOSER_ASSERT_ACCELERATION:-0}" == 1 ]]; then
        grep -Fq "[fast-composer] deferred package removal" "$BASE/remove.log"
      fi
    else
      composer install --no-interaction --no-plugins --no-scripts
    fi
    end="$(php -r 'echo microtime(true);')"
    test ! -e vendor/bench/package
    if compgen -G '.fast-composer-trash-*' >/dev/null; then exit 1; fi
    php -r 'printf("%s total removal: %.3f s\n",$argv[1],$argv[3]-$argv[2]);' "$mode" "$start" "$end" | tee -a "$BASE/results"
  )
done
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  { echo '### Package removal benchmark'; echo '~~~'; cat "$BASE/results"; echo '~~~'; } >> "$GITHUB_STEP_SUMMARY"
fi
