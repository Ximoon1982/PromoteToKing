from pathlib import Path
import json, os, shutil, subprocess, tempfile

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8', errors='ignore')
def block(src,start,end):
    a=src.index(start); b=src.index(end,a); return src[a:b]

def php(code):
    p=subprocess.run(['php','-r',code],cwd=ROOT,text=True,capture_output=True,check=True)
    return p.stdout.strip()

def test_v2810_identity_schema_and_non_destructive_package_contract():
    assert text('VERSION').strip() in {'2.8.10','2.8.11','2.9.0','2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    repo=text('server/team-points/src/Repository.php')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [7,8,9,10,11,12,13,14,15]) and any(f'ANALYTICS_SCHEMA_VERSION = {v}' in repo for v in [5,6,7])
    assert 'core-migration-v2.8.10.sql' in repo
    assert (ROOT/'data/server-config.example.json').is_file()
    assert not (ROOT/'data/server-config.json').exists()
    assert not (ROOT/'data/tournaments/archive.json').exists()
    assert not (ROOT/'data/match-tracking/index.json').exists()


def test_canonical_match_outcome_semantics_are_score_derived():
    out=json.loads(php(r'''require 'server/team-points/src/bootstrap.php';
      echo json_encode([
        \P2K\TeamPoints\Repository::canonicalMatchOutcome(12.5,7.5,10,false),
        \P2K\TeamPoints\Repository::canonicalMatchOutcome(10.0,10.0,10,false),
        \P2K\TeamPoints\Repository::canonicalMatchOutcome(8.5,11.5,10,false),
        \P2K\TeamPoints\Repository::canonicalMatchOutcome(0.0,0.0,10,true)
      ]);'''))
    assert [x['result'] for x in out] == ['win','draw','loss','draw']
    assert [x['competition_points'] for x in out] == [50,20,0,0]
    assert out[-1]['is_void'] is True

    migration=text('server/team-points/sql/core-migration-v2.8.10.sql')
    schema=text('server/team-points/sql/core-schema.sql')
    compact=lambda x: x.replace(' ','').replace('\n','').replace('\t','')
    for sql in (migration,schema):
        c=compact(sql)
        assert "WHENp2k_score>opponent_scoreTHEN'win'" in c
        assert "WHENp2k_score<opponent_scoreTHEN'loss'" in c
        assert "WHENp2k_score>opponent_scoreTHEN5*board_count" in c
        assert "WHENp2k_score=opponent_scoreTHEN2*board_count" in c
    assert "SET is_void=1, result='draw', competition_points=0" in migration
    builder=text('server/team-points/src/AnalyticsBuilder.php')
    assert 'logic:canonical-score-outcomes-v2810' in builder
    opponents=block(builder,'private function rebuildOpponents','public function refreshAchievementsIfNeeded')
    assert 'LEFT JOIN p2k_tp_match_summaries s' in opponents
    for result in ('win','draw','loss'): assert f"s.result='{result}'" in opponents


def test_dashboard_keeps_core_members_and_all_history_finished_authoritative():
    js=text('assets/js/pages/dashboard-v2.js')
    load=block(js,'async function loadTeamData','function renderGauge')
    assert 'publicRequest?.("team")' in load
    assert 'setMatchMetric("registered", data.lists.registered)' in load
    assert 'setMatchMetric("ongoing", data.lists.ongoing)' in load
    assert 'setMatchMetric("finished"' not in load
    assert 'clubMembersAPI' not in js
    assert 'loadJSON(clubProfileAPI)' not in load
    assert 'data.database.current_members' in load
    repo=text('server/team-points/src/Repository.php')
    dash=block(repo,'public function publicClubDashboard','public function publicHallOfFame')
    assert 'p2k_tp_club_totals' in dash
    assert 'p2k_tp_match_summaries' in dash


def test_task_control_write_helper_supplies_required_request_kind_headers():
    client=text('assets/js/shared/team-points-client.js')
    assert '"X-Club-Tools-Request"' in client
    for kind in ['tournaments-update','tracked-match-data','record-league-match','track-upcoming-league-matches','scheduled-task-log','challenge-club-list','match-assistant-log']:
        assert f'"{kind}"' in client
    task=text('assets/js/pages/task-control.js')
    assert '["match-tracking","match-monitoring"].includes(state.selected)' in task
    assert 'requested === "match-monitoring" ? "match-tracking"' in task


def test_tournament_archive_recovers_from_nonempty_backup_semantically():
    d=ROOT/'data/tournaments'; primary=d/'archive.json'; backup=d/'archive.json.bak'
    old_primary=primary.read_bytes() if primary.exists() else None
    old_backup=backup.read_bytes() if backup.exists() else None
    try:
        d.mkdir(parents=True,exist_ok=True)
        primary.write_text(json.dumps({'schemaVersion':3,'generatedAt':None,'tournaments':[]}),encoding='utf-8')
        backup.write_text(json.dumps({'schemaVersion':3,'generatedAt':'2026-08-01T00:00:00Z','tournaments':[{'slug':'known-event','name':'Known event','status':'finished'}]}),encoding='utf-8')
        payload=json.loads(php(r'''require 'server/tournaments/src/bootstrap.php'; $a=(new \P2K\Tournaments\TournamentService())->archive(); echo json_encode($a);'''))
        assert len(payload.get('tournaments',[])) == 1
        assert payload['tournaments'][0]['slug'] == 'known-event'
        assert str(payload.get('recoverySource','')).startswith('backup:')
        sig1=php(r'''require 'server/tournaments/src/bootstrap.php'; echo (new \P2K\Tournaments\BrowseIndex())->signature();''')
        backup.write_text(json.dumps({'schemaVersion':3,'generatedAt':'2026-08-02T00:00:00Z','tournaments':[{'slug':'known-event','name':'Known event','status':'finished'},{'slug':'second-event','name':'Second event','status':'registration'}]}),encoding='utf-8')
        os.utime(backup,None)
        sig2=php(r'''require 'server/tournaments/src/bootstrap.php'; echo (new \P2K\Tournaments\BrowseIndex())->signature();''')
        assert sig1 != sig2
    finally:
        if old_primary is None: primary.unlink(missing_ok=True)
        else: primary.write_bytes(old_primary)
        if old_backup is None: backup.unlink(missing_ok=True)
        else: backup.write_bytes(old_backup)


def test_match_tracking_registry_recovers_and_persists_better_backup_semantically():
    d=ROOT/'data/match-tracking'; primary=d/'index.json'; backup=d/'index.json.bak'
    old_primary=primary.read_bytes() if primary.exists() else None
    old_backup=backup.read_bytes() if backup.exists() else None
    try:
        d.mkdir(parents=True,exist_ok=True)
        primary.write_text(json.dumps({'schemaVersion':3,'revision':0,'matches':{}}),encoding='utf-8')
        backup.write_text(json.dumps({'schemaVersion':3,'revision':5,'matches':{'4242':{'matchId':'4242','name':'Recovered','followed':True}}}),encoding='utf-8')
        payload=json.loads(php(r'''require 'api/_common.php'; $r=read_follow_registry(); echo json_encode($r);'''))
        assert payload['matches']['4242']['followed'] is True
        persisted=json.loads(primary.read_text(encoding='utf-8'))
        assert '4242' in persisted.get('matches',{})
        assert int(persisted.get('revision',0)) > 5
    finally:
        if old_primary is None: primary.unlink(missing_ok=True)
        else: primary.write_bytes(old_primary)
        if old_backup is None: backup.unlink(missing_ok=True)
        else: backup.write_bytes(old_backup)


def test_cron_dispatcher_reads_current_tokens_and_records_pre_http_heartbeat():
    dispatch=text('cron-dispatch-v2.8.10.sh')
    install=text('reset-install-cron-v2.8.10.sh')
    assert 'X-P2K-Cron-Token' in dispatch
    assert '?token=' not in dispatch
    assert 'last-${TASK_KEY}.json' in dispatch and 'status=invoked' in dispatch
    assert 'data/server-config.json' in dispatch and 'config.local.php' in dispatch
    assert 'crontab FILE already performs a complete replacement atomically' in install
    assert 'cron-dispatch-v2.8.10.sh' in install
    assert 'Recovered the shared CRON token from the existing crontab' in install
    assert 'Generated a new protected shared CRON token' in install and 'random_bytes(32)' in install
    for schedule in ['*/5 * * * *','2-59/10 * * * *','7,37 * * * *','17 * * * *']:
        assert schedule in install
    # Installed cron lines contain no actual secret token or token query string.
    cron_block=install[install.index('# BEGIN PROMOTE TO KING v2.8.10'):install.index('# END PROMOTE TO KING v2.8.10')]
    assert '?token=' not in cron_block and 'TEAM_POINTS_TOKEN' not in cron_block and 'SHARED_TOKEN' not in cron_block


def test_cron_endpoints_accept_protected_header_with_query_compatibility():
    tp=text('server/team-points/public/cron.php')
    tr=text('server/tournaments/public/cron.php')
    api=text('api/router.php')
    for src in (tp,tr,api):
        assert 'HTTP_X_P2K_CRON_TOKEN' in src
        assert "$_GET['token']" in src


def test_intelligence_contract_uses_real_operational_freshness_and_separates_backlog():
    svc=text('server/team-points/src/ClubIntelligenceService.php')
    assert '$roster>7200' in svc and '$roster>3600' in svc
    assert '$clubIndex>1800' in svc and '$clubIndex>900' in svc
    assert 'finished_board_complete_percent' in svc and 'active_board_backlog' in svc
    assert 'core_analytics_lag_seconds' in svc and 'oldest_finished_unresolved_seconds' in svc
    assert 'standard_activity_class' in svc and 'chess960_activity_class' in svc
    assert 'league_points' in svc and 'strength_adjusted_points' in svc and 'consistency_score' in svc
    assert 'private ?array $memberRowsCache' in svc and '$row=$this->memberRow($key)' in svc
    assert 'TaskControl.html?task=team-points-player' in svc
    assert 'TaskControl.html?task=team-points-club' in svc
    assert "$path.'.bak'" in svc and 'browse-index-v1.json' in svc and "'browse-index-cache'" in svc
    assert "is_array($last)?($last['at']??null)" in svc and 'status_age_seconds' in svc
    task_ui=text('assets/js/pages/task-control.js')
    assert 'Last shell invocation' in task_ui and 'Shell HTTP' in task_ui and 'task.cron_shell' in task_ui
    ui=text('ClubIntelligence.html')
    assert 'ACAMR effectiveness' in ui and 'ACAMR (disabled)' not in ui
    dashboard=text('assets/js/pages/dashboard-v2.js')
    assert 'ACAMR effectiveness' in dashboard


def test_tournament_and_match_tracking_cadences_are_consistent():
    schema=text('server/team-points/sql/core-schema.sql')
    migration=text('server/team-points/sql/core-migration-v2.8.10.sql')
    tournament_cron=text('server/tournaments/public/cron.php')
    control=text('server/control/public/api.php')
    assert "('tournaments','Tournament maintenance',600" in schema or "('tournaments','Tournament" in schema and ',600,' in schema
    assert 'expected_interval_seconds=600' in migration
    assert "'expectedIntervalSeconds'=>600" in tournament_cron
    assert "'tournaments'=>600" in control
    service=text('server/tournaments/src/TournamentService.php')
    assert "$statusLimit = max(1, min(20" in service and "$workerStage === 'status'" not in service[service.index('$statusLimit'):service.index('$cursor', service.index('$statusLimit'))]
    assert "lastStatusCheckAt" in service and "'statusCheckedAt'" in service
    assert "('match-tracking','Match monitoring',3600" in schema


def test_cron_installer_recovers_shared_token_and_installs_secretless_dispatchers(tmp_path):
    import os, shutil
    site=tmp_path/'site'; fakebin=tmp_path/'bin'; state=tmp_path/'crontab.txt'
    (site/'server/team-points/config').mkdir(parents=True)
    (site/'data').mkdir(parents=True)
    fakebin.mkdir()
    runtime=site/'runtime'
    (site/'server/team-points/config/config.local.php').write_text(
        "<?php return ['app'=>['cron_token'=>'tp-secret-2810'],'storage'=>['runtime_dir'=>" + repr(str(runtime)) + "]];\n",
        encoding='utf-8')
    shutil.copy2(ROOT/'reset-install-cron-v2.8.10.sh', site/'reset-install-cron-v2.8.10.sh')
    shutil.copy2(ROOT/'cron-dispatch-v2.8.10.sh', site/'cron-dispatch-v2.8.10.sh')
    os.chmod(site/'reset-install-cron-v2.8.10.sh',0o755); os.chmod(site/'cron-dispatch-v2.8.10.sh',0o755)
    legacy='shared secret/with+chars'
    from urllib.parse import quote
    state.write_text(
        "*/5 * * * * curl 'https://www.promotetoking.org/server/team-points/public/cron-club.php?token=oldtp'\n"
        f"2-59/10 * * * * curl 'https://www.promotetoking.org/server/tournaments/public/cron.php?token={quote(legacy,safe='')}'\n"
        "7,37 * * * * curl 'https://www.promotetoking.org/server/team-points/public/cron-player.php?token=oldtp'\n"
        f"17 * * * * curl 'https://www.promotetoking.org/api/track-upcoming-league-matches/?token={quote(legacy,safe='')}'\n",
        encoding='utf-8')
    fake=(fakebin/'crontab')
    fake.write_text(r'''#!/usr/bin/env bash
set -euo pipefail
state=${FAKE_CRONTAB_STATE:?}
case "${1:-}" in
  -l) [[ -f "$state" ]] && cat "$state" || exit 1 ;;
  -r) : > "$state" ;;
  -) cat > "$state" ;;
  "") exit 2 ;;
  *) cp "$1" "$state" ;;
esac
''',encoding='utf-8')
    os.chmod(fake,0o755)
    env=os.environ.copy(); env.update({
        'PATH':str(fakebin)+os.pathsep+env.get('PATH',''),
        'FAKE_CRONTAB_STATE':str(state),
        'P2K_SITE_ROOT':str(site),
        'P2K_BASE_URL':'https://www.promotetoking.org',
    })
    cp=subprocess.run([str(site/'reset-install-cron-v2.8.10.sh')],env=env,cwd=site,text=True,capture_output=True,timeout=30)
    assert cp.returncode==0, cp.stdout+'\n'+cp.stderr
    installed=state.read_text(encoding='utf-8')
    assert installed.count('cron-dispatch-v2.8.10.sh')==4
    assert '?token=' not in installed and 'tp-secret-2810' not in installed and legacy not in installed
    shared=json.loads((site/'data/server-config.json').read_text(encoding='utf-8'))
    assert shared['cronToken']==legacy
    backups=list((runtime/'cron-shell').glob('crontab-before-v2.8.10-*.txt'))
    assert backups and 'server/tournaments/public/cron.php?token=' in backups[0].read_text(encoding='utf-8')


def test_site_router_does_not_delay_known_admin_on_remote_club_profile():
    js=text('assets/js/pages/site-tabs.js')
    fn=block(js,'async function authenticatedClubAdmin','async function initializeRouter')
    local=fn.index('configuredAdminUsernames().has(username) || validLocalAdminMarker(username)')
    remote=fn.index('window.P2K_API_CLIENT?.json')
    fallback=fn.index('await fetch(clubApiUrl')
    assert local < remote < fallback
    assert 'profile = await window.P2K_API_CLIENT.json(clubApiUrl' in fn
    assert 'return false;' in fn


def test_cron_installer_generates_shared_token_when_no_legacy_token_exists(tmp_path):
    import os, shutil
    site=tmp_path/'site'; fakebin=tmp_path/'bin'; state=tmp_path/'crontab.txt'; runtime=site/'runtime'
    (site/'server/team-points/config').mkdir(parents=True); (site/'data').mkdir(parents=True); fakebin.mkdir()
    (site/'server/team-points/config/config.local.php').write_text(
        "<?php return ['app'=>['cron_token'=>'tp-secret-2810'],'storage'=>['runtime_dir'=>" + repr(str(runtime)) + "]];\n", encoding='utf-8')
    shutil.copy2(ROOT/'reset-install-cron-v2.8.10.sh',site/'reset-install-cron-v2.8.10.sh')
    shutil.copy2(ROOT/'cron-dispatch-v2.8.10.sh',site/'cron-dispatch-v2.8.10.sh')
    os.chmod(site/'reset-install-cron-v2.8.10.sh',0o755); os.chmod(site/'cron-dispatch-v2.8.10.sh',0o755)
    fake=fakebin/'crontab'
    fake.write_text("#!/usr/bin/env bash\nset -euo pipefail\nstate=${FAKE_CRONTAB_STATE:?}\ncase \"${1:-}\" in\n  -l) [[ -f \"$state\" ]] && cat \"$state\" || exit 1 ;;;\n  -r) : > \"$state\" ;;;\n  -) cat > \"$state\" ;;;\n  \"\") exit 2 ;;;\n  *) cp \"$1\" \"$state\" ;;;\nesac\n".replace(';;;',';;'),encoding='utf-8')
    os.chmod(fake,0o755)
    env=os.environ.copy(); env.update({'PATH':str(fakebin)+os.pathsep+env.get('PATH',''),'FAKE_CRONTAB_STATE':str(state),'P2K_SITE_ROOT':str(site),'P2K_BASE_URL':'https://www.promotetoking.org'})
    cp=subprocess.run([str(site/'reset-install-cron-v2.8.10.sh')],env=env,cwd=site,text=True,capture_output=True,timeout=30)
    assert cp.returncode==0, cp.stdout+'\n'+cp.stderr
    shared=json.loads((site/'data/server-config.json').read_text(encoding='utf-8'))
    token=shared.get('cronToken',''); assert len(token)==64 and all(c in '0123456789abcdef' for c in token)
    assert token not in state.read_text(encoding='utf-8')
