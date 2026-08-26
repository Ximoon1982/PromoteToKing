from pathlib import Path
import json, re

ROOT=Path(__file__).resolve().parents[1]

NEW_KEYS=[
 'match-start-streak-3','match-start-streak-5','match-start-streak-7','match-start-streak-10','match-start-streak-14',
 'match-winner-1','match-winner-5','match-winner-10','match-winner-15','match-winner-20',
 'match-saver-1','match-saver-5','match-saver-10','match-saver-15','match-saver-20',
 'photo-finish-5','close-call-veteran-20','close-call-master-50','close-call-legend-100',
 'winning-side-10','winning-side-50','winning-side-100','winning-side-250',
 'opponent-variety-25','opponent-variety-50','opponent-variety-100',
 'old-foes-5','old-foes-10','old-foes-25'
]

def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')

def test_release_identity_and_blue_baseline():
    assert (ROOT/'VERSION').read_text().strip()=='2.10.3'
    assert (ROOT/'MIGRATION_VERSION').read_text().strip()=='2.10.3'
    assert (ROOT/'BLUE_BASELINE_VERSION').read_text().strip()=='2.9.22.10'
    m=json.loads(text('site-manifest.json'))
    assert m['version']=='2.10.3' and m['migrationVersion']=='2.10.3'
    assert m['blueBaselineVersion']=='2.9.22.10' and m['publicDataSource']=='blue_hardwired'

def test_real_oauth_is_default_and_name_is_display_only():
    auth=text('assets/js/shared/real-oauth.js')
    assert 'params.get("oauth") === "1"' in auth
    assert 'getDisplayUsername' in auth and 'getDisplaySession' in auth
    assert 'authenticatedUsername' in auth and 'displayOnly' in auth
    assert 'p2k-auth-viewing-as' in auth
    # getSession remains independently exported: display mode did not replace the auth session.
    assert 'getSession: () => session' in auth
    tabs=text('assets/js/pages/site-tabs.js')
    assert 'parameters.get("oauth") === "1"' in tabs
    assert 'displayName' in tabs or 'displayUsername' in tabs

def test_migration_uses_default_real_oauth_and_keeps_terminal_hotfix():
    html=text('TeamPointsMigration.html')
    js=text('assets/js/pages/team-points-migration.js')
    assert 'assets/js/shared/real-oauth.js?v=2.10.3' in html
    assert "get('oauth')!=='1'" in js
    assert '__p2k_green_terminal_http' in js
    assert 'remove ?oauth=1' in js
    assert 'HOTFIX_VERSION="2.10.3"' in js

def test_green_profile_and_terminal_http_hotfixes_folded():
    repo=text('server/team-points-green/src/GreenRepository.php')
    worker=text('server/team-points-green/src/GreenWorker.php')
    observe=text('server/team-points-green/public/observe.php')
    assert 'function markProfileHttp' in repo and 'function markStatsHttp' in repo
    assert 'profiles_terminal_unavailable' in worker and 'stats_terminal_unavailable' in worker
    assert '__p2k_green_terminal_http' in observe

def test_challenge_surfaces_are_fail_soft():
    svc=text('server/team-points/src/ClubIntelligenceService.php')
    dash=text('assets/js/pages/dashboard-v2.js')
    assert 'optionalOne' in svc and 'optionalAll' in svc
    assert 'Challenges temporarily unavailable' in dash
    assert 'achievement_progress' in dash
    assert 'temporarily unavailable' in dash.lower()

def test_new_achievement_catalogue_and_logic():
    cat=text('server/team-points/src/AchievementCatalog.php')
    keys=re.findall(r"self::item\(\s*'([^']+)'",cat)
    assert len(keys)==191 and len(set(keys))==191
    for key in NEW_KEYS: assert key in keys
    builder=text('server/team-points/src/AnalyticsBuilder.php')
    assert 'logic:achievement-v2103' in builder
    for token in ['match_winners','match_savers','start_day_streak','close_calls','winning_side','opponents','rematches']:
        assert token in builder

def test_new_achievement_progress_metrics_exist():
    svc=text('server/team-points/src/ClubIntelligenceService.php')
    for token in ['consecutive_match_start_days_peak','opponent_variety','rematch_count','close_call_matches','winning_side_matches','match_winner_count','match_saver_count']:
        assert token in svc

def test_weekly_storage_capacity_monitoring():
    svc=text('server/team-points/src/StorageMetricsService.php')
    ui=text('assets/js/pages/team-points-admin.js')
    assert 'weekly_history' in svc and 'growth_bytes_per_week' in svc
    assert '%x-W%v' in svc
    assert 'weekly_history' in ui and '/ week' in ui

def test_all_production_api_pages_load_real_oauth():
    api_token='assets/js/shared/api-client.js'
    missing=[]
    for p in list(ROOT.glob('*.html'))+list(ROOT.glob('*.htm')):
        s=p.read_text(encoding='utf-8',errors='ignore')
        if api_token in s and 'assets/js/shared/real-oauth.js' not in s:
            missing.append(p.name)
    assert not missing
