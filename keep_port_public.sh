#!/usr/bin/env bash
# Keeps port 8000 public while the Codespace runs. Codespaces (or the editor
# reconnecting) can switch the port back to private after it was made public,
# which breaks the Claude connector. Checks every 30 s for the first 10 minutes,
# then every 2 minutes. Started by autostart_server.sh; log: autostart.log
cd "$(dirname "$0")" || exit 1
[ -n "$GARMIN_PORTS_TOKEN" ] && [ -n "$CODESPACE_NAME" ] && command -v gh >/dev/null || exit 0
export GH_TOKEN="$GARMIN_PORTS_TOKEN"
start=$(date +%s)
while true; do
  vis=$(gh codespace ports -c "$CODESPACE_NAME" --json sourcePort,visibility \
        -q '.[] | select(.sourcePort == 8000) | .visibility' 2>/dev/null)
  if [ -n "$vis" ] && [ "$vis" != "public" ]; then
    if gh codespace ports visibility 8000:public -c "$CODESPACE_NAME" >/dev/null 2>&1; then
      echo "$(date '+%Y-%m-%d %H:%M:%S') port 8000 was $vis - set back to public" >> autostart.log
    fi
  fi
  if [ $(( $(date +%s) - start )) -lt 600 ]; then sleep 30; else sleep 120; fi
done
