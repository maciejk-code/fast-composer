#!/usr/bin/env bash
# Install a user-level macOS launchd job that warms one project's Fast Composer cache.
# Usage: bash scripts/install-launchd-warm.sh /absolute/path/to/project
set -euo pipefail

if [ "$(uname -s)" != "Darwin" ]; then
  echo "This installer requires macOS (launchd)." >&2
  exit 2
fi
if [ "$#" -ne 1 ] || [ ! -f "$1/composer.json" ]; then
  echo "Usage: bash scripts/install-launchd-warm.sh /path/to/composer-project" >&2
  exit 2
fi

PROJECT="$(cd "$1" && pwd -P)"
FC_BIN="$(command -v fast-composer || true)"
PHP_BIN="$(command -v php || true)"
if [ -z "$FC_BIN" ] || [ -z "$PHP_BIN" ]; then
  echo "Install fast-composer globally and ensure both fast-composer and php are on PATH." >&2
  exit 2
fi

xml() {
  printf '%s' "$1" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g'
}

IDENT="$(printf '%s' "$PROJECT" | shasum -a 256 | cut -c1-12)"
LABEL="dev.fast-composer.cache-warm.$IDENT"
DIR="$HOME/Library/LaunchAgents"
LOG_DIR="$HOME/Library/Logs"
PLIST="$DIR/$LABEL.plist"
mkdir -p "$DIR" "$LOG_DIR"
TMP="$(mktemp "$DIR/.fast-composer-XXXXXXXX")"
trap 'rm -f "$TMP"' EXIT

# launchd does not load interactive shell profiles. Include common PHP/Git locations;
# credentials for private repositories must work non-interactively.
PATH_VALUE="$(dirname "$FC_BIN"):$(dirname "$PHP_BIN"):/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin"
cat > "$TMP" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$(xml "$LABEL")</string>
  <key>ProgramArguments</key>
  <array>
    <string>/bin/sh</string>
    <string>-c</string>
    <string>cd "\$1" &amp;&amp; exec "\$2" cache warm</string>
    <string>cache-warm</string>
    <string>$(xml "$PROJECT")</string>
    <string>$(xml "$FC_BIN")</string>
  </array>
  <key>EnvironmentVariables</key>
  <dict><key>PATH</key><string>$(xml "$PATH_VALUE")</string></dict>
  <key>RunAtLoad</key><true/>
  <key>StartInterval</key><integer>1800</integer>
  <key>StandardOutPath</key><string>$(xml "$LOG_DIR/$LABEL.log")</string>
  <key>StandardErrorPath</key><string>$(xml "$LOG_DIR/$LABEL.error.log")</string>
</dict>
</plist>
PLIST
chmod 600 "$TMP"
mv "$TMP" "$PLIST"
launchctl bootout "gui/$(id -u)/$LABEL" >/dev/null 2>&1 || true
launchctl bootstrap "gui/$(id -u)" "$PLIST"
launchctl kickstart -k "gui/$(id -u)/$LABEL"
echo "Installed $LABEL; runs on login and approximately every 30 minutes while macOS is awake."
echo "Inspect: launchctl print gui/$(id -u)/$LABEL"
echo "Logs: $LOG_DIR/$LABEL.log"
echo "Uninstall: launchctl bootout gui/$(id -u)/$LABEL && rm '$PLIST'"
