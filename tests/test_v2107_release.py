#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
cat=(ROOT/'server/team-points/src/AchievementCatalog.php').read_text(encoding='utf-8')
ana=(ROOT/'server/team-points/src/AnalyticsBuilder.php').read_text(encoding='utf-8')
club=(ROOT/'server/team-points/src/ClubIntelligenceService.php').read_text(encoding='utf-8')
keys=['team-points-day-2','team-points-day-5','team-points-week-10','team-points-week-20','team-points-month-25','team-points-month-50','team-points-year-100','team-points-year-250']
checks={
 'VERSION 2.10.7':(ROOT/'VERSION').read_text().strip()=='2.10.7',
 'MIGRATION_VERSION 2.10.7':(ROOT/'MIGRATION_VERSION').read_text().strip()=='2.10.7',
 'exact eight new catalog keys':all(cat.count("'"+k+"'")==1 for k in keys),
 'First Step artwork direct':"self::item('first-point','First Step','Earn your first Team Point.','team-points','assets/images/achievements/first-point.png','assets/images/achievements/thumbs/128/first-point.webp')" in cat,
 'historical time windows':all(k in ana for k in keys) and "gmdate('o-\\WW'" in ana and "gmdate('Y-m-d'" in ana,
 'MIAC canonical awards':'COALESCE(im.canonical_username_key,u.username_key)' in ana,
 'canonical opponent awards':'LEFT JOIN p2k_tp_opponent_aliases oa' in ana and 'COALESCE(oa.canonical_slug,m.opponent_slug) opponent_slug' in ana,
 'canonical member progress':'canonicalMemberIds' in club and 'b.member_id IN ($memberScope)' in club,
 'board based league participation':'FROM p2k_tp_boards b JOIN p2k_tp_match_metadata' in club and 'LEFT JOIN p2k_tp_games g' in club,
 'five league universe':"foreach(['1WL','PCL','TCMAC','TMCL','KOTML'] as $code)" in club,
 'league regex excludes WKCL/CW':"/^(1wl|pcl|tcmac|tmcl|kotml)-(competitor|veteran|legend)$/" in club and "/^(1wl|pcl|tcmac|tmcl|kotml)-(first-point|scorer|specialist|master)$/" in club,
 'canonical rivalry progress':'GROUP BY COALESCE(oa.canonical_slug,mm.opponent_slug)' in club,
 'earned tier floor':'progressFloorIdentity' in club and '$cur=max($cur,$floor);' in club,
 'period peak progress':'team_points_day_peak' in club and 'team_points_week_peak' in club and 'team_points_month_peak' in club and 'team_points_year_peak' in club,
}
failed=[k for k,v in checks.items() if not v]
for k,v in checks.items(): print(('PASS' if v else 'FAIL')+' - '+k)
if failed: raise SystemExit('FAILED: '+'; '.join(failed))
print(f'PASS - {len(checks)} v2.10.7 source gates')
