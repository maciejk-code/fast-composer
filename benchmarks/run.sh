#!/usr/bin/env bash
# Zero-latency control: local git daemon, no added delay. Shows pure local overhead.
set -euo pipefail
export BENCH_GIT_DELAY_MS="${BENCH_GIT_DELAY_MS:-0}"
export BENCH_LABEL="${BENCH_LABEL:-Zero-latency local VCS control}"
exec bash "$(dirname "$0")/compare.sh"
