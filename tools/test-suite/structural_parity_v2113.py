#!/usr/bin/env python3
"""Reject v2.11.3 changes outside its explicit runtime-consolidation boundary."""

from __future__ import annotations

import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASELINE = "4ececcc230ca07099b346cb47396ad00bedd5c21"
PRODUCTION_ALLOWLIST = {
    "AnalyzeMatch.html",
    "AnalyzeMatchModal.html",
    "AnalyzeMatches.htm",
    "ChallengeListAssistant.html",
    "DataReconciliation.html",
    "FindMatch.htm",
    "MatchCreationAnalyzer.htm",
    "RecruitMatch.html",
    "RecruitmentAdmin.html",
    "RecruitmentDemandPlanner.html",
    "TaskControl.html",
    "TeamPointsAdmin.html",
    "TeamPointsMigration.html",
    "TournamentAchievementBadgesDemo.html",
    "TournamentManagement.html",
    "index.html",
    "ui-v2.html",
    "assets/js/shared/api-client.js",
    "assets/js/shared/api-request-semantics.js",
    "assets/js/shared/api-transport.js",
    "assets/js/shared/api-request-coordinator.js",
}
ENGINEERING_PREFIXES = (".github/", "tests/", "tools/")
ENGINEERING_FILES = {
    "ARCHITECTURE_v2.11.3.md",
    "RUNTIME_CONSOLIDATION_v2.11.3.md",
    "INSTALL_v2.11.3.md",
}


def changes() -> list[str]:
    result = subprocess.run(
        ["git", "diff", "--name-only", f"{BASELINE}..HEAD"],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return sorted(filter(None, result.stdout.splitlines()))


def main() -> int:
    changed = changes()
    unexpected = [
        path for path in changed
        if path not in PRODUCTION_ALLOWLIST
        and path not in ENGINEERING_FILES
        and not path.startswith(ENGINEERING_PREFIXES)
    ]
    visual_suffixes = (".css", ".png", ".jpg", ".jpeg", ".gif", ".svg", ".webp", ".ico")
    visual = [path for path in changed if path.lower().endswith(visual_suffixes)]
    if unexpected or visual:
        if unexpected:
            print("Unexpected v2.11.3 production differences:\n" + "\n".join(unexpected))
        if visual:
            print("Unexpected v2.11.3 visual asset/style differences:\n" + "\n".join(visual))
        return 1
    print("v2.11.3 runtime-consolidation allowlist and visual-asset parity passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
