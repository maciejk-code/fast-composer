#!/usr/bin/env bash
# Composer vs Fast Composer on a synthetic many-VCS-repository project.
#
# Every scenario is measured from the same warm starting point for both tools (Composer's VCS
# mirrors and metadata cache populated, Fast Composer's snapshot and mirrors populated), and
# the lock file each tool produces is compared byte-for-byte.
#
# Repositories are served by a local `git daemon`, so Composer uses its normal remote-VCS path
# (cached mirror + `git remote update`), not direct reads of a local directory. A Git shim adds
# BENCH_GIT_DELAY_MS to every network-like Git operation and counts them. BENCH_NETEM_MS
# optionally adds real loopback packet latency with `tc netem` (needs sudo).
#
# Environment:
#   BENCH_REPOS           VCS repositories / required packages   (default 30)
#   BENCH_EXTRA_BRANCHES  unused branches per repository          (default 8)
#   BENCH_RUNS            measured runs per scenario (median)     (default 5)
#   BENCH_GIT_DELAY_MS    added delay per network Git operation   (default 0)
#   BENCH_NETEM_MS        loopback latency via tc netem           (default 0 = off)
#   BENCH_LABEL           name printed in the report header
set -euo pipefail

FC_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
DAEMON_PID=''
NETEM_APPLIED=0
cleanup() {
  if [ -n "$DAEMON_PID" ]; then kill "$DAEMON_PID" 2>/dev/null || true; fi
  if [ "$NETEM_APPLIED" -eq 1 ]; then sudo tc qdisc del dev lo root 2>/dev/null || true; fi
  rm -rf "$WORK"
}
trap cleanup EXIT

REPOS="${BENCH_REPOS:-30}"
EXTRA_BRANCHES="${BENCH_EXTRA_BRANCHES:-8}"
RUNS="${BENCH_RUNS:-5}"
GIT_DELAY_MS="${BENCH_GIT_DELAY_MS:-0}"
NETEM_MS="${BENCH_NETEM_MS:-0}"
LABEL="${BENCH_LABEL:-git delay ${GIT_DELAY_MS} ms/op}"
PORT="${BENCH_GIT_PORT:-$((19400 + RANDOM % 500))}"
REAL_GIT="$(command -v git)"

FIRST="$(printf 'pkg%02d' 1)"
MIDDLE="$(printf 'pkg%02d' $(( (REPOS + 1) / 2 )))"
LAST="$(printf 'pkg%02d' "$REPOS")"

export COMPOSER_HOME="$WORK/composer-home"
export COMPOSER_CACHE_DIR="$WORK/composer-cache"
export FAST_COMPOSER_CACHE_DIR="$WORK/fast-cache"
export COMPOSER_ALLOW_SUPERUSER=1
unset COMPOSER COMPOSER_ROOT_VERSION FAST_COMPOSER_TTL
mkdir -p "$COMPOSER_HOME" "$COMPOSER_CACHE_DIR" "$FAST_COMPOSER_CACHE_DIR" "$WORK/src" "$WORK/export" "$WORK/bin" "$WORK/state"
composer config --global secure-http false >/dev/null 2>&1

# --- Git shim: count network-like operations and add latency to them -------------------------
cat > "$WORK/bin/git" <<'SH'
#!/usr/bin/env bash
network=0
prev=''
for arg in "$@"; do
  case "$arg" in
    ls-remote|fetch|clone|pull) network=1 ;;
    update) [ "$prev" = remote ] && network=1 ;;
  esac
  prev="$arg"
done
if [ "$network" -eq 1 ]; then
  echo x >> "$BENCH_GIT_LOG"
  if [ "$BENCH_GIT_DELAY_S" != 0 ]; then sleep "$BENCH_GIT_DELAY_S"; fi
fi
exec "$BENCH_REAL_GIT" "$@"
SH
chmod +x "$WORK/bin/git"
export BENCH_REAL_GIT="$REAL_GIT"
export BENCH_GIT_LOG="$WORK/git-network.log"
export BENCH_GIT_DELAY_S="$(awk -v ms="$GIT_DELAY_MS" 'BEGIN { if (ms == 0) print 0; else printf "%.3f", ms / 1000 }')"
export PATH="$WORK/bin:$PATH"
: > "$BENCH_GIT_LOG"

g() { "$REAL_GIT" "$@"; }
g config --global user.email fast-composer-bench@example.invalid
g config --global user.name fast-composer-bench

# --- Fixture ----------------------------------------------------------------------------------
commit_version() {
  local id="$1" marker="$2"
  printf '{"name":"bench/%s","type":"library","require":{"php":">=8.2"},"extra":{"marker":"%s"}}\n' "$id" "$marker" > "$WORK/src/$id/composer.json"
  g -C "$WORK/src/$id" add composer.json
  g -C "$WORK/src/$id" -c commit.gpgsign=false commit -q -m "$marker"
}
publish() { g -C "$WORK/src/$1" -c push.negotiate=false push -q --force --tags "$WORK/export/$1.git" 'refs/heads/*:refs/heads/*'; }

for n in $(seq 1 "$REPOS"); do
  id="$(printf 'pkg%02d' "$n")"
  mkdir -p "$WORK/src/$id"
  g -C "$WORK/src/$id" init -q -b main
  commit_version "$id" 1.0
  g -C "$WORK/src/$id" -c tag.gpgsign=false tag 1.0.0
  g -C "$WORK/src/$id" branch feature
  for b in $(seq 1 "$EXTRA_BRANCHES"); do g -C "$WORK/src/$id" branch "unused-$b"; done
  g init -q --bare "$WORK/export/$id.git"
  publish "$id"
done

# Started directly (not via the g() function) so $! is the daemon itself and cleanup can stop it.
"$REAL_GIT" daemon --reuseaddr --base-path="$WORK/export" --export-all --listen=127.0.0.1 --port="$PORT" "$WORK/export" >/dev/null 2>&1 &
DAEMON_PID=$!
for _ in $(seq 1 50); do
  g ls-remote "git://127.0.0.1:$PORT/$FIRST.git" >/dev/null 2>&1 && break
  sleep 0.1
done

if [ "$NETEM_MS" != 0 ]; then
  if command -v tc >/dev/null 2>&1 && sudo tc qdisc add dev lo root netem delay "${NETEM_MS}ms" 2>/dev/null; then
    NETEM_APPLIED=1
  else
    echo "SKIP: cannot apply loopback latency with tc netem" >&2
    exit 2
  fi
fi

ROOT="$WORK/root"
mkdir -p "$ROOT"
{
  echo '{'
  echo '  "name": "bench/root",'
  echo '  "repositories": ['
  for n in $(seq 1 "$REPOS"); do
    comma=','; [ "$n" -eq "$REPOS" ] && comma=''
    printf '    {"type":"vcs","url":"git://127.0.0.1:%s/pkg%02d.git"}%s\n' "$PORT" "$n" "$comma"
  done
  echo '  ],'
  echo '  "require": {'
  echo '    "php": ">=8.2",'
  for n in $(seq 1 "$REPOS"); do
    id="$(printf 'pkg%02d' "$n")"; comma=','; [ "$n" -eq "$REPOS" ] && comma=''
    # The first and the last package track a mutable development branch.
    if [ "$id" = "$FIRST" ] || [ "$id" = "$LAST" ]; then
      printf '    "bench/%s": "dev-feature"%s\n' "$id" "$comma"
    else
      printf '    "bench/%s": "^1.0"%s\n' "$id" "$comma"
    fi
  done
  echo '  }'
  echo '}'
} > "$ROOT/composer.json"

FLAGS=(--no-install --no-interaction --no-plugins --no-scripts --no-audit -q)
FC=(php "$FC_ROOT/bin/fast-composer")
cd "$ROOT"

# Warm Composer: a full update populates its VCS mirrors and composer.json cache.
composer update "${FLAGS[@]}"
cp composer.json composer.lock "$WORK/state/"
cp -a "$COMPOSER_CACHE_DIR" "$WORK/state/composer-cache"

# Warm Fast Composer: first invocation primes the snapshot, a broad refresh syncs every mirror.
"${FC[@]}" update "bench/$FIRST" "${FLAGS[@]}" >/dev/null
cp "$WORK/state/composer.lock" composer.lock
FAST_COMPOSER_TTL=0 "${FC[@]}" update "${FLAGS[@]}" >/dev/null
cp -a "$FAST_COMPOSER_CACHE_DIR" "$WORK/state/fast-cache"

# --- Measurement ------------------------------------------------------------------------------
now_ns() { date +%s%N; }
median() { printf '%s\n' "$@" | sort -n | awk '{a[NR]=$1} END {if (NR%2) print a[(NR+1)/2]; else printf "%.0f\n", (a[NR/2]+a[NR/2+1])/2}'; }

restore() {
  cp "$WORK/state/composer.json" "$WORK/state/composer.lock" "$ROOT/"
  rm -rf "$COMPOSER_CACHE_DIR" "$FAST_COMPOSER_CACHE_DIR"
  cp -a "$WORK/state/composer-cache" "$COMPOSER_CACHE_DIR"
  if [ "${1:-warm}" = cold ]; then mkdir -p "$FAST_COMPOSER_CACHE_DIR"; else cp -a "$WORK/state/fast-cache" "$FAST_COMPOSER_CACHE_DIR"; fi
}

measure() {
  : > "$BENCH_GIT_LOG"
  local s e
  s="$(now_ns)"
  if ! "$@" >"$WORK/last-output.log" 2>&1; then
    echo "command failed: $*" >&2
    cat "$WORK/last-output.log" >&2
    exit 1
  fi
  e="$(now_ns)"
  MS=$(( (e - s) / 1000000 ))
  OPS="$(wc -l < "$BENCH_GIT_LOG" | tr -d ' ')"
}

ROWS=()
FAST_ENV=(FAST_COMPOSER_TTL=300)
scenario() {
  local label="$1" state="$2"; shift 2
  local split=0 arg
  # Arguments: <composer args...> -- <fast args...>
  local -a composer_args=() fast_args=()
  for arg in "$@"; do
    if [ "$arg" = -- ]; then split=1; continue; fi
    if [ "$split" -eq 0 ]; then composer_args+=("$arg"); else fast_args+=("$arg"); fi
  done

  local -a c_ms=() f_ms=() c_ops=() f_ops=()
  local identical=0
  for _ in $(seq 1 "$RUNS"); do
    restore "$state"
    measure composer "${composer_args[@]}"
    c_ms+=("$MS"); c_ops+=("$OPS")
    local composer_lock
    composer_lock="$(sha256sum composer.lock | cut -d' ' -f1)"

    restore "$state"
    measure env "${FAST_ENV[@]}" "${FC[@]}" "${fast_args[@]}"
    f_ms+=("$MS"); f_ops+=("$OPS")
    [ "$(sha256sum composer.lock | cut -d' ' -f1)" = "$composer_lock" ] && identical=$((identical + 1))
  done

  local cm fm
  cm="$(median "${c_ms[@]}")"; fm="$(median "${f_ms[@]}")"
  local speedup
  speedup="$(awk -v a="$cm" -v b="$fm" 'BEGIN { if (b == 0) print "n/a"; else printf "%.2fx", a / b }')"
  local row
  row="$label|$cm|$fm|$speedup|$(median "${c_ops[@]}")|$(median "${f_ops[@]}")|$identical/$RUNS"
  ROWS+=("$row")
  printf 'COMPARE|%s|composer=%s|fast=%s\n' "$row" "$(IFS=,; echo "${c_ms[*]}")" "$(IFS=,; echo "${f_ms[*]}")"
}

# Targeted no-op. Composer stops scanning VCS repositories once it finds the package, so the
# package's position in "repositories" matters a lot for Composer and not at all for Fast Composer.
scenario "targeted no-op, first repository" warm update "bench/$FIRST" "${FLAGS[@]}" -- update "bench/$FIRST" "${FLAGS[@]}"
scenario "targeted no-op, middle repository" warm update "bench/$MIDDLE" "${FLAGS[@]}" -- update "bench/$MIDDLE" "${FLAGS[@]}"
scenario "targeted no-op, last repository" warm update "bench/$LAST" "${FLAGS[@]}" -- update "bench/$LAST" "${FLAGS[@]}"

# Broad update: Fast Composer refreshes all repositories in parallel once the TTL expired, and
# revalidates only explicit dev-* refs within the TTL.
FAST_ENV=(FAST_COMPOSER_TTL=0)
scenario "broad no-op, TTL expired" warm update "${FLAGS[@]}" -- update "${FLAGS[@]}"
FAST_ENV=(FAST_COMPOSER_TTL=86400)
scenario "broad no-op, within TTL" warm update "${FLAGS[@]}" -- update "${FLAGS[@]}"
FAST_ENV=(FAST_COMPOSER_TTL=300)

# New stable tag in the middle repository (the first/last track dev-feature).
commit_version "$MIDDLE" 1.1
g -C "$WORK/src/$MIDDLE" -c tag.gpgsign=false tag 1.1.0
publish "$MIDDLE"
scenario "targeted new tag, middle repository" warm update "bench/$MIDDLE" "${FLAGS[@]}" -- update "bench/$MIDDLE" "${FLAGS[@]}"

# Mutable development branch moved to a new commit.
for id in "$FIRST" "$LAST"; do
  g -C "$WORK/src/$id" checkout -q feature
  commit_version "$id" feature-2
  g -C "$WORK/src/$id" checkout -q main
  publish "$id"
done
scenario "moved dev branch, first repository" warm update "bench/$FIRST" "${FLAGS[@]}" -- update "bench/$FIRST" "${FLAGS[@]}"
scenario "moved dev branch, last repository" warm update "bench/$LAST" "${FLAGS[@]}" -- update "bench/$LAST" "${FLAGS[@]}"

# First ever Fast Composer invocation (runs a regular Composer solve, then indexes refs).
scenario "first invocation (cold snapshot), last repository" cold update "bench/$LAST" "${FLAGS[@]}" -- update "bench/$LAST" "${FLAGS[@]}"

# --- Report -----------------------------------------------------------------------------------
REPORT="$WORK/report.md"
{
  echo "### $LABEL"
  echo
  echo "$REPOS VCS repositories ($EXTRA_BRANCHES extra branches each), median of $RUNS runs. PHP $(php -r 'echo PHP_VERSION;'), $(composer --version --no-ansi 2>/dev/null | grep -o 'Composer version [^ ]*'), $("$REAL_GIT" --version)."
  echo
  echo "| Scenario | Composer | Fast Composer | Speed-up | Composer Git net ops | Fast Git net ops | Same lock as Composer |"
  echo "| --- | ---: | ---: | ---: | ---: | ---: | ---: |"
  for row in "${ROWS[@]}"; do
    IFS='|' read -r label cm fm speedup cops fops identical <<<"$row"
    printf '| %s | %.3f s | %.3f s | **%s** | %s | %s | %s |\n' "$label" "$(awk -v v="$cm" 'BEGIN{print v/1000}')" "$(awk -v v="$fm" 'BEGIN{print v/1000}')" "$speedup" "$cops" "$fops" "$identical"
  done
} > "$REPORT"
cat "$REPORT"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then cat "$REPORT" >> "$GITHUB_STEP_SUMMARY"; fi
