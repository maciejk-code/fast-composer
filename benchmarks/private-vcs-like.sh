#!/usr/bin/env bash
set -euo pipefail

FC_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
DAEMON_PID=''
cleanup() {
  if [ -n "$DAEMON_PID" ]; then kill "$DAEMON_PID" 2>/dev/null || true; fi
  rm -rf "$WORK"
}
trap cleanup EXIT

REPOS="${BENCH_REPOS:-40}"
REQUIRED="${BENCH_REQUIRED:-20}"
EXTRA_BRANCHES="${BENCH_EXTRA_BRANCHES:-10}"
RUNS="${BENCH_RUNS:-3}"
GIT_DELAY_MS="${BENCH_GIT_DELAY_MS:-40}"
PORT="${BENCH_GIT_PORT:-19419}"
TARGET="bench/pkg01"
REAL_GIT="$(command -v git)"

export COMPOSER_HOME="$WORK/composer-home"
export FAST_COMPOSER_CACHE_DIR="$WORK/fast-composer-cache"
mkdir -p "$COMPOSER_HOME" "$FAST_COMPOSER_CACHE_DIR" "$WORK/repos" "$WORK/export" "$WORK/bin"
composer config --global secure-http false

cat > "$WORK/bin/git" <<'SH'
#!/usr/bin/env bash
set -e
REAL_GIT="${BENCH_REAL_GIT:?}"
LOG="${BENCH_GIT_LOG:?}"
ALL_LOG="${BENCH_GIT_ALL_LOG:?}"
DELAY_MS="${BENCH_GIT_DELAY_MS:-0}"
network=0
kind='other'
args=("$@")
printf '%q ' "$@" >> "$ALL_LOG"; printf '\n' >> "$ALL_LOG"
for ((i=0; i<${#args[@]}; i++)); do
  arg="${args[$i]}"
  case "$arg" in
    ls-remote) network=1; kind='ls-remote' ;;
    fetch) network=1; kind='fetch' ;;
    clone) network=1; kind='clone' ;;
    pull) network=1; kind='pull' ;;
    remote)
      next="${args[$((i+1))]:-}"
      if [ "$next" = update ]; then network=1; kind='remote-update'; fi
      ;;
  esac
done
if [ "$network" -eq 1 ]; then
  printf '%s\n' "$kind" >> "$LOG"
  if [ "$DELAY_MS" -gt 0 ]; then
    python3 - "$DELAY_MS" <<'PY'
import sys,time
time.sleep(int(sys.argv[1])/1000)
PY
  fi
fi
exec "$REAL_GIT" "$@"
SH
chmod +x "$WORK/bin/git"
export BENCH_REAL_GIT="$REAL_GIT"
export BENCH_GIT_LOG="$WORK/git-network-calls.log"
export BENCH_GIT_ALL_LOG="$WORK/git-all-calls.log"
export BENCH_GIT_DELAY_MS="$GIT_DELAY_MS"
export PATH="$WORK/bin:$PATH"

"$REAL_GIT" config --global user.email fast-composer-bench@example.invalid
"$REAL_GIT" config --global user.name fast-composer-bench

FC=(php "$FC_ROOT/bin/fast-composer")
FLAGS=(--no-install --no-interaction --no-plugins --no-scripts --no-audit -q)
ms_now() { date +%s%N; }
median() { printf '%s\n' "$@" | sort -n | awk '{a[NR]=$1} END {if (NR%2) print a[(NR+1)/2]; else printf "%.0f\n", (a[NR/2]+a[NR/2+1])/2}'; }
measure() {
  local s e
  : > "$BENCH_GIT_LOG"; : > "$BENCH_GIT_ALL_LOG"
  s="$(ms_now)"; "$@" >/dev/null 2>&1; e="$(ms_now)"
  MEASURE_MS=$(( (e-s)/1000000 ))
  MEASURE_CALLS="$(wc -l < "$BENCH_GIT_LOG" | tr -d ' ')"
}
restore_dir() { rm -rf "$2"; mkdir -p "$2"; cp -a "$1/." "$2/"; }
reset_root() { cp "$WORK/baseline/composer.json" "$WORK/root/composer.json"; cp "$WORK/baseline/composer.lock" "$WORK/root/composer.lock"; }
locked_sha() {
  php -r '$l=json_decode(file_get_contents("composer.lock"),true,512,JSON_THROW_ON_ERROR); foreach(array_merge($l["packages"]??[],$l["packages-dev"]??[]) as $p){if(($p["name"]??null)==="bench/pkg01"){echo $p["source"]["reference"]??""; exit;}}' 
}

for n in $(seq 1 "$REPOS"); do
  id="$(printf '%02d' "$n")"
  repo="$WORK/repos/pkg$id"
  mkdir -p "$repo"
  "$REAL_GIT" -C "$repo" init -q -b main
  printf '{"name":"bench/pkg%s","type":"library","require":{"php":">=8.2"},"extra":{"marker":"1.0"}}\n' "$id" > "$repo/composer.json"
  "$REAL_GIT" -C "$repo" add composer.json
  "$REAL_GIT" -C "$repo" commit -q -m '1.0'
  "$REAL_GIT" -C "$repo" tag 1.0.0
  for b in $(seq 1 "$EXTRA_BRANCHES"); do "$REAL_GIT" -C "$repo" branch "unused-$b"; done
  if [ "$n" -le "$REQUIRED" ]; then "$REAL_GIT" -C "$repo" branch feature; fi
  "$REAL_GIT" clone -q --bare "$repo" "$WORK/export/pkg$id.git"
done

"$REAL_GIT" daemon --reuseaddr --base-path="$WORK/export" --export-all --listen=127.0.0.1 --port="$PORT" "$WORK/export" &
DAEMON_PID=$!
sleep 0.2

mkdir -p "$WORK/root"
{
  echo '{'
  echo '  "name": "bench/root",'
  echo '  "minimum-stability": "dev",'
  echo '  "repositories": ['
  for n in $(seq 1 "$REPOS"); do
    id="$(printf '%02d' "$n")"; comma=','; [ "$n" -eq "$REPOS" ] && comma=''
    printf '    {"type":"vcs","url":"git://127.0.0.1:%s/pkg%s.git"}%s\n' "$PORT" "$id" "$comma"
  done
  echo '  ],'
  echo '  "require": {'
  echo '    "php": ">=8.2",'
  for n in $(seq 1 "$REQUIRED"); do
    id="$(printf '%02d' "$n")"; comma=','; [ "$n" -eq "$REQUIRED" ] && comma=''
    if [ "$n" -eq 1 ]; then
      printf '    "bench/pkg%s": "dev-feature"%s\n' "$id" "$comma"
    else
      printf '    "bench/pkg%s": "1.0.0"%s\n' "$id" "$comma"
    fi
  done
  echo '  }'
  echo '}'
} > "$WORK/root/composer.json"

cd "$WORK/root"
composer update "${FLAGS[@]}"
mkdir -p "$WORK/baseline" "$WORK/cache-baseline"
cp composer.json composer.lock "$WORK/baseline/"
cp -a "$COMPOSER_HOME/." "$WORK/cache-baseline/"

reset_root; restore_dir "$WORK/cache-baseline" "$COMPOSER_HOME"; rm -rf "$FAST_COMPOSER_CACHE_DIR"; mkdir -p "$FAST_COMPOSER_CACHE_DIR"
"${FC[@]}" update "$TARGET" "${FLAGS[@]}" >/dev/null
mkdir -p "$WORK/fast-baseline"
cp -a "$FAST_COMPOSER_CACHE_DIR/." "$WORK/fast-baseline/"

composer_target=(); composer_target_calls=(); fast_target=(); fast_target_calls=()
for _ in $(seq 1 "$RUNS"); do
  reset_root; restore_dir "$WORK/cache-baseline" "$COMPOSER_HOME"; measure composer update "$TARGET" "${FLAGS[@]}"; composer_target+=("$MEASURE_MS"); composer_target_calls+=("$MEASURE_CALLS")
  reset_root; restore_dir "$WORK/cache-baseline" "$COMPOSER_HOME"; restore_dir "$WORK/fast-baseline" "$FAST_COMPOSER_CACHE_DIR"; measure "${FC[@]}" update "$TARGET" "${FLAGS[@]}"; fast_target+=("$MEASURE_MS"); fast_target_calls+=("$MEASURE_CALLS")
done
printf 'PRIVATE_BENCH|composer targeted no-op|%s|%s|calls=%s|%s\n' "$(median "${composer_target[@]}")" "$(IFS=,; echo "${composer_target[*]}")" "$(median "${composer_target_calls[@]}")" "$(IFS=,; echo "${composer_target_calls[*]}")"
printf 'PRIVATE_BENCH|fast-composer targeted no-op|%s|%s|calls=%s|%s\n' "$(median "${fast_target[@]}")" "$(IFS=,; echo "${fast_target[*]}")" "$(median "${fast_target_calls[@]}")" "$(IFS=,; echo "${fast_target_calls[*]}")"

composer_broad=(); composer_broad_calls=(); fast_broad=(); fast_broad_calls=()
for _ in $(seq 1 "$RUNS"); do
  reset_root; restore_dir "$WORK/cache-baseline" "$COMPOSER_HOME"; measure composer update "${FLAGS[@]}"; composer_broad+=("$MEASURE_MS"); composer_broad_calls+=("$MEASURE_CALLS")
  reset_root; restore_dir "$WORK/cache-baseline" "$COMPOSER_HOME"; restore_dir "$WORK/fast-baseline" "$FAST_COMPOSER_CACHE_DIR"; measure env FAST_COMPOSER_TTL=99999 "${FC[@]}" update "${FLAGS[@]}"; fast_broad+=("$MEASURE_MS"); fast_broad_calls+=("$MEASURE_CALLS")
done
printf 'PRIVATE_BENCH|composer broad no-op|%s|%s|calls=%s|%s\n' "$(median "${composer_broad[@]}")" "$(IFS=,; echo "${composer_broad[*]}")" "$(median "${composer_broad_calls[@]}")" "$(IFS=,; echo "${composer_broad_calls[*]}")"
printf 'PRIVATE_BENCH|fast-composer broad no-op|%s|%s|calls=%s|%s\n' "$(median "${fast_broad[@]}")" "$(IFS=,; echo "${fast_broad[*]}")" "$(median "${fast_broad_calls[@]}")" "$(IFS=,; echo "${fast_broad_calls[*]}")"

repo="$WORK/repos/pkg01"
printf '{"name":"bench/pkg01","type":"library","require":{"php":">=8.2"},"extra":{"marker":"feature-2"}}\n' > "$repo/composer.json"
"$REAL_GIT" -C "$repo" add composer.json
"$REAL_GIT" -C "$repo" commit -q -m 'feature moved'
"$REAL_GIT" -C "$repo" branch -f feature HEAD
NEW_SHA="$("$REAL_GIT" -C "$repo" rev-parse feature)"
"$REAL_GIT" -C "$repo" push -q --force "$WORK/export/pkg01.git" feature

composer_dev=(); composer_dev_calls=(); composer_detected=0
fast_dev=(); fast_dev_calls=(); fast_detected=0
for _ in $(seq 1 "$RUNS"); do
  reset_root; restore_dir "$WORK/cache-baseline" "$COMPOSER_HOME"; measure composer update "$TARGET" "${FLAGS[@]}"; composer_dev+=("$MEASURE_MS"); composer_dev_calls+=("$MEASURE_CALLS"); [ "$(locked_sha)" = "$NEW_SHA" ] && composer_detected=$((composer_detected+1))
  reset_root; restore_dir "$WORK/cache-baseline" "$COMPOSER_HOME"; restore_dir "$WORK/fast-baseline" "$FAST_COMPOSER_CACHE_DIR"; measure "${FC[@]}" update "$TARGET" "${FLAGS[@]}"; fast_dev+=("$MEASURE_MS"); fast_dev_calls+=("$MEASURE_CALLS"); [ "$(locked_sha)" = "$NEW_SHA" ] && fast_detected=$((fast_detected+1))
done
printf 'PRIVATE_BENCH|composer moved dev branch|%s|%s|calls=%s|%s|fresh=%s/%s\n' "$(median "${composer_dev[@]}")" "$(IFS=,; echo "${composer_dev[*]}")" "$(median "${composer_dev_calls[@]}")" "$(IFS=,; echo "${composer_dev_calls[*]}")" "$composer_detected" "$RUNS"
printf 'PRIVATE_BENCH|fast-composer moved dev branch|%s|%s|calls=%s|%s|fresh=%s/%s\n' "$(median "${fast_dev[@]}")" "$(IFS=,; echo "${fast_dev[*]}")" "$(median "${fast_dev_calls[@]}")" "$(IFS=,; echo "${fast_dev_calls[*]}")" "$fast_detected" "$RUNS"
printf 'PRIVATE_META|repos=%s|required=%s|extra_branches=%s|git_delay_ms=%s|runs=%s\n' "$REPOS" "$REQUIRED" "$EXTRA_BRANCHES" "$GIT_DELAY_MS" "$RUNS"
