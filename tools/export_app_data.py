#!/usr/bin/env python3
"""Export a compact JSON file with Garmin data for the Hardloop-app artifact.

Reads garmin.db (GARMIN_DATA_DIR or the repo folder) and writes app_data/garmin.json
with the same rows the app otherwise asks the Garmin connector for. The file is
published as a file of the (private) artifact, so the app works on every device
without a running server.

    python3 tools/fetch_garmin_db.py      # newest garmin.db from the draft release
    python3 tools/export_app_data.py      # -> app_data/garmin.json
"""

import json
import os
import sqlite3
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA_DIR = Path(os.environ.get("GARMIN_DATA_DIR", ROOT))
OUT = Path(os.environ.get("APP_DATA_OUT", ROOT / "app_data" / "garmin.json"))

QUERIES = {
    "acts": """SELECT start_time_local, activity_name, activity_type, distance_meters, duration_seconds,
                      average_hr, max_hr, training_load
               FROM activity WHERE date(start_time_local) >= date('now','-260 days')
               ORDER BY start_time_local""",
    "days": """SELECT calendar_date, resting_heart_rate, body_battery_at_wake, average_stress_level
               FROM daily_summary WHERE calendar_date >= date('now','-60 days') ORDER BY calendar_date""",
    "pred": "SELECT calendar_date, time_5k, time_10k, time_half_marathon, time_marathon FROM race_predictions ORDER BY calendar_date DESC LIMIT 60",
    "vo2": "SELECT calendar_date, value FROM vo2max WHERE lower(sport) LIKE '%run%' OR sport IS NULL OR lower(sport) = 'generic' ORDER BY calendar_date DESC LIMIT 200",
    "weight": "SELECT calendar_date, weight, body_fat, bmi FROM weight WHERE weight IS NOT NULL ORDER BY calendar_date DESC LIMIT 200",
    "hrv": "SELECT calendar_date, weekly_avg, last_night_avg, status, baseline_low, baseline_upper FROM hrv ORDER BY calendar_date DESC LIMIT 30",
    "ts": "SELECT calendar_date, status, acute_load, chronic_load FROM training_status ORDER BY calendar_date DESC LIMIT 1",
    "pr": "SELECT pr_type, value, pr_date, activity_type FROM personal_record",
}


def main() -> int:
    db = DATA_DIR / "garmin.db"
    if not db.exists():
        print(f"No garmin.db in {DATA_DIR}")
        return 1
    conn = sqlite3.connect(f"file:{db}?mode=ro", uri=True)
    conn.row_factory = sqlite3.Row
    out = {}
    for key, sql in QUERIES.items():
        try:
            out[key] = [dict(r) for r in conn.execute(sql)]
        except sqlite3.Error as e:  # a missing table must not break the export
            print(f"{key}: skipped ({e})")
            out[key] = []
    last = out["acts"][-1]["start_time_local"] if out["acts"] else None
    out["meta"] = {
        "exported_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "db_modified": datetime.fromtimestamp(db.stat().st_mtime, tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "last_activity": last,
        "counts": {k: len(v) for k, v in out.items() if isinstance(v, list)},
    }
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(out, separators=(",", ":"), default=str))
    print(f"Wrote {OUT} ({OUT.stat().st_size / 1024:.0f} KB), last activity {last}, counts {out['meta']['counts']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
