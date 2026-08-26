from pathlib import Path
import re
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8',errors='ignore')

def test_v29222_identity_and_schema_contract():
    assert text('VERSION').strip()=='2.9.22.2'
    repo=text('server/team-points/src/Repository.php')
    assert 'CORE_SCHEMA_VERSION = 15;' in repo
    assert 'ANALYTICS_SCHEMA_VERSION = 7;' in repo
    assert 'version: "2.9.22.2"' in text('assets/js/site-config.js')

def test_oauth_transport_cap_is_environment_capacity_not_batch_size():
    php=text('server/team-points/src/OAuthSession.php')
    assert 'private static function runtimeOpenFileCap(): int' in php
    assert '$cap=256;' in php
    assert 'runtimeOpenFileCap(int $workload)' not in php
    assert 'min(256,$workload)' not in php
    assert '$transportCap=self::runtimeOpenFileCap();' in php
    assert '$requested=max(1,min(count($requests),$transportCap,max(1,$requestedConcurrency)));' in php
    assert "private const VERSION = '2.9.22.2';" in php

def test_oauth_process_priority_treats_worker_count_as_logical_feeder_depth():
    js=text('assets/js/shared/api-client.js')
    assert 'const RUNTIME_VERSION = "2.9.22.2";' in js
    assert 'const OAUTH_INITIAL_TARGET = 8;' in js
    assert 'p2k-oauth-gateway-tuning-v4' in js
    assert 'const concurrency = oauthBearerMode ? requestedConcurrency : Math.min(requestedConcurrency, transportConcurrency);' in js
    assert 'const concurrency = oauthBearerMode ? transportConcurrency' not in js

def test_reconstruction_requests_deep_oauth_feeder():
    js=text('assets/js/pages/fresh-points-reconstruction.js')
    assert 'RECONSTRUCTION_FEEDER_CONCURRENCY = 256' in js
    assert 'concurrency:RECONSTRUCTION_FEEDER_CONCURRENCY' in js
    assert 'P2K_API_CLIENT.processPriority' in js

def test_runtime_html_cache_busters_are_consistent():
    for p in ROOT.glob('*.html'):
        s=p.read_text(encoding='utf-8',errors='ignore')
        assert not re.search(r'\?v=2\.9\.22\.1(?![0-9])', s), p
        assert not re.search(r'\?v=2\.9\.22(?!\.)',s), p
    assert 'traffic-analytics.js?v=2.9.22.2' in text('assets/js/site-config.js')
