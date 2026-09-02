#!/usr/bin/env python3
"""Fail v2.11.2 when consolidation escapes its approved structural boundary."""

from __future__ import annotations

import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASELINE = "b8bf26c7c41ca1914323717766bca995139291aa"
PRODUCTION_ALLOWLIST = {
    "index.html",
    "ui-v2.html",
    "assets/js/pages/admin-features.js",
    "assets/js/pages/dashboard-v2.js",
    "server/team-points/src/AchievementArtwork.php",
    "server/team-points/src/AchievementCatalog.php",
    "server/team-points/src/AnalyticsBuilder.php",
    "server/team-points/src/AnalyticsRefreshRuntime.php",
    "server/team-points/src/ClubIntelligenceService.php",
    "server/team-points/src/SqlReadGateway.php",
    "server/team-points/src/AdminJob/JobCheckpointStore.php",
    "server/team-points/src/AdminJob/JobRunner.php",
    "server/team-points/src/AdminJob/JobState.php",
    "server/team-points/src/AdminJob/JobTelemetry.php",
    "server/team-points/src/InternalErrorCategory.php",
}
PRODUCTION_ALLOWLIST.update({
    path.relative_to(ROOT).as_posix()
    for directory in (ROOT / "assets/js/admin", ROOT / "assets/js/dashboard")
    for path in directory.glob("*.js")
})
ENGINEERING_PREFIXES = (".github/", "tests/", "tools/")
ENGINEERING_FILES = {"STRUCTURAL_CONSOLIDATION_v2.11.2.md", "INSTALL_v2.11.2.md", "ARCHITECTURE_v2.11.2.md", "PERSISTENCE_OWNERSHIP_v2.11.2.md"}


def changes() -> list[str]:
    result = subprocess.run(["git", "diff", "--name-only", f"{BASELINE}..HEAD"], cwd=ROOT, check=True, text=True, capture_output=True)
    return sorted(filter(None, result.stdout.splitlines()))


def main() -> int:
    unexpected = [path for path in changes() if path not in PRODUCTION_ALLOWLIST and path not in ENGINEERING_FILES and not path.startswith(ENGINEERING_PREFIXES)]
    visual_suffixes = (".css", ".png", ".jpg", ".jpeg", ".gif", ".svg", ".webp", ".ico")
    visual = [path for path in changes() if path.lower().endswith(visual_suffixes)]
    if unexpected or visual:
        if unexpected: print("Unexpected v2.11.2 production differences:\n" + "\n".join(unexpected))
        if visual: print("Unexpected visual asset/style differences:\n" + "\n".join(visual))
        return 1
    print("v2.11.2 structural allowlist and visual-asset parity passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
