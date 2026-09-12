#!/usr/bin/env python3
"""Deterministic immutable production payload selection."""
import subprocess,sys
from pathlib import PurePosixPath
ROOT={".htaccess","VERSION"}
PAGES=set("AnalyzeMatch.html AnalyzeMatchModal.html AnalyzeMatches.htm ChallengeListAssistant.html ClubIntelligence.html DataReconciliation.html FindMatch.htm InsightsHealth.html LeagueSeasonCenter.html LiveRanks.html LiveRanksDemo.html MatchCreationAnalyzer.htm MigrationInstaller.html Opponents.html OpponentsDemo.html RecruitMatch.html RecruitmentAdmin.html RecruitmentDemandPlanner.html TaskControl.html TaskLogs.html TeamInsights.html TeamPointsAdmin.html TeamPointsMigration.html TournamentAchievementBadgesDemo.html TournamentManagement.html Tournaments.html UIv2RepresentativeDemo.html index.html ui-v2.html".split())
ROOTS={"api","artwork","artwork-masters","assets","auth","resources","server","trophies"}
MUTABLE={"cache","caches","data","logs","storage","uploads","backups","processed","quarantine","runtime"}
def included(s):
 p=PurePosixPath(s)
 if s in ROOT|PAGES:return True
 if not p.parts or p.parts[0] not in ROOTS or any(x.lower() in MUTABLE for x in p.parts[1:]):return False
 if "config" in (x.lower() for x in p.parts):return p.name==".htaccess" or ".example." in p.name
 return p.name!=".gitkeep"
files=subprocess.check_output(["git","ls-tree","-r","--name-only",sys.argv[1] if len(sys.argv)>1 else "HEAD"],text=True).splitlines()
print("\n".join(x for x in files if included(x)))
