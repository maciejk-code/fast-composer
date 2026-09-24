#!/usr/bin/env bash
# Single entry point for everything CI verifies. The workflow only calls this script, so what
# CI checks can change here without touching .github/workflows.
#
# Usage: bash tests/ci.sh [all|unit|analyse|contracts|<contract name>...]
#   all        (default) analyse + unit + contracts
#   unit       unit tests (tests/unit)
#   analyse    PHPStan (skipped with a notice when phpstan is not installed)
#   contracts  every end-to-end contract listed in CONTRACTS below
#   <name>     one contract, e.g. `parity` for tests/parity-contract.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# End-to-end contracts against real Composer, in the order they run.
CONTRACTS=(safety ci-verifier runtime parity auth global-install)

contract_script() {
  case "$1" in
    global-install) echo "tests/global-install-smoke.sh" ;;
    *) echo "tests/$1-contract.sh" ;;
  esac
}

RESULTS=()
FAILED=0

step() {
  local name="$1"; shift
  local start end
  start=$(date +%s)
  echo "::group::$name" 2>/dev/null || true
  echo "=== $name"
  if "$@"; then
    end=$(date +%s)
    RESULTS+=("PASS  $name ($((end - start))s)")
  else
    end=$(date +%s)
    RESULTS+=("FAIL  $name ($((end - start))s)")
    FAILED=1
  fi
  echo "::endgroup::" 2>/dev/null || true
}

run_unit() { step unit php tests/run.php; }

run_analyse() {
  if [ -x vendor/bin/phpstan ]; then
    step analyse vendor/bin/phpstan analyse --no-progress
  else
    RESULTS+=("SKIP  analyse (vendor/bin/phpstan not installed; run composer install)")
  fi
}

run_contract() {
  local script
  script="$(contract_script "$1")"
  if [ ! -f "$script" ]; then
    RESULTS+=("FAIL  $1 (no such contract: $script)")
    FAILED=1
    return
  fi
  step "$1" bash "$script"
}

run_contracts() {
  local name
  for name in "${CONTRACTS[@]}"; do run_contract "$name"; done
}

[ $# -eq 0 ] && set -- all
for target in "$@"; do
  case "$target" in
    all) run_analyse; run_unit; run_contracts ;;
    unit) run_unit ;;
    analyse) run_analyse ;;
    contracts) run_contracts ;;
    *) run_contract "$target" ;;
  esac
done

echo
echo "=== Summary"
printf '%s\n' "${RESULTS[@]}"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  { echo '### tests/ci.sh'; echo '```'; printf '%s\n' "${RESULTS[@]}"; echo '```'; } >> "$GITHUB_STEP_SUMMARY"
fi
exit "$FAILED"
