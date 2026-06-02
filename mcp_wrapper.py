import sys
sys.path.insert(0, '.')
from fastapi import FastAPI
from fastapi.responses import JSONResponse
from garmin_mcp.server import mcp
import uvicorn

app = FastAPI()

@app.get("/.well-known/oauth-authorization-server")
async def oauth_metadata():
    return JSONResponse({})

@app.get("/health")
async def health():
    return {"status": "ok"}

sse_app = mcp.sse_app()
app.mount("/", sse_app)

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8000)
