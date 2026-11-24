#!/usr/bin/env bash
# Deterministic SSH transport with artificial latency; no external network.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$(mktemp -d)"
trap 'rm -rf "$BASE"' EXIT
COUNT="${BENCH_INSTALL_PACKAGES:-12}"
DELAY="${BENCH_INSTALL_DELAY:-0.20}"
export COMPOSER_NO_INTERACTION=1 COMPOSER_ALLOW_SUPERUSER=1
export GIT_SSH_VARIANT=ssh BENCH_INSTALL_DELAY="$DELAY"
export GIT_SSH_COMMAND="$BASE/ssh"
cat > "$BASE/ssh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
sleep "$BENCH_INSTALL_DELAY"
# Git passes the upload-pack command as its last argument; fixture owns paths.
exec /bin/sh -c "${@: -1}"
EOF
chmod +x "$BASE/ssh"
mkdir "$BASE/project"
php -r '$p=["name"=>"bench/root","repositories"=>[["packagist.org"=>false]],"require"=>[]]; file_put_contents($argv[1],json_encode($p));' "$BASE/project/composer.json"
for ((i=1;i<=COUNT;i++)); do
  repo="$BASE/repo-$i"
  git init -q -b main "$repo"
  git -C "$repo" config user.email bench@example.invalid
  git -C "$repo" config user.name benchmark
  printf '{"name":"bench/package-%s"}\n' "$i" > "$repo/composer.json"
  git -C "$repo" add .
  git -C "$repo" -c commit.gpgsign=false commit -qm initial
  git -C "$repo" -c tag.gpgsign=false tag 1.0.0
  sha="$(git -C "$repo" rev-parse HEAD)"
  php -r '$f=$argv[1];$p=json_decode(file_get_contents($f),true);$name="bench/package-".$argv[2];$p["require"][$name]="1.0.0";$p["repositories"][]=["type"=>"package","package"=>["name"=>$name,"version"=>"1.0.0","source"=>["type"=>"git","url"=>"git@bench.invalid:".$argv[3],"reference"=>$argv[4]]]];file_put_contents($f,json_encode($p));' "$BASE/project/composer.json" "$i" "$repo" "$sha"
done
cd "$BASE/project"
COMPOSER_CACHE_DIR="$BASE/lock-cache" composer update --no-install --no-audit --no-interaction --no-plugins --no-scripts
cp composer.lock "$BASE/expected.lock"
measure() {
  local label="$1" cache="$2"; shift 2
  rm -rf vendor
  local start end
  start="$(php -r 'echo microtime(true);')"
  COMPOSER_CACHE_DIR="$cache" "$@" install --no-interaction --no-plugins --no-scripts --no-progress
  end="$(php -r 'echo microtime(true);')"
  cmp composer.lock "$BASE/expected.lock"
  php -r '$l=json_decode(file_get_contents("composer.lock"),true);$i=json_decode(file_get_contents("vendor/composer/installed.json"),true);$r=array_column($i["packages"],"source","name");foreach($l["packages"] as $p){if(($r[$p["name"]]["reference"]??null)!==$p["source"]["reference"]){exit(1);}}'
  php -r 'printf("%s: %.3f s\n",$argv[1],$argv[3]-$argv[2]);' "$label" "$start" "$end" | tee -a "$BASE/results"
}
measure "Composer cold" "$BASE/standard-cache" composer
measure "Fast Composer cold" "$BASE/fast-cache" php "$ROOT/bin/fast-composer"
measure "Composer warm" "$BASE/standard-cache" composer
measure "Fast Composer warm" "$BASE/fast-cache" php "$ROOT/bin/fast-composer"
cat "$BASE/results"
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  { echo '### Install benchmark'; echo '~~~'; cat "$BASE/results"; echo '~~~'; } >> "$GITHUB_STEP_SUMMARY"
fi
