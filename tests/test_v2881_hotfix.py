from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(path): return (ROOT/path).read_text(encoding='utf-8')

def test_version_and_no_schema_bump():
    assert text('VERSION').strip() in {'2.8.8.1','2.8.8.2','2.8.8.3','2.8.9','2.8.10','2.8.11','2.9.0','2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    repo=text('server/team-points/src/Repository.php')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [6,7,8,9,10,11,12,13,14,15])

def test_admin_fallback_is_before_chess_profile_lookup():
    dash=text('assets/js/pages/dashboard-v2.js')
    fn=dash[dash.index('async function verifyAdmin'):dash.index('function adminPanelMarkup')]
    assert fn.index('configuredAdminUsernames().has(username)') < fn.index('loadJSON(clubProfileAPI')
    assert 'if (fallbackAllowed)' in fn and 'setAdmin(true, run)' in fn
    guard=text('assets/js/shared/admin-page-guard.js')
    fn=guard[guard.index('async function oauthAdminAuthorized'):guard.index('function oauthSessionClaimsAdmin')]
    assert fn.index('configuredAdminUsernames().has(username)') < fn.index('fetch(endpoint')
    assert 'window.P2K_ADMIN_USERNAME = username; return true' in fn

def test_embedded_admin_gate_and_frames_are_race_resilient():
    guard=text('assets/js/shared/admin-page-guard.js')
    assert 'setInterval' in guard and '1200' in guard and 'P2K_ADMIN_MODE === true' in guard
    dash=text('assets/js/pages/dashboard-v2.js')
    assert 'hotfixRetry' in dash and 'armLoadTimeout' in dash
    assert 'loadFeatureScriptWithRetry' in dash
    assert 'Dashboard Hall module' in dash and 'Dashboard Insights module' in dash

def test_dashboard_first_request_uses_v286_sequence_after_rollback():
    dash=text('assets/js/pages/dashboard-v2.js')
    block=dash[dash.index('async function loadTeamData'):dash.index('function renderGauge')]
    assert 'await (window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("team")' in block
    assert 'databaseRequest.then(databaseResult =>' not in block
    assert 'Promise.race([databaseRequest' not in block
    assert 'afterFirstPaint' in block

def test_profile_rank_art_and_nested_modal_async_targets_are_stable():
    css=text('assets/css/dashboard-v2.css')
    assert 'v2.8.8.1 hotfix: rank art' in css
    assert 'height:auto!important' in css and 'overflow:visible' in css
    dash=text('assets/js/pages/dashboard-v2.js')
    assert 'data-profile-root' in dash and 'profileRoot?.querySelector' in dash
    assert 'const insightsModalStack = []' in dash and 'body.replaceChildren(...previous.nodes)' in dash

def test_opponent_outcomes_use_canonical_match_summary_and_force_refresh():
    builder=text('server/team-points/src/AnalyticsBuilder.php')
    block=builder[builder.index('private function rebuildOpponents'):builder.index('public function refreshAchievementsIfNeeded')]
    assert 'LEFT JOIN p2k_tp_match_summaries s' in block
    assert "s.result='loss'" in block and "s.result='win'" in block and "s.result='draw'" in block
    assert "m.result='loss'" not in block
    assert 'logic:opponent-outcomes-v2881' in builder or 'logic:canonical-score-outcomes-v2810' in builder

def test_team_insights_has_all_history_six_month_score_projection_above_yoy():
    page=text('TeamInsights.html')
    assert page.index('Club Points score progression') < page.index('Year-over-year Club Points progression')
    assert 'id="scoreProgression"' in page and 'id="scoreProgressionLegend"' in page
    assert "g.scoreProgression" in page and "futureLabel:'6-month projection'" in page
    repo=text('server/team-points/src/Repository.php')
    assert 'clubPointsSixMonthForecast' in repo
    assert "modify('+6 months')" in repo
    assert "'scoreProgression'=>$scoreProgression" in repo
    assert 'clubPointsForecastTo' in repo
