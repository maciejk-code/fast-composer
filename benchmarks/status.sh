#!/usr/bin/env bash
# Compare warm, clean ZIP status on the same installation.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$(mktemp -d)"
trap 'rm -rf "$BASE"' EXIT
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
export COMPOSER_HOME="$BASE/home" COMPOSER_CACHE_DIR="$BASE/cache"
export FAST_COMPOSER_STATUS=1 FAST_COMPOSER_STATUS_FINGERPRINTS=1 FAST_COMPOSER_STATUS_TRACE=0 FAST_COMPOSER_STATUS_ZIP_PARALLEL=1
export COMPOSER_MAX_PARALLEL_PROCESSES=4
umask 077
COUNT="${BENCH_STATUS_PACKAGES:-40}"
FILES="${BENCH_STATUS_FILES:-150}"
RUNS="${BENCH_RUNS:-5}"
mkdir -p "$BASE/project"
php -r '
$base=$argv[1];$n=(int)$argv[2];$count=(int)$argv[3];
$p=["name"=>"bench/root","repositories"=>[["packagist.org"=>false]],"require"=>[]];
for($i=0;$i<$n;$i++){
    $name="bench/package-".$i;$dir=$base."/pkg-".$i;mkdir($dir);
    file_put_contents($dir."/composer.json",json_encode(["name"=>$name,"version"=>"1.0.0"]));
    for($j=0;$j<$count;$j++){file_put_contents($dir."/file-".$j.".php",random_bytes(1024));}
    $zip=$base."/pkg-".$i.".zip";
    $z=new ZipArchive();if($z->open($zip,ZipArchive::CREATE)!==true){exit(1);}
    foreach(glob($dir."/*") as $f){$z->addFile($f,"package/".basename($f));}
    $z->close();
    $p["repositories"][]=["type"=>"package","package"=>["name"=>$name,"version"=>"1.0.0","dist"=>["type"=>"zip","url"=>$zip]]];
    $p["require"][$name]="1.0.0";
}
file_put_contents($base."/project/composer.json",json_encode($p));' "$BASE" "$COUNT" "$FILES"
cd "$BASE/project"
composer update --no-interaction --no-audit --no-plugins --no-scripts
composer status --no-ansi >"$BASE/standard.out" 2>"$BASE/standard.err"
php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/fast.out" 2>"$BASE/fast.err"
diff -u "$BASE/standard.out" "$BASE/fast.out"
diff -u "$BASE/standard.err" "$BASE/fast.err"
FAST_COMPOSER_STATUS_TRACE=1 php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/trace.out" 2>"$BASE/trace.err"
grep -Fx "[fast-composer] parallel ZIP proved $COUNT clean packages" "$BASE/trace.err"
actual="$(grep -cF 'compared cached ZIP without extraction:' "$BASE/trace.err" || true)"
printf 'Accelerated packages: %s/%s\n' "$actual" "$COUNT"
if [[ "$actual" != "$COUNT" ]]; then
  echo 'Fixture did not exercise the ZIP fast path; refusing to report acceleration.' >&2
  exit 1
fi
fingerprint_dir="$COMPOSER_CACHE_DIR/fast-composer-status-v1"
fingerprints="$(find "$fingerprint_dir" -maxdepth 1 -type f -name '*.json' | wc -l | tr -d '[:space:]')"
if [[ "$fingerprints" != "$COUNT" ]]; then
  echo "Expected $COUNT cached fingerprints, found $fingerprints" >&2
  exit 1
fi
for ((i=0;i<RUNS;i++)); do
  if ((i%2==0)); then modes=(standard stream serial fast); else modes=(fast serial stream standard); fi
  for mode in "${modes[@]}"; do
    start="$(php -r 'echo microtime(true);')"
    if [[ "$mode" == fast ]]; then
      php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/fast.out" 2>"$BASE/fast.err"
    elif [[ "$mode" == serial ]]; then
      FAST_COMPOSER_STATUS_ZIP_PARALLEL=0 php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/fast.out" 2>"$BASE/fast.err"
    elif [[ "$mode" == stream ]]; then
      FAST_COMPOSER_STATUS_ZIP_PARALLEL=0 FAST_COMPOSER_STATUS_FINGERPRINTS=0 php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/fast.out" 2>"$BASE/fast.err"
    else
      composer status --no-ansi >"$BASE/standard.out" 2>"$BASE/standard.err"
    fi
    end="$(php -r 'echo microtime(true);')"
    php -r 'printf("%.6f\n",$argv[2]-$argv[1]);' "$start" "$end" >>"$BASE/$mode.times"
    diff -u "$BASE/standard.out" "$BASE/fast.out"
    diff -u "$BASE/standard.err" "$BASE/fast.err"
  done
done
result="$(php -r 'function median($f){$a=array_map("floatval",file($f));sort($a);$n=count($a);return ($a[intdiv($n-1,2)]+$a[intdiv($n,2)])/2;}$a=median($argv[1]);$b=median($argv[2]); $s=median($argv[3]);$c=median($argv[4]);printf("Composer: %.3fs | Streaming ZIP: %.3fs (%.2fx) | Serial fingerprints: %.3fs (%.2fx) | Parallel ZIP: %.3fs (%.2fx)\n",$a,$s,$a/$s,$c,$a/$c,$b,$a/$b);' "$BASE/standard.times" "$BASE/fast.times" "$BASE/stream.times" "$BASE/serial.times")"
printf '%s\n' "$result"
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  printf '### ZIP status (%s packages, %s files/package, %s runs)\n\n%s\n\n' "$COUNT" "$FILES" "$RUNS" "$result" >> "$GITHUB_STEP_SUMMARY"
fi
# Verify mixed clean/dirty batches with same-size changes (not just the clean fast path).
php -r '$p=$argv[1];$s=file_get_contents($p);$s[0]=chr(ord($s[0]) ^ 1);file_put_contents($p,$s);' vendor/bench/package-0/file-0.php
native=0; fast=0
composer status --no-ansi -v >"$BASE/standard.out" 2>"$BASE/standard.err" || native=$?
php "$ROOT/bin/fast-composer" composer-status --no-ansi -v >"$BASE/fast.out" 2>"$BASE/fast.err" || fast=$?
test "$native" -ne 0
test "$native" = "$fast"
diff -u "$BASE/standard.out" "$BASE/fast.out"
diff -u "$BASE/standard.err" "$BASE/fast.err"
