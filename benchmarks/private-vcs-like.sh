#!/usr/bin/env bash
# Private-VCS-like workload: 40 repositories, 40 ms added to every network Git operation.
set -euo pipefail
export BENCH_REPOS="${BENCH_REPOS:-40}"
export BENCH_EXTRA_BRANCHES="${BENCH_EXTRA_BRANCHES:-10}"
export BENCH_GIT_DELAY_MS="${BENCH_GIT_DELAY_MS:-40}"
export BENCH_LABEL="${BENCH_LABEL:-Private-VCS-like workload (40 ms per network Git operation)}"
exec bash "$(dirname "$0")/compare.sh"
