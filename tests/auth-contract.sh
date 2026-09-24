#!/usr/bin/env bash
# HTTPS credentials, in Composer's order: Git's own authentication first, Composer's auth.json
# only when that is refused; the method that worked is remembered per repository. Without
# credentials, fail clearly (never hang).
set -euo pipefail

FC_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
SERVER_PID=''
cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null || true; fi
  rm -rf "$WORK"
}
trap cleanup EXIT
[ -n "${AUTH_KEEP:-}" ] && trap - EXIT

PORT="${AUTH_CONTRACT_PORT:-$((18700 + RANDOM % 200))}"
export COMPOSER_HOME="$WORK/composer-home"
export COMPOSER_ALLOW_SUPERUSER=1
export GIT_CONFIG_GLOBAL="$WORK/gitconfig"
unset COMPOSER_AUTH
mkdir -p "$COMPOSER_HOME" "$WORK/src" "$WORK/srv"
: > "$GIT_CONFIG_GLOBAL"
FC=(php "$FC_ROOT/bin/fast-composer")
FLAGS=(--no-interaction --no-plugins --no-scripts --no-audit)

git -C "$WORK/src" init -q -b main
printf '{"name":"acme/private","type":"library"}\n' > "$WORK/src/composer.json"
git -C "$WORK/src" add composer.json
git -C "$WORK/src" -c user.email=t@example.invalid -c user.name=t -c commit.gpgsign=false commit -q -m 1
git -C "$WORK/src" -c tag.gpgsign=false tag 1.0.0
git clone -q --bare "$WORK/src" "$WORK/srv/private.git"

python3 "$FC_ROOT/tests/fixtures/git-http-server.py" "$WORK/srv" "$PORT" >/dev/null 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null && break
  sleep 0.1
done

ROOT="$WORK/root"
mkdir -p "$ROOT"
cat > "$ROOT/composer.json" <<JSON
{
  "name": "acme/root",
  "config": {"secure-http": false},
  "repositories": [{"type": "vcs", "url": "http://127.0.0.1:$PORT/private.git"}, {"packagist.org": false}],
  "require": {"acme/private": "^1.0"}
}
JSON
cd "$ROOT"

# 1. No credentials anywhere: a clear failure with a hint, no hang.
if FAST_COMPOSER_CACHE_DIR="$WORK/cache-1" timeout 60 "${FC[@]}" update "${FLAGS[@]}" > "$WORK/none.log" 2>&1; then
  echo "REGRESSION: fast-composer succeeded without credentials" >&2; exit 1
fi
grep -q 'Hint: Fast Composer runs Git non-interactively' "$WORK/none.log" || { cat "$WORK/none.log" >&2; echo "missing credential hint" >&2; exit 1; }
echo "auth-missing-credentials-fail-clearly: PASS"

# 2. Credentials only in the project's auth.json: same lock as Composer, secret never in argv.
printf '{"http-basic": {"127.0.0.1:%s": {"username": "deploy", "password": "s3cret"}}}\n' "$PORT" > auth.json
composer update --no-install "${FLAGS[@]}" -q
mv composer.lock "$WORK/composer.lock"
FAST_COMPOSER_DEBUG=1 FAST_COMPOSER_CACHE_DIR="$WORK/cache-2" "${FC[@]}" update "${FLAGS[@]}" > "$WORK/authjson.log" 2>&1
cmp -s composer.lock "$WORK/composer.lock" || { diff "$WORK/composer.lock" composer.lock >&2; echo "lock differs from Composer" >&2; exit 1; }
if grep -q 's3cret\|ZGVwbG95OnMzY3JldA' "$WORK/authjson.log"; then
  echo "REGRESSION: credential visible in logged command lines" >&2; exit 1
fi
grep -q 'retrying [0-9]* refused: [1-9][0-9]* with Composer credentials' "$WORK/authjson.log" || { cat "$WORK/authjson.log" >&2; echo "auth.json not used after Git's own authentication was refused" >&2; exit 1; }
echo "auth-json-credentials: PASS"

# 2b. Second run: auth.json is remembered as the working method, no refused attempt first.
FAST_COMPOSER_TTL=0 FAST_COMPOSER_CACHE_DIR="$WORK/cache-2" "${FC[@]}" update "${FLAGS[@]}" > "$WORK/authjson-2.log" 2>&1 || { cat "$WORK/authjson-2.log" >&2; exit 1; }
if grep -q 'retrying' "$WORK/authjson-2.log"; then
  cat "$WORK/authjson-2.log" >&2; echo "REGRESSION: working auth method not remembered" >&2; exit 1
fi
cmp -s composer.lock "$WORK/composer.lock" || { echo "lock differs from Composer on second run" >&2; exit 1; }
echo "auth-method-remembered: PASS"

# 3. Git's credential helper has the right credentials, auth.json wrong ones: like Composer, Git's
#    own authentication is tried first, so the auth.json token is never needed or retried.
printf '{"http-basic": {"127.0.0.1:%s": {"username": "deploy", "password": "wrong"}}}\n' "$PORT" > auth.json
git config --global credential.helper '!f() { echo username=deploy; echo password=s3cret; }; f'
rm -f composer.lock
FAST_COMPOSER_CACHE_DIR="$WORK/cache-3" "${FC[@]}" update "${FLAGS[@]}" > "$WORK/retry.log" 2>&1 || { cat "$WORK/retry.log" >&2; echo "no fallback to Git's own authentication" >&2; exit 1; }
if grep -q 'retrying' "$WORK/retry.log"; then
  cat "$WORK/retry.log" >&2; echo "REGRESSION: Composer credentials tried before Git's own authentication" >&2; exit 1
fi
echo "auth-git-credentials-first: PASS"

echo "auth-contract: PASS"
