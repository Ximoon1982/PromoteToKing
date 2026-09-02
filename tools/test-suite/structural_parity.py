#!/usr/bin/env python3
"""Fail v2.11.2 when consolidation escapes its approved structural boundary."""

from __future__ import annotations

import hashlib
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASELINE = "b8bf26c7c41ca1914323717766bca995139291aa"
PRODUCTION_ALLOWLIST = {
    "server/team-points/src/AchievementArtwork.php",
    "server/team-points/src/AchievementCatalog.php",
    "server/team-points/src/AnalyticsBuilder.php",
    "server/team-points/src/AnalyticsRefreshRuntime.php",
    "server/team-points/src/ClubIntelligenceService.php",
    "server/team-points/src/SqlReadGateway.php",
}
ENGINEERING_PREFIXES = (".github/", "tests/", "tools/")
ENGINEERING_FILES = {"STRUCTURAL_CONSOLIDATION_v2.11.2.md"}


def changes() -> list[str]:
    result = subprocess.run(["git", "diff", "--name-only", f"{BASELINE}..HEAD"], cwd=ROOT, check=True, text=True, capture_output=True)
    return sorted(filter(None, result.stdout.splitlines()))


def main() -> int:
    unexpected = [path for path in changes() if path not in PRODUCTION_ALLOWLIST and path not in ENGINEERING_FILES and not path.startswith(ENGINEERING_PREFIXES)]
    parity = json.loads((ROOT / "tests/v2.11.2-ui-parity.json").read_text(encoding="utf-8"))
    altered = []
    for relative, expected in parity["files"].items():
        actual = hashlib.sha256((ROOT / relative).read_bytes()).hexdigest()
        if actual != expected:
            altered.append(relative)
    if unexpected or altered:
        if unexpected: print("Unexpected v2.11.2 production differences:\n" + "\n".join(unexpected))
        if altered: print("UI/display/runtime parity changed:\n" + "\n".join(altered))
        return 1
    print("v2.11.2 structural and byte-level UI parity passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
