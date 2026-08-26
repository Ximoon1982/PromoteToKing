from pathlib import Path
import json

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')


def test_v291_release_identity_and_runtime_cache_markers():
    version=text('VERSION').strip()
    assert version in {'2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    cfg=text('assets/js/site-config.js')
    assert f'version: "{version}"' in cfg and 'builtAt:' in cfg
    for page in ['ui-v2.html','ClubIntelligence.html']:
        assert f'?v={version}' in text(page)
    manifest=json.loads(text('site-manifest.json'))
    assert manifest['version']==version and manifest['releaseVersion']==version


def test_v291_ionos_cron_contract():
    dispatch=text('cron-dispatch-v2.9.1.sh'); install=text('reset-install-cron-v2.9.1.sh')
    assert '--max-time 55' in dispatch and 'PromoteToKing-Cron/2.9.1' in dispatch
    assert '/usr/bin/php8.5-cli' in dispatch and 'PHP_SAPI === "cli"' in dispatch
    assert 'cron-dispatch-v2.9.1.sh' in install and '# BEGIN PROMOTE TO KING v2.9.1' in install
    assert 'Shared CRON token: repaired from protected Team Points configuration.' in install


def test_v291_data_reconciliation_is_admin_confirmed_bounded_and_authoritative_on_conflicts():
    page=text('DataReconciliation.html')
    endpoint=text('server/team-points/public/data-reconciliation.php')
    svc=text('server/team-points/src/DataReconciliationService.php')
    assert 'Data Reconciliation' in page
    assert "Auth::requireAdmin()" in endpoint and "apply-step" in endpoint
    assert "hash_equals('APPLY ' . $id" in svc
    assert "'conflicting_scores_results_points'=>'authoritative_sync_only'" in svc
    assert "'member_absence'=>'never_demote_from_csv'" in svc
    assert "max(1,min(1000,$limit))" in svc
    assert "'reconciliation_batch'=>$id" in svc and "'sync_match'" in svc and "'sync_board'" in svc


def test_v291_daily_checkpoint_is_chronological_prefix_not_end_of_day():
    svc=text('server/team-points/src/DataReconciliationService.php')
    assert 'chronological non-void finish prefix' in svc
    assert '$targetCount=(int)($last[\'cum_finished\']??0)' in svc
    assert '$targetPoints=(int)($last[\'cum_points\']??0)' in svc
    assert "'daily_checkpoint'=>'dated_prefix_checksum_not_terminal_total'" in svc


def test_v291_balance_analyzer_and_two_dimensional_zoom_are_present():
    js=text('assets/js/pages/opponent-balance-analyzer.js')
    page=text('ClubIntelligence.html')+'\n'+text('ui-v2.html')
    php=text('server/team-points/src/ClubIntelligenceService.php')
    assert ('Balance Analyzer' in page or 'opponentsBalanceAnalyzer' in page) and 'Opponent Balance Analyzer' in js
    for token in ['Friendly','League','Chess960','Classical']:
        assert token in js
    assert 'balance' in php.lower()
    # Interactive heatmap implementation includes wheel/pointer and box/pinch capable zoom machinery.
    assert 'wheel' in js.lower() and ('pointer' in js.lower() or 'touch' in js.lower())


def test_v291_team_depth_members_hall_and_opponent_usability():
    css=text('assets/css/dashboard-v2.css'); ci=text('assets/js/pages/club-intelligence.js'); service=text('server/team-points/src/ClubIntelligenceService.php'); ui=text('ui-v2.html')
    assert '#hallOfFamePage{grid-template-columns:minmax(0,1fr)!important' in css
    assert "if($rating===null || $rating<=0){$stats['unrated']++;continue;}" in service
    for status in ['Active','Available','Overloaded']:
        assert status in ci
    assert 'id="membersActivityStatusFilter"' in ui and 'id="membersTablePager"' in ui


def test_v291_achievement_search_breadth_artwork_and_earned_progress_contract():
    cat=text('server/team-points/src/AchievementCatalog.php'); dash=text('assets/js/pages/dashboard-v2.js'); standalone=text('TournamentAchievementBadgesDemo.html')
    assert 'Search achievement names' in dash and 'Search achievement names' in standalone
    for threshold in [1,5,10,15,20]:
        assert str(threshold) in cat
    assert "self::item('daily-king','King Daily Rank'" in cat and '06_King_250_points.png' in cat
    assert '08_Silver_King_1000_points.png' in cat and '01_Live_Pawn_50_points.png' in cat
    assert 'earned' in dash.lower() and 'progress' in dash.lower()


def test_v291_traffic_diagnostics_keep_dnt_gpc_suppression_and_self_test():
    traffic=text('assets/js/shared/traffic-analytics.js'); diag=text('assets/js/pages/runtime-diagnostics.js'); ci=text('assets/js/pages/club-intelligence.js')
    assert 'doNotTrack' in traffic or 'navigator.doNotTrack' in traffic
    assert 'globalPrivacyControl' in traffic
    server=text('server/shared/TrafficAnalytics.php')
    assert 'trafficSelfTest' in diag and 'Collector self-test' in ci
    assert 'function selfTest' in server and 'isolated' in server


def test_v291_acamr_telemetry_correction_is_integrated():
    client=text('assets/js/shared/authenticated-member-refresh.js'); runtime=text('server/team-points/src/RuntimeTelemetry.php'); ci=text('assets/js/pages/club-intelligence.js')
    assert 'persistedId("localStorage", CLIENT_KEY' in client
    assert 'persistedId("sessionStorage", SESSION_KEY' in client
    for token in ['distinct_clients','distinct_browsing_sessions','distinct_actors','distinct_members_claimed','work_classes']:
        assert token in runtime
    assert 'canonical facts still require authoritative server verification' in ci


def test_v291_release_docs_and_updater_exist():
    for rel in ['RELEASE_NOTES_v2.9.1.md','INSTALL_v2.9.1.md','CRON_SETUP_v2.9.1.md','PRIVACY_v2.9.1.md','update-v2.9.0-to-v2.9.1.sh']:
        assert (ROOT/rel).is_file()
