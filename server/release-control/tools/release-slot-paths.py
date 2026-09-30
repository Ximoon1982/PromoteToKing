#!/usr/bin/env python3
from __future__ import annotations

import argparse
import os
from pathlib import Path, PurePosixPath
import subprocess

ROOT_FILES = {".htaccess", "VERSION", "site-manifest.json"}
ROOT_PAGES = set(
    "AnalyzeMatch.html AnalyzeMatchModal.html AnalyzeMatches.htm "
    "ChallengeListAssistant.html ClubIntelligence.html DataReconciliation.html "
    "FindMatch.htm InsightsHealth.html LeagueSeasonCenter.html LiveRanks.html "
    "LiveRanksDemo.html MatchCreationAnalyzer.htm MaxRatingBackfill.php MigrationInstaller.html "
    "Opponents.html OpponentsDemo.html RecruitMatch.html RecruitmentAdmin.html "
    "RecruitmentDemandPlanner.html TaskControl.html TaskLogs.html TeamInsights.html "
    "TeamPointsAdmin.html TeamPointsMigration.html TournamentAchievementBadgesDemo.html "
    "TournamentManagement.html Tournaments.html UIv2RepresentativeDemo.html index.html ui-v2.html".split()
)
IMMUTABLE_ROOTS = {
    "api", "artwork", "artwork-masters", "assets", "auth", "config", "resources",
    "server", "trophies",
}
PRESERVED_EXACT_PATHS = {
    "assets/trophy-gallery/legacy-r4/club-wars-galactic-conflict.png",
    "assets/trophy-gallery/legacy-r4/owl-2024-classic-u1700.png",
    "assets/trophy-gallery/legacy-r4/owl-2024-grand-prix-candidates.png",
    "assets/trophy-gallery/legacy-r4/owl-2024-swiss4all.jpg",
    "assets/trophy-gallery/legacy-r4/owl-2024-vote-g1.png",
    "assets/trophy-gallery/legacy-r4/pcl-super-bingo-2025.png",
    "assets/trophy-gallery/legacy-r4/tcmac-centurion-s4.png",
    "resources/miac/seed.zip",
}
MUTABLE_SEGMENTS = {
    "backup", "backups", "cache", "caches", "data", "log", "logs", "processed",
    "quarantine", "runtime", "sessions", "storage", "tmp", "upload", "uploads",
}
RECOVERY_EXACT = {"ReleaseControl.php"}
RECOVERY_PREFIXES = ("server/release-control/",)

def included(relative_path: str) -> bool:
    relative_path = relative_path.replace("\\", "/").lstrip("./")
    if not relative_path or relative_path in RECOVERY_EXACT:
        return False
    if any(relative_path.startswith(prefix) for prefix in RECOVERY_PREFIXES):
        return False
    path = PurePosixPath(relative_path)
    if relative_path in ROOT_FILES | ROOT_PAGES:
        return True
    if relative_path in PRESERVED_EXACT_PATHS:
        return False
    if not path.parts or path.parts[0] not in IMMUTABLE_ROOTS:
        return False
    if any(part.lower() in MUTABLE_SEGMENTS for part in path.parts[1:]):
        return False
    lowered = path.name.lower()
    if ".local." in lowered and ".example." not in lowered:
        return False
    if lowered in {".gitkeep", ".env"} or lowered.startswith(".env."):
        return False
    return True

def git_paths(revision: str) -> list[str]:
    output = subprocess.check_output(["git", "ls-tree", "-r", "--name-only", revision], text=True)
    return sorted(path for path in output.splitlines() if included(path))

def installed_paths(root: Path) -> list[str]:
    paths: list[str] = []
    for relative in sorted(ROOT_FILES | ROOT_PAGES):
        candidate = root / relative
        if (candidate.is_file() or candidate.is_symlink()) and included(relative):
            paths.append(relative)

    for immutable_root in sorted(IMMUTABLE_ROOTS):
        base = root / immutable_root
        if not base.is_dir():
            continue
        for current, dirs, files in os.walk(base, topdown=True, followlinks=False):
            current_path = Path(current)
            rel_dir = current_path.relative_to(root).as_posix()
            pruned: list[str] = []
            for name in dirs:
                rel = f"{rel_dir}/{name}"
                parts = PurePosixPath(rel).parts
                if any(part.lower() in MUTABLE_SEGMENTS for part in parts[1:]):
                    continue
                if rel == "server/release-control" or rel.startswith("server/release-control/"):
                    continue
                pruned.append(name)
            dirs[:] = pruned
            for name in files:
                candidate = current_path / name
                relative = candidate.relative_to(root).as_posix()
                if included(relative):
                    paths.append(relative)
    return sorted(set(paths))

def main() -> None:
    parser = argparse.ArgumentParser()
    source = parser.add_mutually_exclusive_group()
    source.add_argument("revision", nargs="?", default="HEAD")
    source.add_argument("--root", type=Path, help="enumerate an installed application tree")
    args = parser.parse_args()
    paths = installed_paths(args.root.resolve()) if args.root else git_paths(args.revision)
    print("\n".join(paths))

if __name__ == "__main__":
    main()
