#!/usr/bin/env bash
set -euo pipefail

FC_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
DAEMON_PID=''
LATENCY_APPLIED=0
cleanup() {
  if [ -n "$DAEMON_PID" ]; then kill "$DAEMON_PID" 2>/dev/null || true; fi
  if [ "$LATENCY_APPLIED" -eq 1 ]; then sudo tc qdisc del dev lo root 2>/dev/null || true; fi
  rm -rf "$WORK"
}
trap cleanup EXIT

REPOS="${BENCH_REPOS:-24}"
EXTRA_BRANCHES="${BENCH_EXTRA_BRANCHES:-8}"
RUNS="${BENCH_RUNS:-3}"
LATENCY_MS="${BENCH_LATENCY_MS:-15}"
TARGET="bench/pkg01"
PORT="${BENCH_GIT_PORT:-19418}"

export COMPOSER_HOME="$WORK/composer-home"
export FAST_COMPOSER_CACHE_DIR="$WORK/fast-composer-cache"
mkdir -p "$COMPOSER_HOME" "$FAST_COMPOSER_CACHE_DIR" "$WORK/repos" "$WORK/export"

git config --global user.email fast-composer-bench@example.invalid
git config --global user.name fast-composer-bench

FC=(php "$FC_ROOT/bin/fast-composer")
FLAGS=(--no-install --no-interaction --no-plugins --no-scripts --no-audit -q)
ms_now() { date +%s%N; }
median() { printf '%s\n' "$@" | sort -n | awk '{a[NR]=$1} END {if (NR%2) print a[(NR+1)/2]; else printf "%.0f\n", (a[NR/2]+a[NR/2+1])/2}'; }
measure() { local s e; s="$(ms_now)"; "$@" >/dev/null 2>&1; e="$(ms_now)"; echo $(( (e-s)/1000000 )); }
restore_dir() { rm -rf "$2"; mkdir -p "$2"; cp -a "$1/." "$2/"; }
reset_root() { cp "$WORK/baseline/composer.json" "$WORK/root/composer.json"; cp "$WORK/baseline/composer.lock" "$WORK/root/composer.lock"; }

for n in $(seq 1 "$REPOS"); do
  id="$(printf '%02d' "$n")"
  repo="$WORK/repos/pkg$id"
  mkdir -p "$repo"
  git -C "$repo" init -q -b main
  printf '{"name":"bench/pkg%s","type":"library","require":{"php":">=8.2"},"extra":{"marker":"1.0"}}\n' "$id" > "$repo/composer.json"
  git -C "$repo" add composer.json
  git -C "$repo" commit -q -m '1.0'
  git -C "$repo" tag 1.0.0
  for b in $(seq 1 "$EXTRA_BRANCHES"); do git -C "$repo" branch "unused-$b"; done
  git clone -q --bare "$repo" "$WORK/export/pkg$id.git"
done

git daemon --reuseaddr --base-path="$WORK/export" --export-all --listen=127.0.0.1 --port="$PORT" "$WORK/export" &
DAEMON_PID=$!
sleep 0.2

if command -v tc >/dev/null 2>&1 && sudo tc qdisc add dev lo root netem delay "${LATENCY_MS}ms" 2>/dev/null; then
  LATENCY_APPLIED=1
else
  echo "SKIP: cannot apply loopback latency with tc" >&2
  exit 2
fi

mkdir -p "$WORK/root"
{
  echo '{'
  echo '  "name": "bench/root",'
  echo '  "repositories": ['
  for n in $(seq 1 "$REPOS"); do
    id="$(printf '%02d' "$n")"; comma=','; [ "$n" -eq "$REPOS" ] && comma=''
    printf '    {"type":"vcs","url":"git://127.0.0.1:%s/pkg%s.git"}%s\n' "$PORT" "$id" "$comma"
  done
  echo '  ],'
  echo '  "require": {'
  echo '    "php": ">=8.2",'
  for n in $(seq 1 "$REPOS"); do
    id="$(printf '%02d' "$n")"; comma=','; [ "$n" -eq "$REPOS" ] && comma=''
    printf '    "bench/pkg%s": "1.0.0"%s\n' "$id" "$comma"
  done
  echo '  }'
  echo '}'
} > "$WORK/root/composer.json"

cd "$WORK/root"
composer update "${FLAGS[@]}"
mkdir -p "$WORK/baseline" "$WORK/cache-baseline"
cp composer.json composer.lock "$WORK/baseline/"
cp -a "$COMPOSER_HOME/." "$WORK/cache-baseline/"

# Cold Fast Composer snapshot with an already warm Composer cache.
cold=()
for i in $(seq 1 "$RUNS"); do
  reset_root; restore_dir "$WORK/cache-baseline" "$COMPOSER_HOME"; rm -rf "$FAST_COMPOSER_CACHE_DIR"; mkdir -p "$FAST_COMPOSER_CACHE_DIR"
  cold+=("$(measure "${FC[@]}" update "$TARGET" "${FLAGS[@]}")")
done
printf 'REMOTE_BENCH|fast-composer cold prime|%s|%s\n' "$(median "${cold[@]}")" "$(IFS=,; echo "${cold[*]}")"

# Prime snapshot for steady-state comparison.
reset_root; restore_dir "$WORK/cache-baseline" "$COMPOSER_HOME"; rm -rf "$FAST_COMPOSER_CACHE_DIR"; mkdir -p "$FAST_COMPOSER_CACHE_DIR"
"${FC[@]}" update "$TARGET" "${FLAGS[@]}" >/dev/null

composer_noop=(); fast_noop=()
for i in $(seq 1 "$RUNS"); do
  reset_root; composer_noop+=("$(measure composer update "$TARGET" "${FLAGS[@]}")")
  reset_root; fast_noop+=("$(measure "${FC[@]}" update "$TARGET" "${FLAGS[@]}")")
done
c_noop="$(median "${composer_noop[@]}")"; f_noop="$(median "${fast_noop[@]}")"
printf 'REMOTE_BENCH|composer warm targeted no-op|%s|%s\n' "$c_noop" "$(IFS=,; echo "${composer_noop[*]}")"
printf 'REMOTE_BENCH|fast-composer warm targeted no-op|%s|%s\n' "$f_noop" "$(IFS=,; echo "${fast_noop[*]}")"

mkdir -p "$WORK/composer-before-tag" "$WORK/fast-before-tag"
cp -a "$COMPOSER_HOME/." "$WORK/composer-before-tag/"
cp -a "$FAST_COMPOSER_CACHE_DIR/." "$WORK/fast-before-tag/"

TARGET_REPO="$WORK/repos/pkg01"
printf '{"name":"bench/pkg01","type":"library","require":{"php":">=8.2"},"extra":{"marker":"1.1"}}\n' > "$TARGET_REPO/composer.json"
git -C "$TARGET_REPO" add composer.json
git -C "$TARGET_REPO" commit -q -m '1.1'
git -C "$TARGET_REPO" tag 1.1.0
git -C "$TARGET_REPO" push -q "$WORK/export/pkg01.git" main --tags

composer_tag=(); fast_tag=()
for i in $(seq 1 "$RUNS"); do
  reset_root; restore_dir "$WORK/composer-before-tag" "$COMPOSER_HOME"
  composer_tag+=("$(measure composer update "$TARGET" "${FLAGS[@]}")")
  reset_root; restore_dir "$WORK/composer-before-tag" "$COMPOSER_HOME"; restore_dir "$WORK/fast-before-tag" "$FAST_COMPOSER_CACHE_DIR"
  fast_tag+=("$(measure "${FC[@]}" update "$TARGET" "${FLAGS[@]}")")
done
c_tag="$(median "${composer_tag[@]}")"; f_tag="$(median "${fast_tag[@]}")"
printf 'REMOTE_BENCH|composer discover new tag|%s|%s\n' "$c_tag" "$(IFS=,; echo "${composer_tag[*]}")"
printf 'REMOTE_BENCH|fast-composer discover new tag|%s|%s\n' "$f_tag" "$(IFS=,; echo "${fast_tag[*]}")"
printf 'REMOTE_META|repos|%s\nREMOTE_META|extra_branches_per_repo|%s\nREMOTE_META|runs|%s\nREMOTE_META|loopback_delay_ms|%s\n' "$REPOS" "$EXTRA_BRANCHES" "$RUNS" "$LATENCY_MS"
printf 'REMOTE_RESULT|warm_noop_speedup|%s\n' "$(awk -v a="$c_noop" -v b="$f_noop" 'BEGIN {printf "%.2fx", a/b}')"
printf 'REMOTE_RESULT|new_tag_speedup|%s\n' "$(awk -v a="$c_tag" -v b="$f_tag" 'BEGIN {printf "%.2fx", a/b}')"
