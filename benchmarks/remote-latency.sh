#!/usr/bin/env bash
# Remote-like latency: real loopback packet delay via tc netem (Linux, needs sudo). Exits 2 if
# netem cannot be applied.
set -euo pipefail
export BENCH_NETEM_MS="${BENCH_NETEM_MS:-15}"
export BENCH_GIT_DELAY_MS="${BENCH_GIT_DELAY_MS:-0}"
export BENCH_LABEL="${BENCH_LABEL:-Remote-like VCS latency (${BENCH_NETEM_MS} ms loopback netem)}"
exec bash "$(dirname "$0")/compare.sh"
