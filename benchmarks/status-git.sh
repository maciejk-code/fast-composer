#!/usr/bin/env bash
# Compare clean Git source status without network latency on the same installation.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$(mktemp -d)"
trap 'rm -rf "$BASE"' EXIT
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
export COMPOSER_HOME="$BASE/home" COMPOSER_CACHE_DIR="$BASE/cache"
export FAST_COMPOSER_STATUS=1 FAST_COMPOSER_STATUS_GIT=1 FAST_COMPOSER_STATUS_TRACE=0
export COMPOSER_MAX_PARALLEL_PROCESSES=8
COUNT="${BENCH_STATUS_GIT_PACKAGES:-24}"
FILES="${BENCH_STATUS_GIT_FILES:-100}"
RUNS="${BENCH_RUNS:-5}"
mkdir "$BASE/project"
for ((i=0;i<COUNT;i++)); do
  repo="$BASE/pkg-$i"
  git init -q -b main "$repo"
  git -C "$repo" config user.email fixture@example.invalid
  git -C "$repo" config user.name fixture
  printf '{"name":"bench/pkg-%s"}\n' "$i" > "$repo/composer.json"
  php -r 'for($j=0;$j<(int)$argv[2];$j++){file_put_contents($argv[1]."/file-".$j.".php",str_repeat("x",1024));}' "$repo" "$FILES"
  git -C "$repo" add .
  git -C "$repo" -c commit.gpgsign=false commit -qm initial
  git -C "$repo" -c tag.gpgsign=false tag 1.0.0
done
cd "$BASE/project"
php -r '$p=["name"=>"bench/root","repositories"=>[["packagist.org"=>false]],"require"=>[]];for($i=0;$i<(int)$argv[2];$i++){$name="bench/pkg-".$i;$p["repositories"][]=["type"=>"vcs","url"=>$argv[1]."/pkg-".$i];$p["require"][$name]="1.0.0";}file_put_contents("composer.json",json_encode($p));' "$BASE" "$COUNT"
composer update --prefer-source --no-audit --no-interaction --no-plugins --no-scripts
composer status --no-ansi >"$BASE/standard.out" 2>"$BASE/standard.err"
php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/fast.out" 2>"$BASE/fast.err"
diff -u "$BASE/standard.out" "$BASE/fast.out"
diff -u "$BASE/standard.err" "$BASE/fast.err"
FAST_COMPOSER_STATUS_TRACE=1 php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/trace.out" 2>"$BASE/trace.err"
grep -Fx "[fast-composer] parallel Git status proved $COUNT clean checkouts" "$BASE/trace.err"
for ((i=0;i<RUNS;i++)); do
  if ((i%2==0)); then modes=(standard fast); else modes=(fast standard); fi
  for mode in "${modes[@]}"; do
    start="$(php -r 'echo microtime(true);')"
    if [[ "$mode" == fast ]]; then
      php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/fast.out" 2>"$BASE/fast.err"
    else
      composer status --no-ansi >"$BASE/standard.out" 2>"$BASE/standard.err"
    fi
    end="$(php -r 'echo microtime(true);')"
    php -r 'printf("%.6f\n",$argv[2]-$argv[1]);' "$start" "$end" >>"$BASE/$mode.times"
  done
  diff -u "$BASE/standard.out" "$BASE/fast.out"
  diff -u "$BASE/standard.err" "$BASE/fast.err"
done
result="$(php -r 'function median($f){$a=array_map("floatval",file($f));sort($a);$n=count($a);return ($a[intdiv($n-1,2)]+$a[intdiv($n,2)])/2;}$a=median($argv[1]);$b=median($argv[2]);printf("Composer: %.3fs | Parallel Git: %.3fs (%.2fx)\n",$a,$b,$a/$b);' "$BASE/standard.times" "$BASE/fast.times")"
printf '%s\n' "$result"
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  printf '### Git status (%s checkouts, %s files/checkout, %s runs)\n\n%s\n\n' "$COUNT" "$FILES" "$RUNS" "$result" >> "$GITHUB_STEP_SUMMARY"
fi
