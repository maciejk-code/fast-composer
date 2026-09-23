#!/usr/bin/env bash
set -euo pipefail

FC_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

REPOS="${BENCH_REPOS:-24}"
EXTRA_BRANCHES="${BENCH_EXTRA_BRANCHES:-8}"
RUNS="${BENCH_RUNS:-3}"
TARGET="bench/pkg01"

export COMPOSER_HOME="$WORK/composer-home"
export FAST_COMPOSER_CACHE_DIR="$WORK/fast-composer-cache"
mkdir -p "$COMPOSER_HOME" "$FAST_COMPOSER_CACHE_DIR" "$WORK/repos"

git config --global user.email fast-composer-bench@example.invalid
git config --global user.name fast-composer-bench

FC=(php "$FC_ROOT/bin/fast-composer")
COMPOSER_FLAGS=(--no-install --no-interaction --no-plugins --no-scripts --no-audit -q)

ms_now() { date +%s%N; }

median() {
  printf '%s\n' "$@" | sort -n | awk '{a[NR]=$1} END {if (NR%2) print a[(NR+1)/2]; else printf "%.0f\n", (a[NR/2]+a[NR/2+1])/2}'
}

measure() {
  local start end
  start="$(ms_now)"
  "$@" >/dev/null 2>&1
  end="$(ms_now)"
  echo $(( (end - start) / 1000000 ))
}

reset_root() {
  cp "$WORK/baseline/composer.json" "$WORK/root/composer.json"
  cp "$WORK/baseline/composer.lock" "$WORK/root/composer.lock"
}

restore_dir() {
  local src="$1" dst="$2"
  rm -rf "$dst"
  mkdir -p "$dst"
  cp -a "$src/." "$dst/"
}

bench_command() {
  local label="$1"; shift
  local -a samples=()
  local i
  for i in $(seq 1 "$RUNS"); do
    reset_root
    samples+=("$(measure "$@")")
  done
  local med
  med="$(median "${samples[@]}")"
  printf 'BENCH|%s|%s|%s\n' "$label" "$med" "$(IFS=,; echo "${samples[*]}")"
}

# Build many local VCS repositories with several unused refs each.
for n in $(seq 1 "$REPOS"); do
  id="$(printf '%02d' "$n")"
  repo="$WORK/repos/pkg$id"
  mkdir -p "$repo"
  git -C "$repo" init -q -b main
  cat > "$repo/composer.json" <<JSON
{"name":"bench/pkg$id","type":"library","require":{"php":">=8.2"},"extra":{"marker":"1.0"}}
JSON
  git -C "$repo" add composer.json
  git -C "$repo" commit -q -m '1.0'
  git -C "$repo" tag 1.0.0
  for b in $(seq 1 "$EXTRA_BRANCHES"); do
    git -C "$repo" branch "unused-$b"
  done
done

mkdir -p "$WORK/root"
{
  echo '{'
  echo '  "name": "bench/root",'
  echo '  "repositories": ['
  for n in $(seq 1 "$REPOS"); do
    id="$(printf '%02d' "$n")"
    comma=','; [ "$n" -eq "$REPOS" ] && comma=''
    printf '    {"type":"vcs","url":"%s"}%s\n' "$WORK/repos/pkg$id" "$comma"
  done
  echo '  ],'
  echo '  "require": {'
  echo '    "php": ">=8.2",'
  for n in $(seq 1 "$REPOS"); do
    id="$(printf '%02d' "$n")"
    comma=','; [ "$n" -eq "$REPOS" ] && comma=''
    printf '    "bench/pkg%s": "1.0.0"%s\n' "$id" "$comma"
  done
  echo '  }'
  echo '}'
} > "$WORK/root/composer.json"

cd "$WORK/root"
composer update "${COMPOSER_FLAGS[@]}"
mkdir -p "$WORK/baseline"
cp composer.json composer.lock "$WORK/baseline/"

# Save a Composer cache that knows only the baseline refs.
mkdir -p "$WORK/cache-baseline"
cp -a "$COMPOSER_HOME/." "$WORK/cache-baseline/"

# 1) First Fast Composer invocation: Composer cache is warm, Fast Composer snapshot is absent.
cold_samples=()
for i in $(seq 1 "$RUNS"); do
  reset_root
  restore_dir "$WORK/cache-baseline" "$COMPOSER_HOME"
  rm -rf "$FAST_COMPOSER_CACHE_DIR"
  mkdir -p "$FAST_COMPOSER_CACHE_DIR"
  cold_samples+=("$(measure "${FC[@]}" update "$TARGET" "${COMPOSER_FLAGS[@]}")")
done
cold_med="$(median "${cold_samples[@]}")"
printf 'BENCH|fast-composer cold prime|%s|%s\n' "$cold_med" "$(IFS=,; echo "${cold_samples[*]}")"

# Prime once and compare steady-state no-op targeted updates.
reset_root
restore_dir "$WORK/cache-baseline" "$COMPOSER_HOME"
rm -rf "$FAST_COMPOSER_CACHE_DIR"
mkdir -p "$FAST_COMPOSER_CACHE_DIR"
"${FC[@]}" update "$TARGET" "${COMPOSER_FLAGS[@]}" >/dev/null

composer_noop_samples=()
fast_noop_samples=()
for i in $(seq 1 "$RUNS"); do
  reset_root
  composer_noop_samples+=("$(measure composer update "$TARGET" "${COMPOSER_FLAGS[@]}")")
  reset_root
  fast_noop_samples+=("$(measure "${FC[@]}" update "$TARGET" "${COMPOSER_FLAGS[@]}")")
done
composer_noop_med="$(median "${composer_noop_samples[@]}")"
fast_noop_med="$(median "${fast_noop_samples[@]}")"
printf 'BENCH|composer warm targeted no-op|%s|%s\n' "$composer_noop_med" "$(IFS=,; echo "${composer_noop_samples[*]}")"
printf 'BENCH|fast-composer warm targeted no-op|%s|%s\n' "$fast_noop_med" "$(IFS=,; echo "${fast_noop_samples[*]}")"

# Snapshot both caches before a new tag exists so every measured run must discover it.
mkdir -p "$WORK/composer-before-tag" "$WORK/fast-before-tag"
cp -a "$COMPOSER_HOME/." "$WORK/composer-before-tag/"
cp -a "$FAST_COMPOSER_CACHE_DIR/." "$WORK/fast-before-tag/"

TARGET_REPO="$WORK/repos/pkg01"
cat > "$TARGET_REPO/composer.json" <<'JSON'
{"name":"bench/pkg01","type":"library","require":{"php":">=8.2"},"extra":{"marker":"1.1"}}
JSON
git -C "$TARGET_REPO" add composer.json
git -C "$TARGET_REPO" commit -q -m '1.1'
git -C "$TARGET_REPO" tag 1.1.0

composer_tag_samples=()
fast_tag_samples=()
for i in $(seq 1 "$RUNS"); do
  reset_root
  restore_dir "$WORK/composer-before-tag" "$COMPOSER_HOME"
  composer_tag_samples+=("$(measure composer update "$TARGET" "${COMPOSER_FLAGS[@]}")")

  reset_root
  restore_dir "$WORK/composer-before-tag" "$COMPOSER_HOME"
  restore_dir "$WORK/fast-before-tag" "$FAST_COMPOSER_CACHE_DIR"
  fast_tag_samples+=("$(measure "${FC[@]}" update "$TARGET" "${COMPOSER_FLAGS[@]}")")
done
composer_tag_med="$(median "${composer_tag_samples[@]}")"
fast_tag_med="$(median "${fast_tag_samples[@]}")"
printf 'BENCH|composer discover new tag|%s|%s\n' "$composer_tag_med" "$(IFS=,; echo "${composer_tag_samples[*]}")"
printf 'BENCH|fast-composer discover new tag|%s|%s\n' "$fast_tag_med" "$(IFS=,; echo "${fast_tag_samples[*]}")"

noop_speedup="$(awk -v a="$composer_noop_med" -v b="$fast_noop_med" 'BEGIN { if (b == 0) print "n/a"; else printf "%.2fx", a/b }')"
tag_speedup="$(awk -v a="$composer_tag_med" -v b="$fast_tag_med" 'BEGIN { if (b == 0) print "n/a"; else printf "%.2fx", a/b }')"

printf 'META|repos|%s\n' "$REPOS"
printf 'META|extra_branches_per_repo|%s\n' "$EXTRA_BRANCHES"
printf 'META|runs|%s\n' "$RUNS"
printf 'META|php|%s\n' "$(php -r 'echo PHP_VERSION;')"
printf 'META|composer|%s\n' "$(composer --version --no-ansi 2>/dev/null | head -n1)"
printf 'META|git|%s\n' "$(git --version)"
printf 'RESULT|warm_noop_speedup|%s\n' "$noop_speedup"
printf 'RESULT|new_tag_speedup|%s\n' "$tag_speedup"
