#!/usr/bin/env bash
# Starts the Garmin MCP server in the background and makes port 8000 public.
# Runs automatically on every Codespace start (postStartCommand) and when the
# editor opens (.vscode/tasks.json, runOn folderOpen). Safe to run more than
# once: if the server already answers, it only makes the port public again.
# Can also be run by hand: ./autostart_server.sh   Log: server.log, autostart.log
cd "$(dirname "$0")" || exit 1
exec > >(tee -a autostart.log) 2>&1
echo "== $(date '+%Y-%m-%d %H:%M:%S') autostart"

server_up() {
  python3 -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=2)" 2>/dev/null
}

# Codespaces resets port 8000 to private after a stop/start, and Claude cannot
# log in to GitHub. With a Codespaces secret GARMIN_PORTS_TOKEN (a GitHub token
# with only the "codespace" scope) the port is made public automatically.
make_port_public() {
  if [ -z "$GARMIN_PORTS_TOKEN" ] || [ -z "$CODESPACE_NAME" ] || ! command -v gh >/dev/null; then
    echo "Port 8000: set it to Public by hand (no GARMIN_PORTS_TOKEN secret or gh)."
    return
  fi
  for _ in $(seq 1 20); do
    if out=$(GH_TOKEN="$GARMIN_PORTS_TOKEN" gh codespace ports visibility 8000:public -c "$CODESPACE_NAME" 2>&1); then
      echo "Port 8000 is public."
      return
    fi
    sleep 3
  done
  echo "Could not make port 8000 public: $out"
  echo "Set it to Public by hand (Ports tab)."
}

# Watcher that switches port 8000 back to public whenever it turns private
if ! pgrep -f "keep_port_public.sh" >/dev/null; then
  setsid nohup ./keep_port_public.sh > /dev/null 2>&1 < /dev/null &
  echo "Port watcher started."
fi

if server_up; then
  echo "Garmin MCP server is already running."
  make_port_public
  exit 0
fi

pkill -f "python3 mcp_wrapper.py" 2>/dev/null
sleep 1
setsid nohup ./start_server.sh > server.log 2>&1 < /dev/null &
echo "Garmin MCP server starting in the background (log: server.log)..."
# Wait until the server answers. If this script exited right away, the caller
# would clean up the new server together with this script before it detached.
for _ in $(seq 1 90); do
  if server_up; then
    echo "Garmin MCP server is running."
    make_port_public
    exit 0
  fi
  sleep 2
done
echo "Server did not answer within 3 minutes - see server.log"
exit 1
