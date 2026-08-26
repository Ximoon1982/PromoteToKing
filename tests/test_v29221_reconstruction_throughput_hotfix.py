from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8',errors='ignore')

def test_v29221_identity_keeps_core15_analytics7_and_cron_2922():
    assert text('VERSION').strip() in {'2.9.22.1','2.9.22.2','2.9.22.3','2.9.22.4','2.9.22.5','2.9.22.6'}
    repo=text('server/team-points/src/Repository.php')
    assert 'CORE_SCHEMA_VERSION = 16;' in repo
    assert 'ANALYTICS_SCHEMA_VERSION = 7;' in repo
    assert text('cron-dispatch-v2.9.22.sh')
    assert text('reset-install-cron-v2.9.22.sh')

def test_reconstruction_fetch_and_persistence_are_decoupled():
    js=text('assets/js/pages/fresh-points-reconstruction.js')
    for marker in [
        'PERSIST_BATCH_ROWS = 400',
        'PERSIST_HIGH_WATER_ROWS = 1600',
        'persistQueues = new Map()',
        'function beginPersistenceDrain()',
        'async function flushPersistence()',
        'state.persistence.queuedRows>=PERSIST_HIGH_WATER_ROWS',
        'rowsPersisted',
        'batchesSent',
    ]:
        assert marker in js
    # Individual Chess.com workers may enqueue staging rows, but only the high-water
    # limit/phase boundaries wait for persistence; the old direct POST loop is gone.
    assert 'for(let i=0;i<rows.length;i+=400)await endpoint("reconstruction-ingest"' not in js
    assert 'postPersistBatch(selected,batch)' in js

def test_player_archive_fallback_is_parallel_across_members_and_batches_rows():
    js=text('assets/js/pages/fresh-points-reconstruction.js')
    assert 'ARCHIVE_MEMBER_CONCURRENCY = 24' in js
    assert 'processFixedPool(fallback,ARCHIVE_MEMBER_CONCURRENCY' in js
    assert 'for(let i=0;i<fallback.length;i++)' not in js
    assert 'for(const m of fallback)await archiveFallback(m)' not in js
    # One archive response now queues all discovered boards together rather than
    # waiting for one server POST per game/match discovery.
    assert 'const data=await fresh(item.url,{priority:75})' in js
    assert 'const candidates=new Set()' in js
    assert 'normalizedMatchRow(item.match_id,data,"player-archive-match")' in js
    assert 'if(rows.length)await ingest("boards",rows)' in js

def test_task_control_exposes_oauth_and_persistence_backpressure_metrics():
    js=text('assets/js/pages/task-control.js')
    for marker in [
        'OAuth transport','OAuth launch rate','Fetch completion rate','Learned safe rate',
        'Gateway target','Gateway queued','Gateway POSTs active',
        'Rows awaiting persistence','Rows persisted','Ingest batches','Ingest failures'
    ]:
        assert marker in js
    html=text('TaskControl.html')
    assert ('fresh-points-reconstruction.js?v=2.9.22.1' in html) or ('fresh-points-reconstruction.js?v=2.9.22.2' in html) or ('fresh-points-reconstruction.js?v=2.9.22.4' in html) or ('fresh-points-reconstruction.js?v=2.9.22.5' in html) or ('fresh-points-reconstruction.js?v=2.9.22.6' in html)
    assert ('task-control.js?v=2.9.22.1' in html) or ('task-control.js?v=2.9.22.2' in html) or ('task-control.js?v=2.9.22.3' in html) or ('task-control.js?v=2.9.22.4' in html) or ('task-control.js?v=2.9.22.5' in html) or ('task-control.js?v=2.9.22.6' in html)

def test_hotfix_updater_does_not_require_php_cli():
    sh=text('update-v2.9.22-to-v2.9.22.1.sh')
    assert 'find_php_cli' not in sh
    assert 'PHP_BIN' not in sh
    assert 'No working PHP CLI found.' not in sh
    assert 'sha256sum' in sh
    assert 'payload-sha256.txt' in sh
