#!/usr/bin/env bash
# Compare clean Git source status without network latency on the same installation.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$(mktemp -d)"
trap 'rm -rf "$BASE"' EXIT
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
export COMPOSER_HOME="$BASE/home" COMPOSER_CACHE_DIR="$BASE/cache"
export FAST_COMPOSER_STATUS=1 FAST_COMPOSER_STATUS_GIT=1 FAST_COMPOSER_STATUS_TRACE=0 FAST_COMPOSER_STATUS_VERSION=1
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
guesses="$(grep -cF 'skipped duplicate version guess:' "$BASE/trace.err" || true)"
test "$guesses" = "$COUNT"
for ((i=0;i<RUNS;i++)); do
  if ((i%2==0)); then modes=(standard local fast); else modes=(fast local standard); fi
  for mode in "${modes[@]}"; do
    start="$(php -r 'echo microtime(true);')"
    if [[ "$mode" == fast ]]; then
      php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/fast.out" 2>"$BASE/fast.err"
    elif [[ "$mode" == local ]]; then
      FAST_COMPOSER_STATUS_VERSION=0 php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/local.out" 2>"$BASE/local.err"
    else
      composer status --no-ansi >"$BASE/standard.out" 2>"$BASE/standard.err"
    fi
    end="$(php -r 'echo microtime(true);')"
    php -r 'printf("%.6f\n",$argv[2]-$argv[1]);' "$start" "$end" >>"$BASE/$mode.times"
  done
  diff -u "$BASE/standard.out" "$BASE/fast.out"
  diff -u "$BASE/standard.err" "$BASE/fast.err"
  diff -u "$BASE/standard.out" "$BASE/local.out"
  diff -u "$BASE/standard.err" "$BASE/local.err"
done
result="$(php -r 'function median($f){$a=array_map("floatval",file($f));sort($a);$n=count($a);return ($a[intdiv($n-1,2)]+$a[intdiv($n,2)])/2;}$a=median($argv[1]);$b=median($argv[2]);$c=median($argv[3]);printf("Composer: %.3fs | Local status only: %.3fs (%.2fx) | Optimized Git: %.3fs (%.2fx)\n",$a,$c,$a/$c,$b,$a/$b);' "$BASE/standard.times" "$BASE/fast.times" "$BASE/local.times")"
printf '%s\n' "$result"
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  printf '### Git status (%s checkouts, %s files/checkout, %s runs)\n\n%s\n\n' "$COUNT" "$FILES" "$RUNS" "$result" >> "$GITHUB_STEP_SUMMARY"
fi
# Profile native versus accelerated Git calls separately from wall-time measurements.
export PROFILE_GIT="$(command -v git)"
mkdir "$BASE/shim"
cat > "$BASE/shim/git" <<'PHP'
#!/usr/bin/env php
<?php
$start = hrtime(true);
$proc = proc_open(array_merge([getenv("PROFILE_GIT")], array_slice($argv, 1)), [STDIN, STDOUT, STDERR], $pipes);
$code = is_resource($proc) ? proc_close($proc) : 127;
file_put_contents(getenv("PROFILE_LOG"), json_encode([$argv[1] ?? "", (hrtime(true)-$start)/1e6])."\n", FILE_APPEND | LOCK_EX);
exit($code);
PHP
chmod +x "$BASE/shim/git"
PROFILE_LOG="$BASE/native.profile" PATH="$BASE/shim:$PATH" composer status --no-ansi >"$BASE/native.profile.out" 2>"$BASE/native.profile.err"
PROFILE_LOG="$BASE/fast.profile" PATH="$BASE/shim:$PATH" php "$ROOT/bin/fast-composer" composer-status --no-ansi >"$BASE/fast.profile.out" 2>"$BASE/fast.profile.err"
diff -u "$BASE/native.profile.out" "$BASE/fast.profile.out"
diff -u "$BASE/native.profile.err" "$BASE/fast.profile.err"
profile="$(php -r 'foreach(array_slice($argv,1) as $f){$a=[];foreach(file($f) as $line){[$name,$ms]=json_decode($line,true);$a[$name][0]=($a[$name][0]??0)+1;$a[$name][1]=($a[$name][1]??0)+$ms;}ksort($a);echo basename($f)."\n";foreach($a as $name=>[$n,$ms])printf("%s: %d calls, %.1f ms total\n",$name,$n,$ms);}' "$BASE/native.profile" "$BASE/fast.profile")"
printf '%s\n' "$profile"
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  { printf '### Git process profile (instrumented, not wall time)\n\n```\n%s\n```\n\n' "$profile"; } >> "$GITHUB_STEP_SUMMARY"
fi
