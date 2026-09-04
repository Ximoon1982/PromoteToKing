#!/usr/bin/env python3
"""Reject v2.11.4 changes outside its explicit operational-robustness boundary."""

from __future__ import annotations

import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASELINE = "dcd71c8e76c07defacf6270aff4224b10484968b"
PRODUCTION_ALLOWLIST = {
    "assets/js/pages/task-control.js",
    "server/team-points-green/public/api.php",
    "server/team-points-green/src/GreenAnalyticsBootstrap.php",
    "server/team-points-green/src/GreenCompatibility.php",
    "server/team-points-green/src/GreenRepository.php",
    "server/team-points-green/src/GreenWorker.php",
}
ENGINEERING_PREFIXES = (".github/", "tests/", "tools/")
ENGINEERING_FILES = {
    "OPERATIONAL_ROBUSTNESS_v2.11.4.md",
    "INSTALL_v2.11.4.md",
    "REPOSITORY_POLICY.md",
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
            print("Unexpected v2.11.4 production differences:\n" + "\n".join(unexpected))
        if visual:
            print("Unexpected v2.11.4 visual asset/style differences:\n" + "\n".join(visual))
        return 1
    print("v2.11.4 operational-robustness allowlist and visual-asset parity passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
