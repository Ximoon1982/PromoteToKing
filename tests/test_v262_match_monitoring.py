#!/usr/bin/env python3
from __future__ import annotations
import importlib.util
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("p2k_local", ROOT / "serve_local.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def check(value, message):
    if not value:
        raise AssertionError(message)


def iso(epoch: int) -> str:
    return datetime.fromtimestamp(epoch, timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def main():
    now = 2_000_000_000
    first_day = module.match_monitoring_schedule({"addedAt": iso(now - 3600), "lastCapturedAt": iso(now - 3601), "startTime": now + 10 * 86400}, now)
    check(first_day["phase"] == "first-24-hours" and first_day["intervalSeconds"] == 3600 and first_day["due"], "First-day hourly sampling is wrong")

    standard = module.match_monitoring_schedule({"addedAt": iso(now - 2 * 86400), "lastCapturedAt": iso(now - 11 * 3600), "startTime": now + 10 * 86400}, now)
    check(standard["phase"] == "standard" and standard["intervalSeconds"] == 43200 and not standard["due"], "Standard 12-hour sampling is wrong")

    within_96 = module.match_monitoring_schedule({"addedAt": iso(now - 2 * 86400), "lastCapturedAt": iso(now - 6 * 3600 - 1), "startTime": now + 80 * 3600}, now)
    check(within_96["phase"] == "within-96-hours" and within_96["intervalSeconds"] == 21600 and within_96["due"], "96-hour sampling window is wrong")

    within_48 = module.match_monitoring_schedule({"addedAt": iso(now - 2 * 86400), "lastCapturedAt": iso(now - 3601), "startTime": now + 30 * 3600}, now)
    check(within_48["phase"] == "within-48-hours" and within_48["intervalSeconds"] == 3600 and within_48["due"], "48-hour hourly sampling window is wrong")

    common = (ROOT / "api/_common.php").read_text()
    router = (ROOT / "api/router.php").read_text()
    registry = (ROOT / "server/shared/TaskRegistry.php").read_text()
    control = (ROOT / "server/control/public/api.php").read_text()
    shared_history = (ROOT / "assets/js/shared/match-history-ui.js").read_text()
    admin_history = "\n".join(path.read_text() for path in (ROOT / "assets/js/admin").glob("*.js")) + (ROOT / "assets/js/pages/admin-features.js").read_text()
    cron_setup = (ROOT / "CRON_SETUP_v2.8.0.md").read_text()

    for token in ("MATCH_MONITORING_FIRST_DAY_SECONDS", "MATCH_MONITORING_DEFAULT_SECONDS", "MATCH_MONITORING_96H_SECONDS", "MATCH_MONITORING_48H_SECONDS", "samplingDue", "nextCaptureAt", "dueReferences", "deferredReferences"):
        check(token in common, f"PHP match-monitoring policy is missing {token}")
    check("17 * * * *" in cron_setup and "every hour" in cron_setup.lower(), "Hourly CRON documentation is missing")
    check("expected_interval_seconds' => 3600" in registry and "expectedIntervalSeconds'=>3600" in router, "Hourly unified health cadence is missing")
    check("due_now" in control and "twelve_hourly_standard" in control, "Unified work report lacks adaptive buckets")
    check("omitUnchangedLineups" in shared_history and "lineupFingerprint" in shared_history, "Public tracking graph does not omit identical lineups")
    check("omittedSnapshots" in admin_history and "lineupFingerprint" in admin_history, "Admin tracking graph does not omit identical lineups")
    check("ratingChanged" in shared_history and "ratingChanged" in admin_history, "Rating changes are not represented in lineup evolution")
    check("schemaVersion\"] = 3" in (ROOT / "serve_local.py").read_text() or '"schemaVersion": 3' in (ROOT / "data/match-tracking/index.json").read_text(), "Tracking registry schema was not advanced")
    print("v2.8.0 adaptive match-monitoring tests passed.")


if __name__ == "__main__":
    main()
