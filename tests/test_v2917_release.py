from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')
def test_v2917_identity_and_scope():
    assert text('VERSION').strip() in {'2.9.17','2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    assert any(f'RUNTIME_VERSION = "{v}"' in text('assets/js/shared/api-client.js') for v in ['2.9.17','2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'])
    assert 'p2k-oauth-gateway-tuning-v3' in text('assets/js/shared/api-client.js') or 'p2k-oauth-gateway-tuning-v4' in text('assets/js/shared/api-client.js')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in text('server/team-points/src/Repository.php') for v in [13,14,15])
    assert any(f'ANALYTICS_SCHEMA_VERSION = {v}' in text('server/team-points/src/Repository.php') for v in [6,7])
    assert 'absoluteDeadlineAt' in text('server/team-points/src/Worker.php')
    assert 'ingestAttestedOAuth' in text('server/shared/SharedChessGateway.php')
    assert 'matchReadyForAuthoritativeFinalization' in text('server/team-points/src/Repository.php')
def test_v2917_operational_scripts_target_only_2917_from_2916():
    u=text('update-v2.9.16-to-v2.9.17.sh')
    assert 'CURRENT" == "2.9.17"' in u and 'CURRENT" != "2.9.16"' in u
    assert 'INCREMENTAL_MANIFEST_v2.9.16_to_v2.9.17.json' in u
    c=text('reset-install-cron-v2.9.17.sh')
    assert c.count('cron-dispatch-v2.9.17.sh')>=2 and '# BEGIN PROMOTE TO KING v2.9.17' in c
