#!/usr/bin/env bash
# Start the Garmin MCP server in the Codespace.
# 1) Fetches the newest garmin.db uploaded by the laptop (private draft release).
# 2) Starts mcp_wrapper.py with the right public URL.
cd "$(dirname "$0")" || exit 1
python3 tools/fetch_garmin_db.py || echo "Could not update garmin.db - starting with the existing data."
if [ -z "$BASE_URL" ] && [ -n "$CODESPACE_NAME" ]; then
  export BASE_URL="https://${CODESPACE_NAME}-8000.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
fi
echo "Server URL: $BASE_URL"
exec python3 mcp_wrapper.py
