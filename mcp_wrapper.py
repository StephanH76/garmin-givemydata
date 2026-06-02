import sys
sys.path.insert(0, '.')
from garmin_mcp.server import mcp
import uvicorn

if __name__ == "__main__":
    mcp.run(transport="streamable-http")
