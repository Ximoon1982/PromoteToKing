from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')


def test_release_identity():
    assert text('VERSION').strip()=='2.10.6.9'
    assert text('MIGRATION_VERSION').strip()=='2.10.6.9'
    assert 'version: "2.10.6.9"' in text('assets/js/site-config.js')
    assert "public const VERSION = '2.10.6.9';" in text('server/team-points-green/src/GreenConfig.php')


def test_green_worker_projects_live_without_gab_ready_gate():
    worker=text('server/team-points-green/src/GreenWorker.php')
    assert "gab_status']??'')==='ready'" not in worker
    assert 'projectMatch($id,false)' in worker
    assert "in_array($status,[404,410],true)" in worker
    assert 'projectMembers();' in worker
    assert 'projectMember($username);' in worker


def test_browser_observations_return_identity_and_project_live():
    repo=text('server/team-points-green/src/GreenRepository.php')
    observe=text('server/team-points-green/public/observe.php')
    assert "$result['match_id']=$matchId" in repo
    assert "$result['board_no']=$boardNo" in repo
    assert "$result['username']=$username" in repo
    assert "gab_status']??'')==='ready'" not in observe
    assert 'projectMatch($mid,false)' in observe
    assert 'projectMembers()' in observe
    assert 'projectMember((string)$result[\'username\'])' in observe


def test_compatibility_analytics_refreshes_during_gab():
    compat=text('server/team-points-green/src/GreenCompatibility.php')
    block=compat[compat.index('public function maybeRebuildAnalytics'):compat.index('/**\n     * Public-read contract audit')]
    assert "gab_status" not in block
    assert 'refreshIfNeeded($this->green->clubSlug)' in block
    assert 'refreshAchievementsIfNeeded($this->green->clubSlug)' in block
    assert "'reason'=>$ran?'source_changed':'watermarks_current'" in block
    assert 'GAB remains a historical' in compat


def test_dashboard_and_signed_in_player_use_native_green_after_cutover():
    repo=text('server/team-points/src/Repository.php')
    assert "if(PublicReadDatabase::source()!=='green')return null;" in repo
    assert 'greenNativeClubDashboard' in repo
    assert "FROM p2k_g_matches" in repo
    assert "FROM p2k_g_players WHERE current_member=1" in repo
    assert "'finished_games'=>$finishedBoards*2" in repo
    assert "'cache_mode'=>'green_native_core_live'" in repo
    assert 'greenNativePlayerSummary' in repo
    assert 'p2k_g_point_events' in repo
    assert "'data_source'=>'green_native_core'" in repo


def test_task_control_exposes_green_live_provenance():
    js=text('assets/js/pages/task-control.js')
    assert 'greenMetric("Public data mode",effective==="green"?"GREEN NATIVE + LIVE":"BLUE"' in js
    assert 'greenMetric("Green public analytics"' in js
    assert 'refresh continues while GAB converges' in js


def test_dashboard_health_follows_green_runtime_after_cutover():
    dash=text('assets/js/pages/dashboard-v2.js')
    api=text('server/team-points-green/public/api.php')
    assert "action==='runtime-health'" in api
    assert 'effectiveTeamPointsSource === "green"' in dash
    assert '["team-points-club", "team-points-player", "team-points"]' in dash
    assert 'name: "Green Team Points"' in dash
    assert 'public analytics ${analyticsLast}' in dash
