"""End-to-end test of the connector: OAuth login, MCP call, restart, same token.

Run: GARMIN_DATA_DIR=<folder with garmin.db> python3 tools/test_connector_e2e.py
"""
import base64, hashlib, json, os, re, secrets, subprocess, sys, time, httpx
B = "http://127.0.0.1:8765"
env = dict(os.environ, GARMIN_DATA_DIR=os.environ.get("GARMIN_DATA_DIR", "."), MCP_PASSWORD="test-wachtwoord-123", PORT="8765", BASE_URL=B)
def start():
    p = subprocess.Popen([sys.executable, "mcp_wrapper.py"], env=env, stdout=open("/tmp/srv.log", "a"), stderr=subprocess.STDOUT)
    for _ in range(60):
        try:
            if httpx.get(B + "/health", timeout=1).status_code == 200: return p
        except Exception: pass
        time.sleep(0.5)
    raise SystemExit("server not up: " + open("/tmp/srv.log").read()[-2000:])
def mcp_call(tok):
    h = {"Authorization": f"Bearer {tok}", "Accept": "application/json, text/event-stream", "Content-Type": "application/json"}
    with httpx.Client(timeout=30) as c:
        r = c.post(B + "/mcp", headers=h, json={"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "t", "version": "1"}}})
        assert r.status_code == 200, (r.status_code, r.text[:300])
        sid = r.headers.get("mcp-session-id"); h2 = dict(h); 
        if sid: h2["mcp-session-id"] = sid
        c.post(B + "/mcp", headers=h2, json={"jsonrpc": "2.0", "method": "notifications/initialized"})
        r = c.post(B + "/mcp", headers=h2, json={"jsonrpc": "2.0", "id": 2, "method": "tools/call", "params": {"name": "garmin_query", "arguments": {"sql": "SELECT activity_name, distance_meters FROM activity"}}})
        txt = r.text; m = re.search(r'data: (\{.*\})', txt); body = json.loads(m.group(1)) if m else r.json()
        return body["result"]["content"][0]["text"]
# 1 discovery + unauthenticated
p = start()
r = httpx.post(B + "/mcp", json={}); print("unauth:", r.status_code, r.headers.get("www-authenticate"))
print("meta:", httpx.get(B + "/.well-known/oauth-protected-resource/mcp").json())
meta = httpx.get(B + "/.well-known/oauth-authorization-server").json(); print("as:", meta["token_endpoint"])
# 2 register, authorize (wrong + right pw), token
cid = httpx.post(meta["registration_endpoint"], json={"redirect_uris": ["https://claude.ai/api/mcp/auth_callback"]}).json()["client_id"]
ver = secrets.token_urlsafe(40); ch = base64.urlsafe_b64encode(hashlib.sha256(ver.encode()).digest()).rstrip(b"=").decode()
pg = httpx.get(meta["authorization_endpoint"], params={"response_type": "code", "client_id": cid, "redirect_uri": "https://claude.ai/api/mcp/auth_callback", "state": "xyz", "code_challenge": ch, "code_challenge_method": "S256"})
print("login page:", pg.status_code, "Wachtwoord" in pg.text)
bad = httpx.post(B + "/oauth/authorize", data={"password": "fout", "client_id": cid, "redirect_uri": "https://claude.ai/api/mcp/auth_callback", "state": "xyz", "code_challenge": ch})
print("wrong pw:", bad.status_code, "Onjuist" in bad.text)
ok = httpx.post(B + "/oauth/authorize", data={"password": "test-wachtwoord-123", "client_id": cid, "redirect_uri": "https://claude.ai/api/mcp/auth_callback", "state": "xyz", "code_challenge": ch})
loc = ok.headers["location"]; print("redirect:", ok.status_code, loc[:60])
code = re.search(r"code=([0-9a-f]+)", loc).group(1)
tok = httpx.post(meta["token_endpoint"], data={"grant_type": "authorization_code", "code": code, "code_verifier": ver, "client_id": cid, "redirect_uri": "https://claude.ai/api/mcp/auth_callback"}).json()
print("token:", tok["access_token"][:12], tok["expires_in"])
print("mcp call:", repr(mcp_call(tok["access_token"])[:120]))
print("fake token:", httpx.post(B + "/mcp", headers={"Authorization": "Bearer gm1.abc.def"}, json={}).status_code)
# 3 restart: token must still work, no token file needed
p.terminate(); p.wait(); 
pass  # signed tokens need no token file
p = start(); print("after restart mcp call:", repr(mcp_call(tok["access_token"])[:80]))
# SSE transport still guarded + works
with httpx.stream("GET", B + "/sse", headers={"Authorization": "Bearer " + tok["access_token"]}, timeout=10) as r: print("sse:", r.status_code, next(r.iter_lines()))
print("health:", httpx.get(B + "/health").json())
p.terminate()
