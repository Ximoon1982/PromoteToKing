#!/usr/bin/env python3
from pathlib import Path
import re
ROOT=Path(__file__).resolve().parents[1]

def check(value,message):
    if not value: raise AssertionError(message)

def main():
    check((ROOT/'VERSION').read_text().strip()=='2.8.1','VERSION must be 2.8.1')
    repo=(ROOT/'server/team-points/src/Repository.php').read_text()
    dashboard=(ROOT/'assets/js/pages/dashboard-v2.js').read_text()
    ui=(ROOT/'ui-v2.html').read_text()
    finder=(ROOT/'assets/js/pages/find-match.js').read_text()
    achievements=(ROOT/'TournamentAchievementBadgesDemo.html').read_text()
    tools='\n'.join((ROOT/name).read_text() for name in ('RecruitmentDemandPlanner.html','LeagueSeasonCenter.html','InsightsHealth.html'))

    # Reliability and direct DB routing.
    for endpoint,method in [
        ('matches-insights.php','publicMatchInsights'),('opponents.php','publicOpponentStats'),
        ('members-insights.php','publicMemberInsights'),('player-profile.php','publicPlayerProfile'),
        ('match-detail.php','publicMatchDetail'),('opponent-profile.php','publicOpponentProfile'),
        ('league-seasons.php','publicLeagueSeasons'),('insights-health.php','publicInsightsHealth')]:
        text=(ROOT/'server/team-points/public'/endpoint).read_text()
        check('Database::connection()' in text and method in text,f'{endpoint} is not a direct database endpoint')
    check('server/team-points/public/matches-insights.php' in dashboard,'Match Insights does not use its direct DB endpoint')
    check('server/team-points/public/opponents.php' in dashboard,'Opponent Insights does not use its direct DB endpoint')
    check("'source'=>'database'" in repo and 'publicInsightsMeta' in repo,'Insights database provenance is missing')
    check("'competition_points'=>(int)" in repo and "'club_points'=>(int)" in repo,'Club Points are not integer API values')
    check("club_points_per_finished_match'] = (int)round" in repo,'Derived Club Points average is not integer')

    # Members, profiles and cross-navigation.
    check('data-insights-subtab="members"' in ui and 'data-insights-panel="members"' in ui,'Members Insights is not enabled')
    for name in ('openUnifiedPlayerProfile','openMatchDetail','openOpponentProfile','loadMemberInsights'):
        check(f'function {name}' in dashboard,f'Missing {name}')
    check('onMemberClick: member => openUnifiedPlayerProfile' in dashboard,'Hall rank tables do not open player profiles')
    check('membersActivityAnalytics' in ui and 'membersRankAnalytics' in ui and 'monthly_activity' in repo and 'rank_distribution' in repo,'Members analytics are incomplete')

    # Team/Match/Opponent analytics.
    for token in ('comparison','rolling30','monthlyUniquePlayers','concentration'):
        check(token in repo,f'Team Insights analytics missing {token}')
    for token in ('results_by_size','duration_trend','categories','biggest_wins','biggest_losses'):
        check(token in repo,f'Match Insights analytics missing {token}')
    for token in ('publicOpponentProfile','Balanced rivalry','Frequent opponent','Good rematch candidate','trend'):
        check(token in repo,f'Opponent intelligence missing {token}')

    # Admin modules and probability.
    for title in ('Recruitment demand planner','League and season centre','Insights API health'):
        check(title in dashboard and title in tools,f'Admin module missing: {title}')
    check('function dashboardMatchWinProbability' in finder and 'winProbability' in finder,'Admin match win probability calculation missing')
    check('P2K win probability' in dashboard,'Admin card does not display win probability')
    check('Detailed match analysis' in dashboard and 'Results over time' in dashboard,'Match/opponent drill-down actions are incomplete')
    for page in ('RecruitmentDemandPlanner.html','LeagueSeasonCenter.html','InsightsHealth.html'):
        text=(ROOT/page).read_text()
        check('admin-page-guard.js' in text and 'admin-access-pending' in text,f'{page} is not administrator guarded')

    # Real achievements, no fictive source.
    check('data-hall-subtab="achievements"' in ui and 'achievementsFrame' in ui,'Achievements Hall tab missing')
    check('members-insights.php' in achievements and 'player-profile.php' in achievements and 'tournaments.php' in achievements,'Achievement explorer is not live-data backed')
    check('fictive player' not in achievements.lower() and 'proposal demo' not in achievements.lower(),'Fictive achievement demo remains')

    # League detection and current terminology.
    for league in ('1WL','TCMAC','KOTML','TMCL','WKCL','PCL','CW'):
        check(league in repo and league in (ROOT/'LeagueSeasonCenter.html').read_text(),f'League support missing {league}')
    check('CSV source files for MCAs' in dashboard,'Administrative MCA source terminology is wrong')
    print('v2.8.1 connected Insights, profiles, achievements and administrator intelligence tests passed.')

if __name__=='__main__': main()
