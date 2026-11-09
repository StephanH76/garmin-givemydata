#!/usr/bin/env python3
"""Download the newest garmin.db from the private draft release "garmin-db".

The Windows laptop uploads garmin.db there every week (weekly_sync_windows.bat).
A draft release is only visible to people with write access to the repository,
so the database does not become public even though the repository is.

Runs in a Codespace (uses GITHUB_TOKEN and GITHUB_REPOSITORY, which Codespaces
sets) or anywhere else with those two environment variables.
"""

import os
import sys
from datetime import datetime, timezone
from pathlib import Path

import httpx

TAG = "garmin-db"
ASSET = "garmin.db"
API = os.environ.get("GITHUB_API_URL", "https://api.github.com")


def main() -> int:
    token = os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    repo = os.environ.get("GITHUB_REPOSITORY", "StephanH76/garmin-givemydata")
    if not token:
        print("No GITHUB_TOKEN found - cannot fetch garmin.db.")
        return 1

    data_dir = Path(os.environ.get("GARMIN_DATA_DIR", Path(__file__).resolve().parent.parent))
    target = data_dir / ASSET
    headers = {"Authorization": f"Bearer {token}", "Accept": "application/vnd.github+json"}

    with httpx.Client(headers=headers, timeout=60, follow_redirects=True) as client:
        r = client.get(f"{API}/repos/{repo}/releases", params={"per_page": 100})
        r.raise_for_status()
        release = next((rel for rel in r.json() if rel.get("tag_name") == TAG), None)
        if release is None:
            print(f"No release '{TAG}' found yet - run weekly_sync_windows.bat on the laptop first.")
            return 1
        asset = next((a for a in release.get("assets", []) if a["name"] == ASSET), None)
        if asset is None:
            print(f"Release '{TAG}' has no {ASSET} attached.")
            return 1

        uploaded = datetime.fromisoformat(asset["updated_at"].replace("Z", "+00:00"))
        if target.exists():
            local = datetime.fromtimestamp(target.stat().st_mtime, tz=timezone.utc)
            if local >= uploaded:
                print(f"garmin.db is up to date (uploaded {uploaded:%Y-%m-%d %H:%M} UTC).")
                return 0

        print(f"Downloading garmin.db uploaded {uploaded:%Y-%m-%d %H:%M} UTC ({asset['size'] / 1e6:.1f} MB)...")
        tmp = target.with_suffix(".db.download")
        with client.stream("GET", asset["url"], headers={"Accept": "application/octet-stream"}) as resp:
            resp.raise_for_status()
            with open(tmp, "wb") as f:
                for chunk in resp.iter_bytes():
                    f.write(chunk)
        if tmp.stat().st_size != asset["size"]:
            tmp.unlink()
            print("Download incomplete - keeping the existing garmin.db.")
            return 1
        # Stale WAL/SHM files from the old database must not be applied to the new one
        for suffix in ("-wal", "-shm"):
            Path(str(target) + suffix).unlink(missing_ok=True)
        os.replace(tmp, target)
        print("garmin.db updated.")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except httpx.HTTPError as e:
        print(f"Could not fetch garmin.db: {e}")
        sys.exit(1)
