#!/usr/bin/env bash
# Called by the Codespace on every start (postStartCommand in devcontainer.json).
# Stops a leftover server and starts start_server.sh in the background, so the
# Garmin connector works as soon as the Codespace is running. Log: server.log
cd "$(dirname "$0")" || exit 1
pkill -f "python3 mcp_wrapper.py" 2>/dev/null
sleep 1
setsid nohup ./start_server.sh > server.log 2>&1 < /dev/null &
echo "Garmin MCP server starting in the background (log: server.log)"
