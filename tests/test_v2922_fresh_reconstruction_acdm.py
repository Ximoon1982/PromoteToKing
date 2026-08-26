from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8',errors='ignore')

def test_v2922_identity_and_additive_core15_reconstruction_schema():
    assert text('VERSION').strip() in {'2.9.22','2.9.22.1','2.9.22.2','2.9.22.3','2.9.22.4','2.9.22.5','2.9.22.6'}
    repo=text('server/team-points/src/Repository.php')
    assert ('CORE_SCHEMA_VERSION = 15;' in repo) or ('CORE_SCHEMA_VERSION = 16;' in repo)
    assert 'ANALYTICS_SCHEMA_VERSION = 7;' in repo
    mig=text('server/team-points/sql/core-migration-v2.9.22.sql')
    for table in ['p2k_tp_reconstruction_runs','p2k_tp_reconstruction_matches','p2k_tp_reconstruction_members','p2k_tp_reconstruction_boards','p2k_tp_reconstruction_games']:
        assert f'CREATE TABLE IF NOT EXISTS {table}' in mig
    assert 'club_applied_at DATETIME NULL' in mig
    assert 'player_applied_at DATETIME NULL' in mig
    assert 'VALUES(15)' in mig

def test_fresh_reconstruction_is_staged_checkpointed_and_explicitly_applied():
    svc=text('server/team-points/src/FreshPointsReconstruction.php')
    api=text('server/control/public/api.php')
    for action in ['reconstruction-status','reconstruction-start','reconstruction-command','reconstruction-progress','reconstruction-ingest','reconstruction-work','reconstruction-review','reconstruction-apply']:
        assert f"$action === '{action}'" in api
    assert "GET_LOCK(?,0)" in svc
    assert "status='applying'" in svc
    assert 'new AnalyticsBuilder' in svc
    assert 'Superseded by fresh reconstruction' in svc
    assert "i.item_type='sync_club_matches'" in svc
    assert "CONCAT('player-matches:',r.username_key)" in svc
    assert "r.closing_member=1" in svc

def test_club_pipeline_refetches_known_matches_closes_index_and_voids_finished_zero_zero():
    js=text('assets/js/pages/fresh-points-reconstruction.js')
    assert 'cacheMode:"network-only"' in js and 'networkOnly:true' in js
    assert 'Opening match discovery' in js
    assert 'Match-detail reconstruction' in js
    assert 'Closing match discovery' in js
    assert 'blindTailProbe' in js and 'length:128' in js
    assert 'excluded_zero_zero:zero' in js
    assert 'stage_state:"resolved"' in js
    svc=text('server/team-points/src/FreshPointsReconstruction.php')
    assert '$zero?1:0' in svc
    assert "competition_points=?,is_void=?" in svc

def test_player_pipeline_roster_matches_archive_board_game_closing_checks():
    js=text('assets/js/pages/fresh-points-reconstruction.js')
    for marker in ['Opening roster','Player match discovery','Archive fallback','Board/result resolution','Closing roster check','Closing player-match check','Closing board resolution','Player reconciliation']:
        assert marker in js
    assert '/games/archives' in js
    assert '/matches`' in js
    assert 'resolveBoard' in js
    assert 'extractGames' in js
    assert 'pointsX2' in js

def test_reconstruction_task_card_has_track_controls_phase_and_review_metrics():
    html=text('TaskControl.html'); js=text('assets/js/pages/task-control.js'); css=text('assets/css/task-control.css')
    assert any(f'fresh-points-reconstruction.js?v={v}' in html for v in ['2.9.22','2.9.22.1','2.9.22.4','2.9.22.5','2.9.22.6'])
    for marker in ['reconstructionClubChoice','reconstructionPlayerChoice','reconstructionClubBar','reconstructionPlayerBar','reconstructionPhaseRows','reconstructionReviewGrid']:
        assert marker in html or marker in js
    assert ('reconstructionApplyClub' in html or 'reconstructionApplyClub' in js) or ('reconstructionClubDifferenceRows' in html and 'reconstructionFinalizeClub' in html)
    assert ('reconstructionApplyPlayer' in html or 'reconstructionApplyPlayer' in js) or ('reconstructionPlayerDifferenceRows' in html and 'reconstructionFinalizePlayer' in html)
    for marker in ['Matches found','Members found','Boards found','Games reconstructed','Chess.com requests','Fetch failures']:
        assert marker in js
    assert ('Queue work superseded' in js) or ('queue item(s) superseded' in js)
    assert 'reconstruction-phase-progress' in css
    assert 'checkpointTimer=setInterval(()=>checkpoint(),15000)' in text('assets/js/pages/fresh-points-reconstruction.js')

def test_acdm_server_drain_policy_overrides_old_protected_25_item_settings():
    worker=text('server/team-points/src/Worker.php')
    cron=text('server/team-points/src/CronLoop.php')
    api=text('server/control/public/api.php')
    client=text('assets/js/shared/client-continuous-refresh.js')
    assert "$configuredMaxItems=$laneName==='club'?100" in worker
    assert "if($lane==='club')$configured=max(34,$configured);" in cron
    assert 'min(64,(int)($body[\'canonical_quota\'] ?? 16))' in api
    assert "$total >= 10000 ? 64" in api
    assert 'boundedInt(options.canonicalQuota ?? drain.suggested_quota, 16, 1, 64)' in client
    assert 'Math.min(64' in client
