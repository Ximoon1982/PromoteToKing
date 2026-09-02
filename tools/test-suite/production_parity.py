#!/usr/bin/env python3
"""Enforce v2.11.0 R6 production parity for the v2.11.1 release branch."""

from __future__ import annotations

import argparse
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
R6_BASELINE = "93480c852fc4c554c9a404e5d68b0ac51efed04b"
PRODUCTION_ALLOWLIST = {"server/team-points/src/OAuthSession.php"}
NON_PRODUCTION_PREFIXES = (".github/", "tests/", "tools/test-suite/", "tools/release/")
NON_PRODUCTION_FILES = {"TEST_SUITE_AUDIT_v2.11.1.md"}


def changed_paths(baseline: str) -> list[str]:
    completed = subprocess.run(
        ["git", "diff", "--name-only", f"{baseline}..HEAD", "--"],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return sorted(filter(None, completed.stdout.splitlines()))


def unexpected(paths: list[str]) -> list[str]:
    return [
        path for path in paths
        if path not in PRODUCTION_ALLOWLIST
        and path not in NON_PRODUCTION_FILES
        and not path.startswith(NON_PRODUCTION_PREFIXES)
    ]


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--baseline", default=R6_BASELINE)
    args = parser.parse_args()
    paths = changed_paths(args.baseline)
    failures = unexpected(paths)
    print(f"Parity baseline: {args.baseline}")
    print("Approved production differences: " + ", ".join(sorted(PRODUCTION_ALLOWLIST)))
    if failures:
        print("Unexpected differences from v2.11.0 R6:")
        print("\n".join(failures))
        return 1
    if not PRODUCTION_ALLOWLIST.issubset(paths):
        print("Approved OAuthSession.php release difference is missing.")
        return 1
    print(f"Production parity passed across {len(paths)} changed paths.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
