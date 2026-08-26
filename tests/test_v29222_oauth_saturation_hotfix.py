from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8',errors='ignore')

def test_v29222_identity_and_schema_contract():
    assert text('VERSION').strip() in {'2.9.22.2','2.9.22.3','2.9.22.4','2.9.22.5','2.9.22.6'}
    repo=text('server/team-points/src/Repository.php')
    assert 'CORE_SCHEMA_VERSION = 15;' in repo
    assert 'ANALYTICS_SCHEMA_VERSION = 7;' in repo

def test_transport_capacity_is_not_batch_size():
    php=text('server/team-points/src/OAuthSession.php')
    assert 'runtimeOpenFileCap(int $workload)' not in php
    assert 'runtimeOpenFileCap(count($requests))' not in php
    assert 'private static function runtimeOpenFileCap(): int' in php
    assert "'transport_capacity'=>$transportCap" in php
    assert "'batch_size'=>count($requests)" in php

def test_oauth_feeder_is_deep_and_independent_of_physical_cap():
    js=text('assets/js/shared/api-client.js')
    assert any(v in js for v in ['const RUNTIME_VERSION = "2.9.22.2";','const RUNTIME_VERSION = "2.9.22.3";','const RUNTIME_VERSION = "2.9.22.4";','const RUNTIME_VERSION = "2.9.22.5";'])
    assert 'const OAUTH_LOGICAL_CONCURRENCY = 256;' in js
    assert 'const OAUTH_INITIAL_TARGET = 8;' in js
    assert 'p2k-oauth-gateway-tuning-v4' in js
    block=js[js.index('function effectiveBatchConcurrency()'):js.index('function jitter(', js.index('function effectiveBatchConcurrency()'))]
    assert 'return OAUTH_LOGICAL_CONCURRENCY;' in block
    assert 'Math.min(OAUTH_LOGICAL_CONCURRENCY, oauthGatewayMax)' not in block
    assert 'batch?.transport_capacity' in js
    assert 'legacyCap > oauthGatewayMax' in js
    assert 'oauthLogicalFeederConcurrency: OAUTH_LOGICAL_CONCURRENCY' in js

def test_reconstruction_keeps_gateway_supplied_without_stealing_p0_lane():
    js=text('assets/js/pages/fresh-points-reconstruction.js')
    assert 'RECONSTRUCTION_FEEDER_CONCURRENCY = 256' in js
    assert 'concurrency:RECONSTRUCTION_FEEDER_CONCURRENCY' in js
    assert 'trafficClass:"background"' in js
    assert 'PERSIST_FLUSH_DELAY_MS = 1000' in js
    assert 'schedulePersistence()' in js
    assert 'queuedPersistenceRows()>=PERSIST_BATCH_ROWS' in js
    assert 'logicalFeeder' in js and 'transportCapacity' in js

def test_task_control_exposes_saturation_metrics():
    js=text('assets/js/pages/task-control.js')
    for marker in ['Gateway target / POST','Logical feeder','Transport capacity','Gateway queued','Gateway POSTs active','Fetch completion rate']:
        assert marker in js

def test_all_current_html_cache_markers_are_current():
    bad=[]
    for p in ROOT.rglob('*.html'):
        s=p.read_text(encoding='utf-8',errors='ignore')
        if '?v=2.9.22.1' in s or '?v=2.9.22"' in s or '?v=2.9.22\'' in s:
            bad.append(str(p.relative_to(ROOT)))
    assert not bad, bad
    assert any(v in text('TaskControl.html') for v in ['fresh-points-reconstruction.js?v=2.9.22.2','fresh-points-reconstruction.js?v=2.9.22.3','fresh-points-reconstruction.js?v=2.9.22.4','fresh-points-reconstruction.js?v=2.9.22.5'])
