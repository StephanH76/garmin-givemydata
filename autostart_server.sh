#!/usr/bin/env bash
# Run automatically when the Codespace editor opens (.vscode/tasks.json, runOn folderOpen),
# also when opened from Safari on a phone. Can also be run by hand.
# Stops a leftover server and starts start_server.sh in the background, so the
# Garmin connector works as soon as the Codespace is running. Log: server.log
cd "$(dirname "$0")" || exit 1
pkill -f "python3 mcp_wrapper.py" 2>/dev/null
sleep 1
setsid nohup ./start_server.sh > server.log 2>&1 < /dev/null &
echo "Garmin MCP server starting in the background (log: server.log)..."
# Wait until the server answers. If this script exited right away, the editor
# would clean up the new server together with this script before it detached.
for _ in $(seq 1 90); do
  if python3 -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=2)" 2>/dev/null; then
    echo "Garmin MCP server is running."
    exit 0
  fi
  sleep 2
done
echo "Server did not answer within 3 minutes - see server.log"
exit 1
