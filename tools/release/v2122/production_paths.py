#!/usr/bin/env python3
"""Select immutable production files from a Git revision or installed tree."""
from __future__ import annotations
import argparse
from pathlib import Path, PurePosixPath
import subprocess
ROOT_FILES={'.htaccess','VERSION','site-manifest.json'}
ROOT_PAGES=set('AnalyzeMatch.html AnalyzeMatchModal.html AnalyzeMatches.htm ChallengeListAssistant.html ClubIntelligence.html DataReconciliation.html FindMatch.htm InsightsHealth.html LeagueSeasonCenter.html LiveRanks.html LiveRanksDemo.html MatchCreationAnalyzer.htm MigrationInstaller.html Opponents.html OpponentsDemo.html RecruitMatch.html RecruitmentAdmin.html RecruitmentDemandPlanner.html TaskControl.html TaskLogs.html TeamInsights.html TeamPointsAdmin.html TeamPointsMigration.html TournamentAchievementBadgesDemo.html TournamentManagement.html Tournaments.html UIv2RepresentativeDemo.html index.html ui-v2.html'.split())
IMMUTABLE_ROOTS={'api','artwork','artwork-masters','assets','auth','resources','server','trophies'}
PRESERVED_EXACT_PATHS={
'assets/trophy-gallery/legacy-r4/club-wars-galactic-conflict.png','assets/trophy-gallery/legacy-r4/owl-2024-classic-u1700.png','assets/trophy-gallery/legacy-r4/owl-2024-grand-prix-candidates.png','assets/trophy-gallery/legacy-r4/owl-2024-swiss4all.jpg','assets/trophy-gallery/legacy-r4/owl-2024-vote-g1.png','assets/trophy-gallery/legacy-r4/pcl-super-bingo-2025.png','assets/trophy-gallery/legacy-r4/tcmac-centurion-s4.png','resources/miac/seed.zip'}
MUTABLE_SEGMENTS={'backup','backups','cache','caches','data','log','logs','processed','quarantine','runtime','sessions','storage','tmp','upload','uploads'}
def included(relative_path:str)->bool:
    path=PurePosixPath(relative_path)
    if relative_path in ROOT_FILES|ROOT_PAGES:return True
    if relative_path in PRESERVED_EXACT_PATHS:return False
    if not path.parts or path.parts[0] not in IMMUTABLE_ROOTS:return False
    if any(part.lower() in MUTABLE_SEGMENTS for part in path.parts[1:]):return False
    if 'config' in (part.lower() for part in path.parts):return path.name=='.htaccess' or '.example.' in path.name
    lowered=path.name.lower()
    if '.local.' in lowered and '.example.' not in lowered:return False
    if lowered in {'.gitkeep','.env'} or lowered.startswith('.env.'):return False
    return True
def git_paths(revision:str)->list[str]:
    out=subprocess.check_output(['git','ls-tree','-r','--name-only',revision],text=True)
    return sorted(p for p in out.splitlines() if included(p))
def installed_paths(root:Path)->list[str]:
    out=[]
    for candidate in root.rglob('*'):
        if not(candidate.is_file() or candidate.is_symlink()):continue
        rel=candidate.relative_to(root).as_posix()
        if included(rel):out.append(rel)
    return sorted(out)
def main():
    p=argparse.ArgumentParser();g=p.add_mutually_exclusive_group();g.add_argument('revision',nargs='?',default='HEAD');g.add_argument('--root',type=Path);a=p.parse_args()
    print('\n'.join(installed_paths(a.root.resolve()) if a.root else git_paths(a.revision)))
if __name__=='__main__':main()
