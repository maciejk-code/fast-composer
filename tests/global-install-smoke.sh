#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export COMPOSER_HOME="$WORK/composer-home"
mkdir -p "$COMPOSER_HOME"

composer global config repositories.fast-composer path "$ROOT"
composer global require 'maciejk-code/fast-composer:@dev' --no-interaction --no-plugins --no-scripts --prefer-dist -q

BIN="$COMPOSER_HOME/vendor/bin/fast-composer"
if [ ! -f "$BIN" ]; then
  echo "global install did not expose vendor/bin/fast-composer" >&2
  exit 1
fi

OUTPUT="$(php "$BIN" --version)"
if [ "$OUTPUT" != "fast-composer 0.1.0" ]; then
  echo "unexpected version output: $OUTPUT" >&2
  exit 1
fi

echo "global-install-smoke: PASS"
