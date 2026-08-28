from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8')

def php(code):
    p = subprocess.run(['php', '-r', code], cwd=ROOT, text=True, capture_output=True)
    assert p.returncode == 0, (p.stdout, p.stderr)
    return p.stdout.strip()


def test_release_identity_and_cache_markers():
    assert text('VERSION').strip() == '2.10.6.25'
    assert text('MIGRATION_VERSION').strip() == '2.10.6.25'
    site = text('assets/js/site-config.js')
    assert 'version: "2.10.6.25"' in site
    ui = text('ui-v2.html')
    assert 'site-config.js?v=2.10.6.25' in ui
    admin = text('TeamPointsAdmin.html')
    assert 'team-points-admin.js?v=2.10.6.25' in admin
    assert 'team-points-features.js?v=2.10.6.25' in admin
    challenge = text('ChallengeListAssistant.html')
    assert 'challenge-list-assistant.js?v=2.10.6.25' in challenge


def test_mca_legacy_index_parser_identity_next_page_and_date():
    code = r'''require "server/team-points/src/McaIndexParser.php";
    use P2K\TeamPoints\McaIndexParser;
    $x=McaIndexParser::identityFromHref('/tournament/live/arena/p2k-night-arena-32165498');
    if(($x['arena_id']??0)!==32165498)exit(11);
    if(($x['csv_url']??'')!=='https://www.chess.com/tournament/live/arena/p2k-night-arena-32165498.csv')exit(12);
    $d=McaIndexParser::extractDateFromText('Rating: Open 127 players Aug 18, 2026, 7:30 PM');
    if(($d['event_date']??'')!=='2026-08-18')exit(13);
    $html='<a href="/tournament/live/arena/new-one-32165499">New</a><a href="?type=multi&page=2" rel="next">Next</a>';
    $p=McaIndexParser::parse($html,1);
    if(count($p['events'])!==1||empty($p['has_next']))exit(14);
    echo 'ok';'''
    assert php(code) == 'ok'


def test_mca_discovery_is_incremental_resumable_and_serial():
    svc = text('server/team-points/src/McaResultsCronService.php')
    assert "current_stage='index:1'" in svc
    assert '$arenaId <= $boundary' in svc
    assert "current_stage=?,last_error=NULL" in svc
    assert "DATE_ADD(UTC_TIMESTAMP(),INTERVAL 12 HOUR)" in svc
    assert "last_request_at=UTC_TIMESTAMP(6)" in svc
    assert 'if ($elapsed < 1.0) usleep' in svc
    assert "GET_LOCK(?,0)" in svc
    assert "needs_csv=1 AND status='pending' ORDER BY arena_id DESC LIMIT 1" in svc
    assert "status='error',last_error=?" in svc
    assert "status IN ('pending','running')" in svc  # errors do not block processing successful downloads
    assert "workflow = 'discovery_attention'" in svc
    assert "phase='failed_recovered'" in svc


def test_mca_acquisition_never_replaces_existing_sources_and_reuses_mira():
    svc = text('server/team-points/src/McaResultsCronService.php')
    assert 'if ($existing !== null)' in svc
    assert 'storeAutomaticCsv' in svc
    assert "source_origin,source_fetched_at" in svc
    assert "'auto',UTC_TIMESTAMP()" in svc
    assert 'validateResultsCsv' in svc
    for col in ['username', 'club', 'score']:
        assert f"'{col}'" in svc
    assert 'new LiveRanksService' in svc
    assert 'startProcessing($deadline - 1.0)' in svc
    assert 'DELETE FROM p2k_lr_players' in svc and 'DELETE FROM p2k_lr_arena_stats' in svc


def test_mca_admin_and_cron_split_results_from_historical_date_repair():
    page = text('TeamPointsAdmin.html')
    api = text('server/team-points/public/live-ranks-admin.php')
    js = text('assets/js/pages/team-points-features.js')
    cron = text('server/team-points/bin/mca-results-sync.php')
    reset = text('reset-install-mca-cron-v2.10.6.25.sh')
    for marker in ['Synchronize MCA Results', 'Historical MCA date repair', 'liveRanksDateSyncStart']:
        assert marker in page
    for action in ['sync_discovery', 'sync_hydrate', 'sync_retry_download_errors', 'date_sync_start', 'date_sync_step', 'date_sync_retry_errors']:
        assert action in api
    assert "workflow === 'discovery_attention'" in js
    assert 'if (payload.sync?.last_error) break;' in js
    assert '$service->runDiscovery' in cron and '$service->runHydration' in cron
    assert 'startAutoSync' not in cron and 'autoSyncStep' not in cron
    assert 'Historical date backfill is manual-only' in reset
    assert '17 0,12 * * *' in reset
    live = text('server/team-points/src/LiveRanksService.php')
    assert "DELETE FROM p2k_lr_sync_queue WHERE club_slug=? AND needs_csv=0" in live
    assert "WHERE club_slug=? AND needs_csv=0 GROUP BY status" in live
    assert "q.needs_csv=0 AND q.needs_date=1 AND q.status='pending'" in live
    assert "return $this->autoSyncStatus()+['cron_disabled'=>true]" in live
    assert "['discovery','discovery_attention','hydration','attention'].includes(workflow)" in js


def test_member_chronology_filters_and_join_presentation_are_server_backed():
    page = text('TeamPointsAdmin.html')
    js = text('assets/js/pages/team-points-admin.js')
    api = text('server/team-points-green/public/api.php')
    repo = text('server/team-points-green/src/GreenRepository.php')
    for marker in ['memberChronologyMember', 'memberChronologyEventType', 'memberChronologyFrom', 'memberChronologyTo']:
        assert marker in page
    assert '<th>Source</th>' not in page[page.index('Member chronology'):page.index('data-panel="storage"')]
    assert '<th>Cycle</th>' not in page[page.index('Member chronology'):page.index('data-panel="storage"')]
    assert "greenRequest('member-events',{params})" in js
    assert "'event_type'=>(string)($_GET['event_type']??'')" in api
    assert 'public function memberEvents(int $limit=200,array $filters=[])' in repo
    assert 'memberEventScope' in repo
    assert "if($raw==='discovered')" in repo
    assert "$event['event_type']='joined'" in repo
    assert "event_type IN ('discovered','joined')" in repo  # prior rename cleanup remains


def test_departure_checker_retains_exact_chess_account_closure_status():
    repo = text('server/team-points-green/src/GreenRepository.php')
    worker = text('server/team-points-green/src/GreenWorker.php')
    js = text('assets/js/pages/team-points-admin.js')
    assert "profile_account_status" in repo
    assert "profile_closure_reason" in repo
    assert "str_contains($reported,':')" in repo
    assert 'markDepartureProfileResult($username,$status,$r[\'json\'])' in worker
    assert "metadata.profile_account_status" in js
    assert "account += `:${reason}`" in js


def test_challenge_assistant_each_tab_can_save_loaded_list_to_existing_secured_endpoint():
    page = text('ChallengeListAssistant.html')
    js = text('assets/js/pages/challenge-list-assistant.js')
    router = text('api/router.php')
    for marker in ['p2kCheckerSaveList', 'p2kActivitySaveList', 'p2kRecommendationSaveList']:
        assert marker in page
        assert marker in js
    assert 'Save current list' in page
    assert 'normalizedServerList(key' in js
    assert 'saveServerDefault(key' in js
    assert 'body: { revision: serverListState.revision, clubs: parsed.clubs }' in js
    assert 'requestKind: "challenge-club-list"' in js
    assert "require_admin_write('challenge-club-list')" in router
    assert "revision" in router and "409" in router


def test_release_has_no_new_database_reset_or_schema_migration():
    assert not (ROOT / 'server/team-points/sql/analytics-migration-v2.10.6.25.sql').exists()
    notes = text('RELEASE_NOTES_v2.10.6.25.md')
    assert 'No database reset or reseed.' in notes
    assert 'No Blue/Green routing change.' in notes
