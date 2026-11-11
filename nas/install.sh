#!/bin/sh
# One-time install on the Synology NAS. Run as root (Task Scheduler, user-defined script):
#   curl -fsSL https://raw.githubusercontent.com/StephanH76/garmin-givemydata/main/nas/install.sh | sh -s -- 'PASSWORD' 'GITHUB_TOKEN'
# Installs into /volume1/garmin (shared folder), downloads garmin.db, starts the server.
ROOT=${GARMIN_ROOT:-/volume1/garmin}
SRC=${GARMIN_SRC:-https://raw.githubusercontent.com/StephanH76/garmin-givemydata/main/nas}
mkdir -p "$ROOT/mcp" "$ROOT/data" || exit 1
cd "$ROOT/mcp" || exit 1
LOG="$ROOT/mcp/install.log"
echo "== $(date) install" > "$LOG"
for f in server.php fetch_db.php start.sh config.example.php; do
  curl -fsSL "$SRC/$f" -o "$f" >> "$LOG" 2>&1 || wget -q -O "$f" "$SRC/$f" >> "$LOG" 2>&1 || { echo "download of $f failed" >> "$LOG"; exit 1; }
done
chmod 755 start.sh
PHP=""
for p in /usr/local/bin/php74 /usr/local/bin/php80 /usr/local/bin/php81 /usr/local/bin/php82 /usr/bin/php php; do
  if command -v "$p" >/dev/null 2>&1; then PHP="$p"; break; fi
done
echo "PHP: $PHP" >> "$LOG"
if [ -n "$1" ]; then
  "$PHP" -r 'file_put_contents($argv[1], "<?php\nreturn " . var_export(["password" => $argv[2], "db_path" => $argv[3], "base_url" => "", "state_dir" => dirname($argv[1])], true) . ";\n");' \
    "$ROOT/mcp/config.php" "$1" "$ROOT/data/garmin.db"
  chmod 600 config.php
fi
if [ -n "$2" ]; then printf '%s' "$2" > github_token; chmod 600 github_token; fi
sh start.sh update
sh start.sh
cat server.log >> "$LOG"
echo "== klaar. Bekijk install.log en server.log in de map garmin/mcp." >> "$LOG"
