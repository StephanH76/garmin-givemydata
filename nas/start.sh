#!/bin/sh
# Garmin MCP on the Synology NAS.
#   sh start.sh          start the server + Tailscale Funnel (boot-up task)
#   sh start.sh update   download the newest garmin.db (daily task)
# Log: server.log next to this script.
DIR=$(cd "$(dirname "$0")" && pwd)
LOG="$DIR/server.log"
PORT=8099
PHP=""
for p in /usr/local/bin/php74 /usr/local/bin/php80 /usr/local/bin/php81 /usr/local/bin/php82 /usr/bin/php php; do
  if command -v "$p" >/dev/null 2>&1; then PHP="$p"; break; fi
done
[ -z "$PHP" ] && { echo "$(date) no PHP found - install PHP 7.4 in Package Center" >> "$LOG"; exit 1; }
EXT=""
"$PHP" -m 2>/dev/null | grep -qi '^pdo_sqlite' || EXT="-d extension=pdo_sqlite.so -d extension=sqlite3.so"
"$PHP" $EXT -m 2>/dev/null | grep -qi '^openssl' || EXT="$EXT -d extension=openssl.so"

if [ "$1" = "update" ]; then
  "$PHP" $EXT "$DIR/fetch_db.php" >> "$LOG" 2>&1
  exit $?
fi

echo "== $(date) start ($PHP)" >> "$LOG"
pkill -f "$DIR/server.php" 2>/dev/null
sleep 1
PHP_CLI_SERVER_WORKERS=2 nohup "$PHP" $EXT -S 127.0.0.1:$PORT "$DIR/server.php" >> "$LOG" 2>&1 &
sleep 3
if wget -q -O - "http://127.0.0.1:$PORT/health" >> "$LOG" 2>&1 || curl -fsS "http://127.0.0.1:$PORT/health" >> "$LOG" 2>&1; then
  echo "" >> "$LOG"; echo "server ok" >> "$LOG"
else
  echo "server did not start - see above" >> "$LOG"
fi

TS=/var/packages/Tailscale/target/bin/tailscale
[ -x "$TS" ] || TS=$(command -v tailscale)
if [ -z "$TS" ]; then echo "Tailscale not found" >> "$LOG"; exit 1; fi
sleep 5
# Public HTTPS address https://<nas>.<tailnet>.ts.net -> this server. The first time,
# Tailscale prints a link to approve Funnel for your tailnet: open it once.
timeout 20 "$TS" funnel --bg $PORT >> "$LOG" 2>&1 || \
  { timeout 20 "$TS" serve https / "http://127.0.0.1:$PORT" >> "$LOG" 2>&1; timeout 20 "$TS" funnel 443 on >> "$LOG" 2>&1; }
"$TS" funnel status >> "$LOG" 2>&1
