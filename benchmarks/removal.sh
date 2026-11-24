#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$(mktemp -d)"
trap 'rm -rf "$BASE"' EXIT
COUNT="${BENCH_NODE_FILES:-40000}"
mkdir -p "$BASE/standard/node_modules" "$BASE/fast/node_modules"
php -r 'for($i=0;$i<(int)$argv[2];$i++){file_put_contents($argv[1]."/node_modules/file-".$i,"fixture");}' "$BASE/standard" "$COUNT"
cp -R "$BASE/standard/node_modules/." "$BASE/fast/node_modules/"
php -r 'file_put_contents($argv[1],json_encode(["rm"=>"/bin/rm","paths"=>[$argv[2]]]));' "$BASE/manifest.json" "$BASE/fast"
now() { php -r 'echo microtime(true);'; }
report() { php -r 'printf("%s: %.3f s\n",$argv[1],$argv[3]-$argv[2]);' "$1" "$2" "$3" | tee -a "$BASE/results"; }
start="$(now)"
rm -rf "$BASE/standard"
report "Standard blocking deletion" "$start" "$(now)"
start="$(now)"
FAST_COMPOSER_REMOVE_MANIFEST="$BASE/manifest.json" php "$ROOT/bin/remove-package-worker.php" -rf "$BASE/fast"
report "Deferred blocking deletion" "$start" "$(now)"
test ! -e "$BASE/fast"
mkdir "$BASE/fast"
touch "$BASE/fast/replacement"
deadline=$((SECONDS + 60))
while compgen -G "$BASE/.fast-composer-trash-*" >/dev/null; do
  if ((SECONDS >= deadline)); then echo "Deferred cleanup timed out" >&2; exit 1; fi
  sleep 0.02
done
report "Deferred total cleanup" "$start" "$(now)"
test -f "$BASE/fast/replacement"
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  { echo '### Package removal benchmark'; echo '~~~'; cat "$BASE/results"; echo '~~~'; } >> "$GITHUB_STEP_SUMMARY"
fi
