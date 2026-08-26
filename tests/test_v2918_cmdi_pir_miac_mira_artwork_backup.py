from __future__ import annotations
from pathlib import Path
from io import BytesIO
import hashlib, json, subprocess, tempfile, os
from PIL import Image

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')
def sha(path): return hashlib.sha256(Path(path).read_bytes()).hexdigest()

def test_artwork_master_thumb_mini_lineage_for_new_and_all_leagues():
    rec=json.loads((ROOT/'POST_v2.9.17_ACHIEVEMENT_ARTWORK_INTEGRATION.json').read_text())
    assert rec['new_approved_count']==43
    assert rec['league_derivatives_regenerated']==35
    keys=[r['key'] for r in rec['records']]+list(rec['league_keys'])
    assert len(keys)==78 and len(set(keys))==78
    catalogue=text('server/team-points/src/AchievementCatalog.php')
    expected_master={r['key']:r['master_sha256'] for r in rec['records']}
    for key in keys:
        master=ROOT/f'assets/images/achievements/{key}.png'
        thumb=ROOT/f'assets/images/achievements/thumbs/128/{key}.webp'
        mini64=ROOT/f'assets/images/achievements/mini/64/{key}.webp'
        mini=ROOT/f'assets/images/achievements/mini/{key}.webp'
        assert master.exists() and thumb.exists() and mini64.exists() and mini.exists(), key
        im=Image.open(master).convert('RGBA'); assert im.size==(640,640)
        if key in expected_master: assert sha(master)==expected_master[key]
        for size,path in [(128,thumb),(64,mini64),(64,mini)]:
            target=im.resize((size,size),Image.Resampling.LANCZOS)
            b=BytesIO(); target.save(b,'WEBP',quality=92,method=6)
            assert hashlib.sha256(b.getvalue()).hexdigest()==sha(path), f'{key} {size} derivative is not from canonical master'
        assert f'assets/images/achievements/{key}.png' in catalogue
        assert f'assets/images/achievements/thumbs/128/{key}.webp' in catalogue

def test_miac_seed_and_schema_contract():
    assert sha(ROOT/'resources/miac/seed.zip')=='fbb0ad58132a65836e2a2c8c10f9f952dc727f41a668671cba69dc822f1b4310'
    seed=json.loads((ROOT/'resources/miac/miac-seed.json').read_text())
    assert seed['format']=='p2k-miac-seed-v1'
    assert seed['summary']['historical_usernames']==6956
    assert len(seed['candidates'])==429 and len(seed['chains'])==292
    core=text('server/team-points/sql/core-migration-v2.9.18.sql')
    assert 'p2k_miac_state' in core and 'p2k_miac_names' in core and 'p2k_miac_edges' in core and 'p2k_miac_canonical_map' in core
    assert "VALUES(14)" in core
    repo=text('server/team-points/src/Repository.php')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in (14,15)) and 'ANALYTICS_SCHEMA_VERSION = 7' in repo
    svc=text('server/team-points/src/MiacService.php')
    assert "status='confirmed'" in svc and 'edgePlayerIdConflict' in svc and "'hard_conflict'" in svc
    assert "player_id_link" in svc and "data/miac/seed/miac-seed.json" in svc

def test_mira_raw_attribution_generation_and_event_dedupe_contract():
    migration=text('server/team-points/sql/analytics-migration-v2.9.18.sql')
    assert 'p2k_lr_source_rows' in migration and 'p2k_lr_attributions' in migration
    assert 'identity_map_generation' in migration and "VALUES(7)" in migration
    live=text('server/team-points/src/LiveRanksService.php')
    assert 'source_row_no' in live and 'raw_username_key' in live and 'canonical_username_key' in live
    assert "arena_count']++; // distinct source event" in live
    assert 'identityAttributionStatus' in live and 'rebuildIdentityAttributionIfNeeded(?float $deadlineAt' in live
    assert "identity_generation_changed" in live and 'MIRA_DEADLINE' in live
    assert '$resolutionCache' in live

def test_pir_detects_and_queues_only_authoritative_repairs():
    pir=text('server/team-points/src/PointIntegrityService.php')
    assert 'match_outcome_points' in pir and 'member_points_team_score_mismatch' in pir and 'finished_board_incomplete' in pir
    assert "'source'=>'pir'" in pir and "'force_revalidate'=>true" in pir
    # PIR records issues and queues verification; it does not mutate canonical result/score/competition fields.
    forbidden=['UPDATE p2k_tp_match_metadata SET result','UPDATE p2k_tp_match_metadata SET p2k_score','UPDATE p2k_tp_games SET points_x2']
    assert not any(x in pir for x in forbidden)
    assert '$processedAll=$checked===count($matches)' in pir
    assert 'boardId??0' in pir
    assert 'board_id BIGINT UNSIGNED NOT NULL DEFAULT 0' in text('server/team-points/sql/core-migration-v2.9.18.sql')
    worker=text('server/team-points/src/Worker.php')
    assert 'force_revalidate' in worker

def test_cmdi_separates_worker_from_optional_maintenance():
    loop=text('server/team-points/src/CronLoop.php')
    assert 'ClubIntelligenceService' not in loop
    cron=text('server/team-points/public/cron.php')
    assert 'CronMaintenanceCoordinator' in cron
    coord=text('server/team-points/src/CronMaintenanceCoordinator.php')
    for name in ['analytics','intelligence','pir','achievements','mira','housekeeping','storage']:
        assert f"runClass('{name}'" in coord
    assert 'max_statement_time' in coord and 'hard_return_reserve_seconds' in coord
    assert 'runIfDue(21600, $deadline)' in coord and 'snapshot(true, $deadline)' in coord
    assert 'deadline_reached' in text('server/team-points/src/Housekeeping.php')

def test_miac_admin_review_surface_and_mira_staleness_bridge():
    assert 'Aliases &amp; name changes' in text('ClubIntelligence.html')
    js=text('assets/js/pages/club-intelligence.js')
    assert 'renderAliases' in js and 'miacReviewEdge' in js and 'server/team-points/public/miac.php' in js
    assert 'miacRenderChainList' in js and 'miacOpenEvidence' in js and 'Manual confirmation queue' in js
    intelligence=text('server/team-points/public/intelligence.php')
    assert "'aliases'=>(new MiacService" in intelligence
    live=text('server/team-points/src/LiveRanksService.php')
    assert 'identityAttributionStatus' in live and 'rebuildIdentityAttributionIfNeeded' in live


def test_weekly_backup_is_single_archive_and_excludes_rebuildable_or_nested_backup_data():
    script=ROOT/'weekly-backup-v2.9.18.sh'
    subprocess.run(['bash','-n',str(script)],check=True)
    source=text('weekly-backup-v2.9.18.sh')
    assert "PromoteToKing_LongLife_${WEEK}.tar.gz" in source
    assert "data/tournaments/cache" in source and "data/tournaments/locks" in source and "data/live-ranks/backups" in source
    assert "data/miac/seed/seed.zip" in source and 'P2K_WEEKLY_BACKUP_KEEP:-52' in source
    with tempfile.TemporaryDirectory() as td:
        root=Path(td); (root/'data/tournaments/cache').mkdir(parents=True); (root/'data/live-ranks/uploads').mkdir(parents=True); (root/'logs/scheduled-tasks').mkdir(parents=True)
        (root/'data/tournaments/tournaments.json').write_text('long-life')
        (root/'data/tournaments/cache/cache.json').write_text('rebuildable')
        (root/'data/live-ranks/uploads/a.csv').write_text('arena')
        (root/'logs/scheduled-tasks/a.jsonl').write_text('log')
        env=os.environ.copy(); env['P2K_SITE_ROOT']=td; env['P2K_WEEKLY_BACKUP_KEEP']='4'
        subprocess.run(['bash',str(script)],env=env,check=True,capture_output=True,text=True)
        subprocess.run(['bash',str(script)],env=env,check=True,capture_output=True,text=True)
        archives=list((root/'_backup').glob('PromoteToKing_LongLife_*.tar.gz'))
        assert len(archives)==1
        listing=subprocess.check_output(['tar','-tzf',str(archives[0])],text=True)
        assert 'data/tournaments/tournaments.json' in listing and 'data/live-ranks/uploads/a.csv' in listing and 'logs/scheduled-tasks/a.jsonl' in listing
        assert 'data/tournaments/cache/cache.json' not in listing

def test_seed_installer_and_five_job_cron_staging_contract():
    install=text('install-miac-seed-v2.9.18.sh')
    assert "EXPECTED_ARCHIVE_SHA='fbb0ad58132a65836e2a2c8c10f9f952dc727f41a668671cba69dc822f1b4310'" in install
    assert 'data/miac/seed' in install and 'runtime identity state was not modified' in install
    cron=text('reset-install-cron-v2.9.18.sh')
    assert 'weekly-backup-v2.9.18.sh' in cron and '37 3 * * 0' in cron
    assert 'install-miac-seed-v2.9.18.sh' in cron
    assert 'COUNT" -eq 4 && "$WEEKLY_COUNT" -eq 1' in cron
