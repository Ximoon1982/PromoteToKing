from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8',errors='ignore')
def test_release_identity_and_schema_contract():
    assert text('VERSION').strip() in {'2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    assert any(f'version: "{v}"' in text('assets/js/site-config.js') for v in ('2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    assert any(f'RUNTIME_VERSION = "{v}"' in text('assets/js/shared/api-client.js') for v in ('2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    assert any(f"VERSION = '{v}'" in text('server/team-points/src/OAuthSession.php') for v in ('2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    repo=text('server/team-points/src/Repository.php')
    assert any(f'CORE_SCHEMA_VERSION = {v};' in repo for v in (14,15)) and 'ANALYTICS_SCHEMA_VERSION = 7;' in repo
    assert 'core-migration-v2.9.18.sql' in repo and 'analytics-migration-v2.9.18.sql' in repo

def test_runtime_cache_markers_and_oauth_tuning_preserved():
    assert any(f'?v={v}' in text('index.html') for v in ('2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    assert 'p2k-oauth-gateway-tuning-v3' in text('assets/js/shared/api-client.js') or 'p2k-oauth-gateway-tuning-v4' in text('assets/js/shared/api-client.js')
    assert any(f'{v}-acsr-v1' in text('assets/js/shared/active-convergence-refresh.js') for v in ('2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'))

def test_release_scripts_and_schedule_contract():
    for name in ['cron-dispatch-v2.9.19.sh','reset-install-cron-v2.9.19.sh','install-oauth-v2.9.19.sh','install-miac-seed-v2.9.19.sh','weekly-backup-v2.9.19.sh','update-v2.9.18-to-v2.9.19.sh']:
        assert (ROOT/name).is_file(),name
    cron=text('reset-install-cron-v2.9.19.sh')
    assert '*/5 * * * *' in cron and '2-59/10 * * * *' in cron and '4-59/10 * * * *' in cron and '17 * * * *' in cron
    assert '37 3 * * 0' in cron
    assert "grep -qx 'SHELL=/bin/bash'" in cron and "grep -qx 'PATH=/usr/local/bin:/usr/bin:/bin'" in cron
    updater=text('update-v2.9.18-to-v2.9.19.sh')
    assert 'Expected verified production VERSION 2.9.18' in updater
    assert 'Promote to King is already VERSION 2.9.19' in updater

def test_release_scope_docs_present():
    notes=text('RELEASE_NOTES_v2.9.19.md')
    assert 'Identity Propagation & Derived-data Refresh Fix (IPDR)' in notes
    assert 'Most earned' in notes and 'Shared physical Daily-board evidence' in notes
    assert 'no database schema migration' in text('MIGRATION_v2.9.19.md').lower()
