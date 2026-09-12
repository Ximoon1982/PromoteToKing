#!/usr/bin/env python3
"""Select immutable production files from a Git revision or installed tree."""

from __future__ import annotations

import argparse
from pathlib import Path, PurePosixPath
import subprocess

ROOT_FILES = {".htaccess", "VERSION"}
ROOT_PAGES = set(
    "AnalyzeMatch.html AnalyzeMatchModal.html AnalyzeMatches.htm "
    "ChallengeListAssistant.html ClubIntelligence.html DataReconciliation.html "
    "FindMatch.htm InsightsHealth.html LeagueSeasonCenter.html LiveRanks.html "
    "LiveRanksDemo.html MatchCreationAnalyzer.htm MigrationInstaller.html "
    "Opponents.html OpponentsDemo.html RecruitMatch.html RecruitmentAdmin.html "
    "RecruitmentDemandPlanner.html TaskControl.html TaskLogs.html TeamInsights.html "
    "TeamPointsAdmin.html TeamPointsMigration.html "
    "TournamentAchievementBadgesDemo.html TournamentManagement.html "
    "Tournaments.html UIv2RepresentativeDemo.html index.html ui-v2.html".split()
)
IMMUTABLE_ROOTS = {
    "api", "artwork", "artwork-masters", "assets", "auth", "resources",
    "server", "trophies",
}
MUTABLE_SEGMENTS = {
    "backup", "backups", "cache", "caches", "data", "log", "logs",
    "processed", "quarantine", "runtime", "sessions", "storage", "tmp",
    "upload", "uploads",
}


def included(relative_path: str) -> bool:
    """Return whether a repository-relative file is managed immutable content."""
    path = PurePosixPath(relative_path)
    if relative_path in ROOT_FILES | ROOT_PAGES:
        return True
    if not path.parts or path.parts[0] not in IMMUTABLE_ROOTS:
        return False
    if any(part.lower() in MUTABLE_SEGMENTS for part in path.parts[1:]):
        return False
    if "config" in (part.lower() for part in path.parts):
        return path.name == ".htaccess" or ".example." in path.name
    lowered = path.name.lower()
    if lowered in {".gitkeep", ".env"} or lowered.startswith(".env."):
        return False
    return True


def git_paths(revision: str) -> list[str]:
    output = subprocess.check_output(
        ["git", "ls-tree", "-r", "--name-only", revision], text=True
    )
    return sorted(path for path in output.splitlines() if included(path))


def installed_paths(root: Path) -> list[str]:
    paths: list[str] = []
    for candidate in root.rglob("*"):
        if not (candidate.is_file() or candidate.is_symlink()):
            continue
        relative = candidate.relative_to(root).as_posix()
        if included(relative):
            paths.append(relative)
    return sorted(paths)


def main() -> None:
    parser = argparse.ArgumentParser()
    source = parser.add_mutually_exclusive_group()
    source.add_argument("revision", nargs="?", default="HEAD")
    source.add_argument("--root", type=Path, help="enumerate an installed tree")
    args = parser.parse_args()
    paths = installed_paths(args.root.resolve()) if args.root else git_paths(args.revision)
    print("\n".join(paths))


if __name__ == "__main__":
    main()
