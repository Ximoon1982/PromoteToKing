from __future__ import annotations
import json, os, subprocess, tempfile, time
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def text(rel:str)->str:
    return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')

def php(code:str,*args:str)->str:
    result=subprocess.run(['php','-r',code,*args],cwd=ROOT,text=True,capture_output=True,check=True)
    return result.stdout.strip()


def test_observation_rate_limiter_is_single_file_and_old_minute_files_are_housekept():
    observe=text('server/team-points/public/observe.php')
    housekeeping=text('server/team-points/src/Housekeeping.php')
    assert "new BoundedRateLimiter($runtime.'/rate-state.json'" in observe
    assert "hash('sha256',$ip.'|'.$slot)" not in observe
    assert "'/rate-'" not in observe
    assert "'rate-*.txt'" in housekeeping


def test_bounded_rate_limiter_keeps_one_state_file_under_many_windows():
    with tempfile.TemporaryDirectory() as tmp:
        state=Path(tmp)/'rate-state.json'
        code=r'''
require $argv[1].'/server/shared/FilesystemCache.php';
require $argv[1].'/server/shared/BoundedRateLimiter.php';
$l=new \P2K\Shared\BoundedRateLimiter($argv[2],64,3600);
for($i=0;$i<5000;$i++){$r=$l->consume('ip-'.($i%80),300,60);}
echo json_encode($r);
'''
        result=json.loads(php(code,str(ROOT),str(state)))
        files=[p for p in Path(tmp).rglob('*') if p.is_file()]
        # rate state + protected .htaccess/index only; no per-minute buckets.
        assert len(files)==3, files
        assert result['subjects']<=64



def test_bounded_rate_limiter_is_atomic_across_concurrent_php_processes():
    with tempfile.TemporaryDirectory() as tmp:
        state=Path(tmp)/'rate-state.json'; worker=Path(tmp)/'worker.php'
        worker.write_text("<?php\n"
                          "require $argv[1].'/server/shared/FilesystemCache.php';\n"
                          "require $argv[1].'/server/shared/BoundedRateLimiter.php';\n"
                          "$l=new \\P2K\\Shared\\BoundedRateLimiter($argv[2],64,3600);$ok=0;\n"
                          "for($i=0;$i<100;$i++){if($l->consume('same-ip',300,60)['allowed'])$ok++;}\n"
                          "echo $ok;\n")
        procs=[subprocess.Popen(['php',str(worker),str(ROOT),str(state)],stdout=subprocess.PIPE,text=True) for _ in range(8)]
        accepted=sum(int(p.communicate(timeout=20)[0] or 0) for p in procs)
        assert accepted==300
        assert all(p.returncode==0 for p in procs)

def test_acamr_claim_store_cleans_from_shared_store_and_caps_receipts():
    store=text('server/team-points/src/AcamrClaimStore.php')
    plan=text('server/team-points/public/acamr-plan.php')
    observe=text('server/team-points/public/observe.php')
    assert 'function cleanup' in store and "ledgerPath('claims'" in store and "ledgerPath('members'" in store
    assert '$claimStore->cleanup(' in plan
    assert '$claimStore->cleanup(' in observe
    assert '$claimStore->issue(' in plan
    assert '$claimStore->verify(' in observe


def test_filesystem_cache_enforces_entry_budget_and_reads_legacy_shards():
    with tempfile.TemporaryDirectory() as tmp:
        code=r'''
require $argv[1].'/server/shared/FilesystemCache.php';
$legacy=new \P2K\Shared\FilesystemCache(['cache_dir'=>$argv[2],'cache_max_entries'=>1000,'cache_max_bytes'=>104857600,'cache_cleanup_probability_percent'=>0,'cache_shard_depth'=>2]);
$legacy->put('https://api.chess.com/pub/match/legacy',200,'{"legacy":true}',null,null,3600,'test');
$c=new \P2K\Shared\FilesystemCache(['cache_dir'=>$argv[2],'cache_max_entries'=>1000,'cache_max_bytes'=>104857600,'cache_cleanup_probability_percent'=>0,'cache_shard_depth'=>1]);
$before=$c->get('https://api.chess.com/pub/match/legacy');
$c->put('https://api.chess.com/pub/match/legacy',200,'{"legacy":false}',null,null,3600,'test');
for($i=0;$i<1100;$i++)$c->put('https://api.chess.com/pub/match/'.$i,200,'{}',null,null,3600,'test');
$r=$c->purge(10000);$s=$c->stats();echo json_encode([$r,$s,$before]);
'''
        result=json.loads(php(code,str(ROOT),tmp))
        assert result[2]['body_json']=='{"legacy":true}'
        assert result[0]['remaining_files']<=1000
        assert result[0]['max_entries']==1000
        assert result[1]['max_entries']==1000
        # One-level sharding means at most 256 first-level cache directories after purge.
        dirs=[p for p in Path(tmp).iterdir() if p.is_dir()]
        assert len(dirs)<=256


def test_match_tracking_retention_thins_old_history_and_keeps_boundaries():
    with tempfile.TemporaryDirectory() as tmp:
        root=Path(tmp); now=time.time()
        paths=[]
        for i in range(400):
            p=root/f'{i:06d}.json'; status='registration' if i<100 else ('in_progress' if i<250 else 'finished')
            p.write_text(json.dumps({'match':{'status':status}}))
            # 200-600 days old, one day apart: weekly tier should thin strongly.
            stamp=now-(600-i)*86400
            os.utime(p,(stamp,stamp)); paths.append(p)
        first,last=paths[0],paths[-1]
        code=r'''
require $argv[1].'/server/shared/MatchTrackingRetention.php';
$r=\P2K\Shared\MatchTrackingRetention::pruneMatchDirectory($argv[2],['recent_days'=>7,'dense_days'=>30,'daily_days'=>180,'hard_cap'=>256,'min_before_prune'=>32]);echo json_encode($r);
'''
        result=json.loads(php(code,str(ROOT),tmp))
        assert result['before']==400
        assert result['after']<=256
        assert first.exists() and last.exists()
        assert paths[100].exists() and paths[250].exists()  # status-transition boundaries


def test_future_backup_helper_creates_constant_object_count_and_excludes_cache():
    with tempfile.TemporaryDirectory() as tmp:
        site=Path(tmp); (site/'server/team-points/config').mkdir(parents=True); (site/'data/runtime-v280/cache/chesscom/aa').mkdir(parents=True); (site/'data/match-tracking/matches/1').mkdir(parents=True); (site/'logs').mkdir()
        (site/'server/team-points/config/config.local.php').write_text('secret')
        (site/'data/runtime-v280/acamr').mkdir(parents=True)
        for i in range(120):(site/f'data/runtime-v280/acamr/claims-{i:02x}.json').write_text('{}')
        (site/'data/runtime-v280/telemetry').mkdir(parents=True)
        for i in range(30):(site/f'data/runtime-v280/telemetry/2026-07-{(i%28)+1:02d}.jsonl').write_text('{}\n')
        for i in range(500):(site/f'data/runtime-v280/cache/chesscom/aa/{i}.json.gz').write_text('x')
        for i in range(20):(site/f'data/match-tracking/matches/1/{i}.json').write_text('{}')
        env=os.environ.copy();env['P2K_BACKUP_STAMP']='20260812T120000Z'
        subprocess.run([str(ROOT/'tools/release/p2k-state-backup.sh'),str(site),'test'],env=env,text=True,capture_output=True,check=True)
        backup=site/'_backup'; files=[p for p in backup.iterdir() if p.is_file()]
        assert len(files)==2
        archive=backup/'20260812T120000Z_test.tar.gz'
        listing=subprocess.run(['tar','-tzf',str(archive)],text=True,capture_output=True,check=True).stdout
        assert 'runtime-v280/cache' not in listing
        assert 'runtime-v280/acamr' not in listing
        assert 'runtime-v280/telemetry' not in listing
        assert 'data/match-tracking/matches/1/' in listing
        assert 'server/team-points/config/config.local.php' in listing



def test_compact_backup_restore_round_trip_and_legacy_backup_consolidation():
    with tempfile.TemporaryDirectory() as tmp:
        site=Path(tmp)/'site'; site.mkdir(); (site/'data/match-tracking').mkdir(parents=True); (site/'server/team-points/config').mkdir(parents=True)
        (site/'data/match-tracking/index.json').write_text('{"durable":true}')
        (site/'server/team-points/config/config.local.php').write_text('protected-config')
        env=os.environ.copy();env['P2K_BACKUP_STAMP']='20260812T121500Z'
        subprocess.run([str(ROOT/'tools/release/p2k-state-backup.sh'),str(site),'roundtrip'],env=env,text=True,capture_output=True,check=True)
        archive=site/'_backup/20260812T121500Z_roundtrip.tar.gz'
        restored=Path(tmp)/'restored'
        subprocess.run([str(ROOT/'tools/release/p2k-state-restore.sh'),str(archive),str(restored)],text=True,capture_output=True,check=True)
        assert (restored/'data/match-tracking/index.json').read_text()=='{"durable":true}'
        assert (restored/'server/team-points/config/config.local.php').read_text()=='protected-config'

        legacy=site/'_backup/legacy_v2912_to_v2913'; (legacy/'data/cache').mkdir(parents=True)
        for i in range(250):(legacy/f'data/cache/{i}.json').write_text('x')
        dry=subprocess.run([str(ROOT/'tools/release/p2k-consolidate-legacy-backups.sh'),str(site)],text=True,capture_output=True,check=True).stdout
        assert 'WOULD_ARCHIVE' in dry and legacy.exists()
        subprocess.run([str(ROOT/'tools/release/p2k-consolidate-legacy-backups.sh'),'--apply',str(site)],text=True,capture_output=True,check=True)
        assert not legacy.exists()
        assert (site/'_backup/legacy_v2912_to_v2913.tar.gz').is_file()
        assert (site/'_backup/legacy_v2912_to_v2913.tar.gz.sha256').is_file()

def test_storage_metrics_exposes_file_contract_and_cache_entry_contract():
    src=text('server/team-points/src/StorageMetricsService.php');cfg=text('server/team-points/config/config.example.php')
    for token in ['filesystem_max_files','file_percent','directories','max_entries','entry_percent']:
        assert token in src or token in cfg
    assert "'filesystem_max_files' => 200000" in cfg
    assert "'cache_max_entries' => 30000" in cfg


def test_filesystem_hardening_is_promoted_in_v2914_without_shipping_archives_in_tree():
    assert (ROOT/'VERSION').read_text().strip() in {'2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    assert (ROOT/'tools/release/p2k-state-backup.sh').is_file()
    assert not list(ROOT.glob('PromoteToKing*v2.9.15*.zip'))


def test_acamr_new_claims_and_member_leases_use_fixed_shard_ledgers():
    with tempfile.TemporaryDirectory() as tmp:
        code=r'''
function p2k_tp_username_key(string $u): string { return strtolower(trim($u)); }
require $argv[1].'/server/shared/FilesystemCache.php';
require $argv[1].'/server/team-points/src/AcamrClaimStore.php';
$s=new \P2K\TeamPoints\AcamrClaimStore(['runtime_dir'=>$argv[2]]);
$first='';$accepted=0;
for($i=0;$i<5000;$i++){
  if($s->claimMember(['username_key'=>'member-'.$i],1200,'actor','oauth'))$accepted++;
  $t=$s->issue('member-'.$i,[['kind'=>'stats','url'=>'https://api.chess.com/pub/player/member-'.$i.'/stats']],1200,'actor','oauth');
  if($i===0)$first=$t;
}
$v=$s->verify($first,'https://api.chess.com/pub/player/member-0/stats','stats');
echo json_encode(['accepted'=>$accepted,'verified'=>$v['verified']??false]);
'''
        result=json.loads(php(code,str(ROOT),tmp))
        acamr=Path(tmp)/'acamr'
        files=[p for p in acamr.iterdir() if p.is_file()]
        ledgers=[p for p in files if p.name.startswith(('claims-','members-'))]
        assert result=={'accepted':5000,'verified':True}
        # Fixed 256 shards for claims + 256 for members (+ protected sentinels).
        assert len(ledgers)<=512
        assert not list(acamr.glob('claim-*.json'))
        assert not list(acamr.glob('member-*.json'))
        assert not (acamr / ('member-'+('x'*64)+'.json')).exists()


def test_runtime_file_retention_bounds_elapsed_time_histories():
    with tempfile.TemporaryDirectory() as tmp:
        root=Path(tmp); now=time.time()
        telemetry=root/'telemetry'; intelligence=root/'intelligence/snapshots'; reconciliation=root/'reconciliation'
        telemetry.mkdir(parents=True); intelligence.mkdir(parents=True); reconciliation.mkdir(parents=True)
        for i in range(100):
            p=telemetry/f'{i:03d}.jsonl'; p.write_text('{}\n'); stamp=now-i*86400; os.utime(p,(stamp,stamp))
        for i in range(900):
            p=intelligence/f'{i:04d}.json'; p.write_text('{}'); stamp=now-i*86400; os.utime(p,(stamp,stamp))
        for i in range(300):
            d=reconciliation/f'batch-{i:03d}'; d.mkdir(); (d/'batch.json').write_text('{}'); stamp=now-i*86400; os.utime(d/'batch.json',(stamp,stamp)); os.utime(d,(stamp,stamp))
        code=r'''
require $argv[1].'/server/shared/FilesystemRetention.php';
$a=\P2K\Shared\FilesystemRetention::pruneFiles($argv[2].'/telemetry','*.jsonl',30,35);
$b=\P2K\Shared\FilesystemRetention::pruneFiles($argv[2].'/intelligence/snapshots','*.json',730,730);
$c=\P2K\Shared\FilesystemRetention::pruneDirectories($argv[2].'/reconciliation',365,250);
echo json_encode([$a,$b,$c]);
'''
        result=json.loads(php(code,str(ROOT),tmp))
        assert len(list(telemetry.glob('*.jsonl')))<=31
        assert len(list(intelligence.glob('*.json')))<=730
        assert len([p for p in reconciliation.iterdir() if p.is_dir()])<=250
        assert result[0]['removed_files']>0 and result[1]['removed_files']>0 and result[2]['removed_directories']>0


def test_housekeeping_has_bounded_cleanup_for_all_runtime_file_families():
    src=text('server/team-points/src/Housekeeping.php'); cfg=text('server/team-points/config/config.example.php')
    for token in [
        'telemetry_retention_days','telemetry_max_files','reconciliation_retention_days','reconciliation_max_batches',
        'intelligence_snapshot_retention_days','intelligence_snapshot_max_files','acamr_member_lease_retention_seconds'
    ]:
        assert token in src or token in cfg
    assert "->purge(50000)" in src
    assert "FilesystemRetention::pruneFiles($runtime.'/telemetry'" in src
    assert "FilesystemRetention::pruneDirectories($runtime.'/reconciliation'" in src
    assert "FilesystemRetention::pruneFiles($runtime.'/intelligence/snapshots'" in src


def test_acamr_member_lease_is_atomic_across_concurrent_planners():
    with tempfile.TemporaryDirectory() as tmp:
        worker=Path(tmp)/'claim-member.php'
        worker.write_text("<?php\n"
                          "function p2k_tp_username_key(string $u): string { return strtolower(trim($u)); }\n"
                          "require $argv[1].'/server/shared/FilesystemCache.php';\n"
                          "require $argv[1].'/server/team-points/src/AcamrClaimStore.php';\n"
                          "$s=new \\P2K\\TeamPoints\\AcamrClaimStore(['runtime_dir'=>$argv[2]]);\n"
                          "echo $s->claimMember(['username_key'=>'same-member'],1200,'actor','oauth')?'1':'0';\n")
        procs=[subprocess.Popen(['php',str(worker),str(ROOT),tmp],stdout=subprocess.PIPE,text=True) for _ in range(12)]
        accepted=sum(int(p.communicate(timeout=20)[0] or 0) for p in procs)
        assert accepted==1
        assert all(p.returncode==0 for p in procs)


def test_client_continuous_refresh_shares_bounded_acamr_ledgers():
    src=text('server/control/public/api.php')
    assert 'new AcamrClaimStore($storage)' in src
    assert '$claimStore->claimMember(' in src and '$claimStore->issue(' in src
    # The continuous-refresh planner must never regress to one file per member/token.
    block=src[src.index("if ($action === 'client-refresh-plan')"):src.index("if ($action === 'logs')")]
    assert "'/member-' . hash('sha256'" not in block
    assert "'/claim-' . hash('sha256'" not in block


def test_housekeeping_bounds_low_volume_daily_and_manual_file_families():
    src=text('server/team-points/src/Housekeeping.php'); cfg=text('server/team-points/config/config.example.php')
    expected=[
        'scheduled_task_file_retention_days','scheduled_task_file_max_files',
        'traffic_analytics_retention_days','traffic_analytics_max_files',
        'fresh_init_nonce_retention_days','fresh_init_nonce_max_files',
        'fresh_init_coverage_retention_days','fresh_init_coverage_max_runs',
        'tournament_backup_retention_days','tournament_backup_max_files',
    ]
    for token in expected:
        assert token in cfg and token in src
    for path in ["'/logs/scheduled-tasks'","'/data/traffic/aggregates'","'/fresh-init/nonces'","'/fresh-init/coverage'","'/data/tournaments/backups'"]:
        assert path in src


def test_package_validator_rejects_python_test_cache_artifacts():
    validator=text('tests/validate_package.py')
    assert 'Generated Python test-cache artifacts must not ship' in validator
    assert '"__pycache__" in p.parts' in validator and '".pytest_cache" in p.parts' in validator


def test_live_ranks_distinct_upload_growth_has_non_destructive_hard_cap():
    src=text('server/team-points/src/LiveRanksService.php'); cfg=text('server/team-points/config/config.example.php')
    assert "'live_ranks_max_upload_files' => 5000" in cfg
    assert 'LIVE_RANKS_FILE_LIMIT' in src and 'SELECT COUNT(*) FROM p2k_lr_files WHERE club_slug=?' in src
    # Replacement is evaluated before the cap, so an existing filename stays replaceable at the limit.
    assert src.index('if (!is_array($existingRow))') < src.index("$stored = bin2hex(random_bytes(12)) . '.csv'")
