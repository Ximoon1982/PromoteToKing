from pathlib import Path
import json, re, subprocess

ROOT=Path(__file__).resolve().parents[1]

def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_identity_and_schema():
    assert text('VERSION').strip().startswith('2.10.6')
    assert text('MIGRATION_VERSION').strip().startswith('2.10.6')
    repo=text('server/team-points/src/Repository.php')
    assert 'CORE_SCHEMA_VERSION = 17' in repo
    assert 'ANALYTICS_SCHEMA_VERSION = 9' in repo
    assert 'core-migration-v2.10.6.sql' in repo
    assert 'analytics-migration-v2.10.6.sql' in repo
    assert "public const VERSION = '2.10.6" in text('server/team-points-green/src/GreenConfig.php')
    assert 'version: "2.10.6.' in text('assets/js/site-config.js') or 'version: "2.10.6"' in text('assets/js/site-config.js')

def test_gabcrf_is_a_real_resumable_lane():
    gab=text('server/team-points-green/src/GreenAnalyticsBootstrap.php')
    compat=text('server/team-points-green/src/GreenCompatibility.php')
    assert "['compat_reconciliation','Green compatibility reconciliation (GABCRF)',65]" in gab
    assert "function reconcileCompatibilityBatch" in compat
    assert "'reset_cursor'" in compat
    assert "lane_key IN ('compat_reconciliation','analytics_build','read_parity')" in gab
    assert "gab_phase='compat_reconciliation'" in gab
    assert 'projectMatch($id,false)' in compat

def test_opponent_player_projection_and_insights():
    schema=text('server/team-points/sql/core-schema.sql')
    worker=text('server/team-points/src/Worker.php')
    repo=text('server/team-points/src/Repository.php')
    green=text('server/team-points-green/src/GreenCompatibility.php')
    ui=text('assets/js/pages/dashboard-insights.js')
    assert 'opponent_username VARCHAR(80)' in schema
    assert "'opponent_username'" in worker
    assert 'opponent_username=COALESCE(VALUES(opponent_username),opponent_username)' in repo
    assert "$board['opponent_username']=$opponentUsername!==''?$opponentUsername:null" in green
    assert "'player_summary'=>$playerSummary,'players'=>$players" in repo
    assert 'Opponent players' in ui
    assert 'P2K W/D/L' in ui or 'W/D/L' in ui

def test_recent_admin_match_click_is_network_first_only_for_new24():
    endpoint=text('server/team-points/public/match-detail-refresh.php')
    worker=text('server/team-points/src/Worker.php')
    ui=text('assets/js/pages/dashboard-v2.js')
    assert 'Auth::requireAdmin()' in endpoint
    assert "Http::method('POST')" in endpoint
    assert 'refreshMatchNow' in worker
    assert "https://api.chess.com/pub/match/" in worker
    assert 'openAdminFreshMatchDetail' in ui
    assert 'kind==="new24"?openAdminFreshMatchDetail' in ui
    assert 'openMatchDetail(Number(button.dataset.adminMatchDetail))' in ui

def test_mca_auto_sync_durable_serial_and_manual_fallback():
    svc=text('server/team-points/src/LiveRanksService.php')
    adm=text('server/team-points/public/live-ranks-admin.php')
    ui=text('assets/js/pages/team-points-features.js')
    html=text('TeamPointsAdmin.html')
    migration=text('server/team-points/sql/analytics-migration-v2.10.6.sql')
    assert 'p2k_lr_sync_state' in migration and 'p2k_lr_sync_queue' in migration
    assert 'last_request_at DATETIME(6)' in migration
    assert "if($elapsed<1.0)usleep" in svc
    assert "'request_spacing_ms'=>1000,'serial'=>true" in svc
    assert "discoverArenaLinks" in svc
    assert '$arenas=$discovered' in svc
    assert 'array_slice($discovered,0,40)' in svc
    assert 'possibleRenameArenaIdentities' in svc
    assert 'next_scan_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 12 HOUR)' in svc
    cron=text('reset-install-mca-cron-v2.10.6.sh')
    assert '17 0,12 * * *' in cron and 'cron-mca-results-v2.10.6.sh' in cron
    assert "$action === 'sync_start'" in adm and "$action === 'sync_step'" in adm
    assert 'Grab missing Results CSV' in html
    assert 'liveRanksSyncResume' in html
    assert 'runMcaSourceSync' in ui
    # Existing manual upload / staged processing remain available.
    assert "$action === 'upload'" in adm
    assert "$action === 'process_start'" in adm and "$action === 'process_step'" in adm

def test_mca_parser_fixture():
    php=r'''require "server/team-points/src/LiveRanksService.php";
    $r=new ReflectionClass("P2K\\TeamPoints\\LiveRanksService");
    $o=$r->newInstanceWithoutConstructor();
    $d=$r->getMethod("discoverArenaLinks");$d->setAccessible(true);
    $rows=$d->invoke($o,'<a href="/tournament/live/arena/older-31321355">x</a><a href="https://www.chess.com/tournament/live/arena/we-love-wednesday-32-2h--31321357">y</a>');
    if(count($rows)!==2||$rows[0]["arena_id"]!==31321357)exit(11);
    $e=$r->getMethod("extractArenaStart");$e->setAccessible(true);
    $date=$e->invoke($o,'Rating: Open 127 players Aug 18, 2026, 7:30 PM');
    if(($date["event_date"]??"")!=="2026-08-18")exit(12);
    echo "ok";'''
    p=subprocess.run(['php','-r',php],cwd=ROOT,text=True,capture_output=True)
    assert p.returncode==0,(p.stdout,p.stderr)
    assert p.stdout.strip()=='ok'
