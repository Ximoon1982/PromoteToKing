#!/usr/bin/env python3
from __future__ import annotations
import json, os, re, subprocess, tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def check(value, message):
    if not value:
        raise AssertionError(message)

def parse_live_fixture():
    script = r'''<?php
$root = getenv('P2K_ROOT');
require $root . '/server/team-points/src/bootstrap.php';
$r = new ReflectionClass(\P2K\TeamPoints\LiveRanksService::class);
$o = $r->newInstanceWithoutConstructor();
foreach (['storageDir'=>$root.'/tests/fixtures','clubSlug'=>'promote-to-king'] as $name=>$value) {
  $p=$r->getProperty($name); $p->setAccessible(true); $p->setValue($o,$value);
}
$m=$r->getMethod('parseCsvFile'); $m->setAccessible(true);
$result=$m->invoke($o,'live-ranks-arena.csv');
echo json_encode($result, JSON_THROW_ON_ERROR);
'''
    with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False) as fh:
        fh.write(script); name=fh.name
    try:
        out=subprocess.check_output(['php',name],env={**os.environ,'P2K_ROOT':str(ROOT)},text=True)
        return json.loads(out)
    finally:
        Path(name).unlink(missing_ok=True)

def main():
    check((ROOT/'VERSION').read_text().strip()=='2.8.1','VERSION must be 2.8.1')
    ui=(ROOT/'ui-v2.html').read_text()
    dashboard=(ROOT/'assets/js/pages/dashboard-v2.js').read_text()
    css=(ROOT/'assets/css/dashboard-v2.css').read_text()
    live=(ROOT/'server/team-points/src/LiveRanksService.php').read_text()
    schema=(ROOT/'server/team-points/sql/core-schema.sql').read_text()+'\n'+(ROOT/'server/team-points/sql/analytics-schema.sql').read_text()
    public=(ROOT/'server/team-points/public/public.php').read_text()
    repair=(ROOT/'server/team-points/public/database-repair.php').read_text()
    admin=(ROOT/'TeamPointsAdmin.html').read_text()
    tournaments=(ROOT/'Tournaments.html').read_text()
    tournament_service=(ROOT/'server/tournaments/src/TournamentService.php').read_text()
    tournament_admin=(ROOT/'TournamentManagement.html').read_text()

    # Complete dashboard panel and source toggle.
    for fn in ('matchLists','matchBoardCount','matchListTotals','setMatchMetric','memberCount','renderLiveTeamData','renderTeamMode'):
        check(f'function {fn}' in dashboard,f'Missing dashboard panel helper: {fn}')
    check('action=live-team' in dashboard and "if ($action === 'live-team')" in public,'Live team API integration missing')
    check('server/team-points/public/team-insights.php' in (ROOT/'TeamInsights.html').read_text() and 'Database::connection()' in (ROOT/'server/team-points/public/team-insights.php').read_text(),'Team Insights is not routed through its direct database API')
    check('function opponentInsightsURL' in dashboard and 'remoteLoader: async tableState' in dashboard,'Opponent Insights is not server-filtered/paginated')
    check('id="teamDailyContent"' in ui and 'id="teamLiveContent"' in ui,'Daily/Live team panel containers missing')
    check('dashboard-points-title-row' in ui and 'id="personalModeToggle"' in ui,'Daily/Live toggle is not aligned with the points title')
    for metric in ('personalLiveBestScore','personalLiveTotalWins','personalLiveMaxWins','personalLiveBestStreak'):
        check(f'id="{metric}"' in ui,f'Missing Live bottom metric {metric}')
    for label in ('Best rank','Top 3','Top 10'):
        check(label in dashboard,f'Missing Live side statistic {label}')
    check('rank?.icon' in dashboard and 'assets/images/ranks/thumbs/320/' in dashboard,'Dashboard Live rank is not frameless/thumbnail-backed')
    check('View Daily Ranks →' in ui and 'View Daily Ranks →' in dashboard,'Daily rank button wording is wrong')
    check('View Live Ranks →' in dashboard and 'pendingLiveFocus' in dashboard,'Live rank focus navigation is missing')
    check('function promoteMatchAssistantFrame' in dashboard and 'classList.remove("dashboard-recommendation-engine")' in dashboard and 'classList.add("dashboard-assistant-frame")' in dashboard,'Find more matches does not promote the hidden recommendation iframe into the visible assistant')
    check('.dashboard-assistant-frame[hidden] { display: none !important; }' in css,'Hidden Match Assistant frame state is not explicit')
    check('Arenas played' in ui and 'Unique players' in ui and 'Total arena points' in ui,'MCA dashboard labels are incomplete')
    check('Total participations' not in ui and 'Current participants' not in ui and 'Aggregate arena points' not in ui,'Removed Live team labels remain')
    check('CSV pack' not in ui and 'CSV pack' not in dashboard,'Public Live-rank UI still exposes CSV terminology')
    for metric in ('teamLiveArenas','teamLiveCurrentPlayers','teamLiveMostParticipants','teamLiveMostPoints','teamLiveFirstPlaces','teamLiveSecondPlaces','teamLiveThirdPlaces','teamLiveAggregatePoints'):
        check(f'id="{metric}"' in ui,f'Missing Live team metric {metric}')

    # CSV interpretation, case alignment, player and team arena aggregates.
    for field in ('best_rank','top3_count','top10_count','best_score'):
        check(field in schema and field in live,f'Missing Live rank placement field {field}')
    check("['wins', 'games won', 'total wins', 'most wins']" in live,'Most wins is not interpreted as per-arena wins')
    check('p2k_tp_username_key($username)' in live,'CSV usernames are not case-normalized')
    check('p2k_lr_arena_stats' in schema and 'publicTeamPayload' in live,'Arena-level team aggregation missing')
    parsed=parse_live_fixture()
    check(parsed['rows']==4 and parsed['p2k_rows']==3,'Fixture CSV row counts are wrong')
    check(set(parsed['players'])=={'alpha','gamma'},'Case-insensitive player merge failed')
    alpha=parsed['players']['alpha']
    check(alpha['score']==50 and alpha['wins']==14 and alpha['max_wins']==12 and alpha['rank']==2,'Alpha CSV metrics were not aggregated correctly')
    check(parsed['arena']=={'participants':2,'points':100,'first':1,'second':1,'third':0},'Arena summary is wrong')

    # Persistent issue-only audits and arithmetic repair.
    for table in ('p2k_tp_audit_jobs','p2k_tp_audit_issues','p2k_tp_consistency_repairs'):
        check(table in schema and table in repair,f'Missing persistent repair table {table}')
    for mode in ("'arithmetic'","'former'","'full'"):
        check(mode in repair,f'Missing repair mode {mode}')
    for action in ("$action==='start'","$action==='latest'","$action==='pause'","$action==='resume'","$action==='step'","$action==='repair'"):
        check(action in repair,f'Missing repair action {action}')
    for arithmetic in ('authoritative_boards','discovered_boards','event_games','invalid_boards','opponent_score','competition_points'):
        check(arithmetic in repair,f'Missing arithmetic check {arithmetic}')
    check("WHERE job_id=? AND status='pending'" in repair and "Successful checks only advance counters" in repair,'Repair page does not use issue-only results')
    check('Fix next 5' in repair and 'Fix next 10' in repair and 'Fix next 25' in repair,'Small repair batches are missing')
    check('Load latest audit' in repair and 'localStorage.setItem(JOB_KEY' in repair,'Cross-refresh audit resume is missing')
    check('database-repair.php' in admin,'Consolidated repair tool is not exposed in Administration')

    # Tournament administration, pagination and Hall deep links.
    check('public function manualAdd' in tournament_service and "mode:'manual-add'" in tournament_admin,'Manual tournament entry is missing')
    check('const PAGE=50' in tournaments and 'rows.slice(start,start+PAGE)' in tournaments,'Tournament tables are not capped at 50 rows')
    check('Load 50 more' not in tournaments and 'Previous' in tournaments and 'Next' in tournaments,'Tournament table pagination is incomplete')
    check("params.get('player')" in tournaments and 'tournamentMatchesPlayer' in tournaments,'Member tournament filtering is missing')
    check("params.get('podium')" in tournaments and 'is-highlighted' in tournaments,'Podium deep-link highlighting is missing')
    check('Open podiums' in dashboard and 'Open tournaments' in dashboard,'Hall member-search tournament actions are missing')

    # English-only formatting with browser-local timezone.
    frontend='\n'.join(p.read_text(errors='ignore') for p in list(ROOT.glob('*.html'))+list(ROOT.glob('*.htm'))+list((ROOT/'assets/js').rglob('*.js')))
    check('Intl.DateTimeFormat(undefined' not in frontend and 'Intl.NumberFormat(undefined' not in frontend and 'Intl.RelativeTimeFormat(undefined' not in frontend,'Browser-default locale is still used')
    check('timeZone: "UTC"' not in frontend and "timeZone: 'UTC'" not in frontend,'Frontend still forces UTC instead of the user timezone')
    check('new Intl.DateTimeFormat("en-GB"' in frontend,'English date formatting is not configured')

    # Softer validated-admin card accent.
    check('Softer validated-administrator accent' in css and 'rgba(190,105,91,.25)' in css,'Administrator card accent was not softened')
    check('id="dashboardAdminPriorityCard"' not in ui and 'if (!state.admin) return null' in dashboard,'Administrator card visibility guard is incomplete')
    print('v2.8.1 dashboard, Live arena, repair and tournament integration tests passed.')

if __name__=='__main__':
    main()
