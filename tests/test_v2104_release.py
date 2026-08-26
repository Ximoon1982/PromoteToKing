from pathlib import Path
import json, re, os, subprocess, tempfile, shutil

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_identity_and_schema():
    assert text('VERSION').strip()=='2.10.4'
    assert text('MIGRATION_VERSION').strip()=='2.10.4'
    assert text('BLUE_BASELINE_VERSION').strip()=='2.9.22.10'
    repo=text('server/team-points/src/Repository.php')
    assert 'CORE_SCHEMA_VERSION = 16' in repo
    assert 'ANALYTICS_SCHEMA_VERSION = 8' in repo
    assert "analytics-migration-v2.10.4.sql" in repo
    sql=text('server/team-points/sql/analytics-migration-v2.10.4.sql')
    for col in ('actual_event_date','effective_event_date','event_date_precision','event_date_updated_at'):
        assert col in sql

def test_admin_session_is_oauth_bound_and_legacy_writes_are_admin_secured():
    session=text('server/team-points/public/session.php')
    oauth=text('server/team-points/src/OAuthSession.php')
    common=text('api/_common.php'); router=text('api/router.php')
    client=text('assets/js/shared/team-points-client.js')
    assert 'OAuthSession::authenticatedUsername(true)' in session
    assert "body()['username']" not in session and "$body['username']" not in session
    assert 'authenticatedUsername(bool $closeSession = true)' in oauth
    assert "SESSION_NAME = 'P2KOAUTH'" in oauth
    assert 'function require_admin_write' in common and 'Auth::requireAdmin()' in common
    for marker in ('challenge-club-list','scheduled-task-log','record-league-match','track-upcoming-league-matches','tracked-match-data'):
        assert f"require_admin_write('{marker}')" in router
    assert 'body: JSON.stringify({})' in client
    assert 'P2K_ADMIN_USERNAME' not in client

def test_display_identity_projects_ui_only():
    oauth=text('assets/js/shared/real-oauth.js')
    dash=text('assets/js/pages/dashboard-v2.js')
    tabs=text('assets/js/pages/site-tabs.js')
    guard=text('assets/js/shared/admin-page-guard.js')
    migration=text('TeamPointsMigration.html')
    assert 'displayOnly: Boolean(requestedDisplayName)' in oauth
    assert 'authenticatedUsername: session.username' in oauth
    for src in (dash,tabs,guard):
        assert ('displayOnly !== true' in src or 'displayOnly!==true' in src)
        assert 'adminFlagEnabled' not in src
    assert 'target.searchParams.delete("admin")' in dash
    assert 'admin-page-guard.js' in migration and 'admin-access-pending' in migration

def test_achievement_progress_and_see_mine_contract():
    service=text('server/team-points/src/ClubIntelligenceService.php')
    demo=text('TournamentAchievementBadgesDemo.html')
    dash=text('assets/js/pages/dashboard-v2.js')
    catalog=text('server/team-points/src/AchievementCatalog.php')
    assert service.count('self::item(')==0  # catalogue lives separately; guard accidental duplication
    assert 'if($target===null||$target<=0||$cur===null)continue;$cur=max(0,(float)$cur)' in service
    assert "!earned" in demo and "!earned" in dash
    assert 'See mine' in demo
    assert demo.index('Achievement catalogue') < demo.index('See mine') < demo.index('Most earned')
    assert 'progressMap.get(k)' in demo and "!has&&pr&&Number(pr.target)>0" in demo
    assert 'Achievement date' in demo and 'approximative date' in demo
    assert len(re.findall(r"self::item\('",catalog))==191
    for k in ('first-match','first-point','matches-10','matches-50','matches-100','matches-250','matches-500'):
        assert f"self::item('{k}'" in catalog


def test_matches_team_points_artwork_placeholder_release_is_explicit_and_safe():
    import hashlib
    provenance=json.loads(text('ARTWORK_PROVENANCE_v2.10.4.json'))
    assert provenance.get('status')=='released-with-placeholders'
    expected={
        'first-match':'8466f10a34b4b35a2af72965d6b0ca942565d964b6fa6abfe8d0293978a0cd84',
        'first-point':'1550af9a0c66bc69e1bb50a19614ddb3c0bc533677cbee8a147ac5a0379529e7',
        'matches-10':'ef7f484e59db60cb436b8ed41a3c59f268b53a0a92fc2351b642648767f710f0',
        'matches-50':'42502c69dc3af5580c873225631575d62f07489388856ac149b1ce3a0def05c9',
        'matches-100':'fb0e01f9bcd4f875185cc16c924fb6f6c48fa26bfb777ab9334706df9d63e57b',
        'matches-250':'c834bc4a85c3f5f1aebec9523a21ad69bdd6603843747ab37fdb4bce1109c900',
        'matches-500':'5398fb202249dfd469f2249754a5d4c29e51d072d5e0786e06d44843573ccf77',
    }
    entries={e['key']:e for e in provenance['entries']}
    assert set(entries)==set(expected)
    catalog=text('server/team-points/src/AchievementCatalog.php')
    for key,source_sha in expected.items():
        e=entries[key]
        assert e['source_sha256']==source_sha
        assert e['release_state']=='placeholder'
        rel=e['release_asset']; path=ROOT/rel
        assert path.is_file() and path.suffix=='.svg'
        assert f"self::placeholder('{key}')" in catalog

def test_mca_event_date_provenance_and_links():
    svc=text('server/team-points/src/LiveRanksService.php')
    ui=text('assets/js/pages/team-points-features.js')
    builder=text('server/team-points/src/AnalyticsBuilder.php')
    assert "https://www.chess.com/tournament/live/arena/" in svc
    assert "preg_match('/-(\\d+)$/'" in svc
    assert "event_date_precision" in svc and "interpolated" in svc and "upload-fallback" in svc
    assert 'approximative date' in ui and 'Edit actual date' in ui and 'Set actual date' in ui
    assert "mca-interpolated" in builder and "mca-upload-fallback" in builder and "mca-event-date" in builder
    assert "effective_event_date" in builder

def test_green_integrity_and_current_member_projection():
    repo=text('server/team-points-green/src/GreenRepository.php')
    worker=text('server/team-points-green/src/GreenWorker.php')
    assert 'GICL' in repo
    assert 'bounded/idempotent convergence sweep' in repo
    assert "m.status='finished'" in repo and "b.finished_game_count>COALESCE(e.c,0)" in repo
    assert 'integrityConvergence' in repo or 'convergence' in repo.lower()
    assert 'current_member=1' in repo
    assert "JOIN p2k_g_players p ON p.username_key=COALESCE(i.canonical_username_key,e.username_key) AND p.current_member=1" in repo
    assert 'integrity' in worker.lower() or 'convergence' in worker.lower()

def test_gscf_installer_preserves_blue_and_is_idempotent():
    script=ROOT/'reset-install-green-cron-v2.10.4.sh'
    assert script.exists()
    src=script.read_text(encoding='utf-8')
    assert 'P2K_GSCF_BEGIN' in src and 'P2K_GSCF_END' in src
    assert '2,32 * * * *' in src
    assert 'soft=18s' in src and 'hard=24s' in src and 'lease=p2k_green_worker' in src
    install=text('server/team-points-green/public/install.php')
    assert "if($action==='cron-info')" in install and install.index("if($action==='cron-info')") < install.index("Use POST for installer actions")
    with tempfile.TemporaryDirectory() as td:
        td=Path(td); fakebin=td/'bin'; fakebin.mkdir(); site=td/'site'; (site/'server/team-points-green/config').mkdir(parents=True)
        (site/'server/team-points-green/config/green.local.php').write_text("<?php\nreturn ['app'=>['cron_token'=>'test-token']];\n",encoding='utf-8')
        state=td/'crontab.txt'
        blue="*/5 * * * * /usr/bin/curl https://example.test/server/team-points/public/cron-club.php?token=blue\n"
        unrelated="13 * * * * /bin/echo keep-me\n"
        oldgreen="7 * * * * /usr/bin/curl https://example.test/server/team-points-green/public/cron.php?token=old\n"
        state.write_text(blue+unrelated+oldgreen,encoding='utf-8')
        crontab=fakebin/'crontab'
        crontab.write_text('#!/usr/bin/env bash\nset -e\nif [[ "${1:-}" == "-l" ]]; then cat "$FAKE_CRONTAB"; else cp "$1" "$FAKE_CRONTAB"; fi\n',encoding='utf-8'); crontab.chmod(0o755)
        curl=fakebin/'curl'; curl.write_text('#!/usr/bin/env bash\nexit 0\n',encoding='utf-8'); curl.chmod(0o755)
        env=os.environ.copy(); env['PATH']=str(fakebin)+os.pathsep+env.get('PATH',''); env['FAKE_CRONTAB']=str(state)
        for _ in range(2): subprocess.run(['bash',str(script),str(site),'https://example.test'],env=env,check=True,capture_output=True,text=True)
        out=state.read_text(encoding='utf-8')
        assert blue.strip() in out and unrelated.strip() in out
        assert 'token=old' not in out
        assert out.count('P2K_GSCF_BEGIN')==1 and out.count('P2K_GSCF_END')==1
        assert '2,32 * * * *' in out
        assert out.count('/server/team-points-green/public/cron.php?token=test-token')==1

def test_gqac_quick_cycle_accounting_and_convergence_contract():
    repo=text('server/team-points-green/src/GreenRepository.php')
    worker=text('server/team-points-green/src/GreenWorker.php')
    schema=text('server/team-points-green/sql/core-schema.sql')
    ui=text('assets/js/pages/team-points-migration.js')
    # One finite snapshot per quick cycle; fresh hints do not enlarge it.
    assert 'p2k_g_quick_board_cycles' in schema and 'p2k_g_quick_board_cycle_items' in schema
    assert 'INSERT IGNORE INTO p2k_g_quick_board_cycle_items' in repo
    assert "WHERE b.needs_refresh=1" in repo
    assert "i.cycle_no=" in repo and "i.status='pending'" in repo
    # Worker and browser planner consume the same cycle cohort rather than global board debt.
    assert 'nextQuickBoardNeedingRefresh($this->cycle)' in worker
    assert "claimQuickBoardRows($limit,$owner,(int)($s['cycle_no']??0))" in repo
    assert "quickBoardCycleState((int)($s['cycle_no']??0),$browserOwner)" in repo
    # A terminal observation retires the item; a hint changed after admission remains due next cycle.
    assert "needs_refresh=CASE WHEN hint_hash <=> ? THEN 0 ELSE 1 END" in repo
    assert "status='completed',requeued_for_next=?" in repo
    assert "in_array($status,[404,410],true)" in repo
    # Transient failures are not marked complete and retain bounded retry/backoff.
    assert "$delay=$status===429?300:120" in repo
    # Accounting comes from the same item table and exposes the convergence diagnostics.
    for marker in ('claim_attempts','unique_claimed_ids','repeated_claimed_ids','requeued_for_next','net_completion','percent'):
        assert marker in repo
    assert 'GQAC cycle' in ui and 'GQAC claims' in ui and 'GQAC requeue / net' in ui
    # Browser handoff cannot advance while the finite cohort still has pending items.
    assert "quick_boards_cycle_still_due" in repo and "quick_boards_cycle_drained" in repo
