from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_identity():
    assert text('VERSION').strip()=='2.10.6.11'
    assert text('MIGRATION_VERSION').strip()=='2.10.6.11'
    assert 'version: "2.10.6.11"' in text('assets/js/site-config.js')

def test_dashboard_team_and_match_lists_are_green_database_native():
    repo=text('server/team-points/src/Repository.php')
    api=text('server/team-points/public/public.php')
    dash=text('assets/js/pages/dashboard-v2.js')
    assert 'public function publicDashboardMatches' in repo
    assert "FROM p2k_g_matches WHERE club_verified=1 AND time_class='daily'" in repo
    assert "if ($action === 'dashboard-matches')" in api
    team=dash[dash.index('async function loadTeamData()'):dash.index('function renderGauge')]
    assert 'clubMatchesAPI' not in team
    assert 'publicRequest?.("dashboard-matches"' in team
    assert 'GREEN · live database' in team
    assert 'registered_matches' in team and 'in_progress_matches' in team
    assert 'hydrateDashboardMatchBoards' not in team

def test_team_points_database_reads_bypass_browser_cache():
    dash=text('assets/js/pages/dashboard-v2.js')
    client=text('assets/js/shared/team-points-client.js')
    assert 'isTeamPointsDatabase' in dash
    assert 'if (!isTeamPointsDatabase && !force && cached' in dash
    assert '(force || isTeamPointsDatabase) ? "no-store"' in dash
    public_block=client[client.index('async function publicRequest'):client.index('window.addEventListener')]
    assert 'cache: "no-store"' in public_block
    assert '"Cache-Control": "no-cache"' in public_block

def test_migration_green_summary_and_top_are_live_core():
    green=text('server/team-points-green/src/GreenRepository.php')
    assert 'public function liveTotals()' in green
    summary=green[green.index('public function greenSummary'):green.index('public function greenTop')]
    assert '$r=$this->liveTotals();' in summary
    assert "'analytics_totals'=>$analyticsTotals" in summary
    top=green[green.index('public function greenTop'):]
    assert 'FROM p2k_g_point_events e JOIN p2k_g_matches m' in top
    worker=text('server/team-points-green/src/GreenWorker.php')
    assert 'maybeRebuildAnalytics($this->cycle,30)' in worker
    assert 'maybeRebuildAnalytics(30)' in worker

def test_registration_chart_consumer_uses_current_release_generation():
    page=text('MatchCreationAnalyzer.htm')
    ui=text('ui-v2.html')
    charts=text('assets/js/pages/match-creation-charts.js')
    assert re.search(r'assets/js/pages/match-creation-charts\.js\?v=[^"\'&<>\s]+', page)
    assert re.search(r'assets/js/pages/match-creation-analyzer\.js\?v=[^"\'&<>\s]+', page)
    assert 'MatchCreationAnalyzer.htm?embedded=1&release=2.10.6.11' in ui
    assert 'const records = Array.isArray(buckets.registration) ? buckets.registration : [];' in charts
    assert 'parseDisplayedDate' not in charts

def test_match_assistant_full_ready_state_and_cache_generation():
    dash=text('assets/js/pages/dashboard-v2.js')
    finder=text('FindMatch.htm')
    ready=dash[dash.index('if (event.data?.type === "p2k-dashboard-assistant-ready")'):dash.index('if (!state.recommendationReady')]
    assert 'state.assistantReady = true;' in ready
    assert 'state.assistantFullReady = false;' in ready
    assert 'p2k-dashboard-show-full-assistant' in ready
    full=dash[dash.index('if (event.data?.type === "p2k-dashboard-full-assistant-ready")'):dash.index('if (event.data?.type === "p2k-dashboard-assistant-ready")')]
    assert 'state.assistantFullReady = true;' in full
    assert re.search(r'assets/js/pages/find-match\.js\?v=[^"\'&<>\s]+', finder)
    assert re.search(r'assets/js/shared/analysis-coordinator\.js\?v=[^"\'&<>\s]+', finder)
    assert 'target.searchParams.set("release", String(config.version' in dash
