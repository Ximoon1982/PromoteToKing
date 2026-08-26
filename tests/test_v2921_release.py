from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8',errors='ignore')

def test_v2921_release_identity_and_schema():
    assert text('VERSION').strip() in {'2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    assert any(f'version: "{v}"' in text('assets/js/site-config.js') for v in ('2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    assert any(f'RUNTIME_VERSION = "{v}"' in text('assets/js/shared/api-client.js') for v in ('2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    assert any(f"VERSION = '{v}'" in text('server/team-points/src/OAuthSession.php') for v in ('2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    repo=text('server/team-points/src/Repository.php')
    assert any(f'CORE_SCHEMA_VERSION = {v};' in repo for v in (14,15)) and 'ANALYTICS_SCHEMA_VERSION = 7;' in repo

def test_v2921_mca_timeout_budget_scope():
    js=text('assets/js/pages/team-points-features.js')
    php=text('server/team-points/public/live-ranks-admin.php')
    assert 'MCA_REBUILD_TIMEOUT_MS = 110_000' in js
    assert 'MCA_PROFILE_STEP_TIMEOUT_MS = 75_000' in js
    assert 'MCA_PROFILE_BATCH_CAP = 32' in js
    assert '$boundedLimit = min($requestedLimit, 32, $pacedLimit)' in php
    assert '$service->processProfileBatch($boundedLimit, $fetcher)' in php

def test_v2921_runtime_helpers_and_cache_bust():
    for f in ['cron-dispatch-v2.9.21.sh','reset-install-cron-v2.9.21.sh','install-oauth-v2.9.21.sh','install-miac-seed-v2.9.21.sh','weekly-backup-v2.9.21.sh']:
        assert (ROOT/f).is_file(),f
    assert any(f'?v={v}' in text('TeamPointsAdmin.html') for v in ('2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    assert any(f'{v}-acsr-v1' in text('assets/js/shared/active-convergence-refresh.js') for v in ('2.9.21','2.9.22','2.9.22.1','2.9.22.2'))

def test_v2921_scope_documented():
    notes=text('RELEASE_NOTES_v2.9.21.md')
    assert 'MCA Processing Request Budget Fix (MPRBF)' in notes
    assert 'no re-upload is required' in notes.lower()
    assert 'No database reset, reseed or schema migration' in notes
