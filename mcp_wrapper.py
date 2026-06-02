import sys, os, secrets, hashlib, base64, json, time
sys.path.insert(0, '.')
from fastapi import FastAPI, Request, Form
from fastapi.responses import JSONResponse, RedirectResponse, HTMLResponse
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.responses import Response
from garmin_mcp.server import mcp
import uvicorn

app = FastAPI()
BASE_URL = os.environ.get("BASE_URL", "https://web-production-ffb71.up.railway.app")
TOKENS = {}
CODES = {}


# ---------------------------------------------------------------------------
# OAuth endpoints — moeten VOOR de SSE mount komen
# ---------------------------------------------------------------------------

@app.get("/.well-known/oauth-authorization-server")
async def oauth_meta():
    return JSONResponse({
        "issuer": BASE_URL,
        "authorization_endpoint": f"{BASE_URL}/oauth/authorize",
        "token_endpoint": f"{BASE_URL}/oauth/token",
        "registration_endpoint": f"{BASE_URL}/oauth/register",
        "response_types_supported": ["code"],
        "grant_types_supported": ["authorization_code"],
        "code_challenge_methods_supported": ["S256"]
    })


@app.get("/.well-known/oauth-protected-resource")
async def oauth_resource():
    return JSONResponse({
        "resource": BASE_URL,
        "authorization_servers": [BASE_URL]
    })


@app.post("/oauth/register")
async def register(request: Request):
    body = await request.json()
    client_id = secrets.token_hex(16)
    return JSONResponse({"client_id": client_id, "client_secret": "none", **body})


@app.get("/oauth/authorize")
async def authorize(
    request: Request,
    response_type: str = "",
    client_id: str = "",
    redirect_uri: str = "",
    state: str = "",
    code_challenge: str = "",
    code_challenge_method: str = "",
):
    code = secrets.token_hex(16)
    CODES[code] = {
        "redirect_uri": redirect_uri,
        "challenge": code_challenge,
        "client_id": client_id,
    }
    sep = "&" if "?" in redirect_uri else "?"
    return RedirectResponse(f"{redirect_uri}{sep}code={code}&state={state}")


@app.post("/oauth/token")
async def token(
    request: Request,
    grant_type: str = Form(""),
    code: str = Form(""),
    redirect_uri: str = Form(""),
    code_verifier: str = Form(""),
    client_id: str = Form(""),
):
    if code not in CODES:
        return JSONResponse({"error": "invalid_grant"}, status_code=400)

    # Optioneel: PKCE verificatie
    stored = CODES[code]
    if stored.get("challenge"):
        digest = hashlib.sha256(code_verifier.encode()).digest()
        expected = base64.urlsafe_b64encode(digest).rstrip(b"=").decode()
        if expected != stored["challenge"]:
            return JSONResponse({"error": "invalid_grant", "detail": "PKCE mismatch"}, status_code=400)

    access_token = secrets.token_hex(32)
    TOKENS[access_token] = {"client_id": client_id, "expires": time.time() + 3600}
    del CODES[code]
    return JSONResponse({
        "access_token": access_token,
        "token_type": "bearer",
        "expires_in": 3600,
    })


@app.get("/health")
async def health():
    return {"status": "ok"}


# ---------------------------------------------------------------------------
# Token-validatie middleware voor SSE / MCP requests
# ---------------------------------------------------------------------------

class BearerAuthMiddleware(BaseHTTPMiddleware):
    # Paden die GEEN token nodig hebben
    PUBLIC_PATHS = {
        "/health",
        "/.well-known/oauth-authorization-server",
        "/.well-known/oauth-protected-resource",
        "/oauth/register",
        "/oauth/authorize",
        "/oauth/token",
    }

    async def dispatch(self, request: Request, call_next):
        if request.url.path in self.PUBLIC_PATHS:
            return await call_next(request)

        auth = request.headers.get("Authorization", "")
        if not auth.startswith("Bearer "):
            return Response(
                content=json.dumps({"error": "unauthorized", "detail": "Missing Bearer token"}),
                status_code=401,
                media_type="application/json",
            )

        token_val = auth.removeprefix("Bearer ").strip()
        record = TOKENS.get(token_val)
        if not record:
            return Response(
                content=json.dumps({"error": "unauthorized", "detail": "Unknown token"}),
                status_code=401,
                media_type="application/json",
            )
        if record["expires"] < time.time():
            del TOKENS[token_val]
            return Response(
                content=json.dumps({"error": "unauthorized", "detail": "Token expired"}),
                status_code=401,
                media_type="application/json",
            )

        return await call_next(request)


app.add_middleware(BearerAuthMiddleware)


# ---------------------------------------------------------------------------
# SSE mount als LAATSTE — vangt alles op wat hierboven niet gematcht is
# ---------------------------------------------------------------------------

sse_app = mcp.sse_app()
app.mount("/mcp", sse_app)   # Expliciet pad, niet "/" — anders worden OAuth routes overruled


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 8000))
    uvicorn.run(app, host="0.0.0.0", port=port)