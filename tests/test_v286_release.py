from pathlib import Path
import json, os, subprocess
ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')

def test_release_identity_schema_and_docs():
    assert text('VERSION').strip() in {'2.8.6','2.8.7','2.8.8','2.8.8.1', '2.8.8.2', '2.8.8.3','2.8.9','2.8.10','2.8.11','2.9.0','2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    repo=text('server/team-points/src/Repository.php')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [6,7,8,9,10,11,12,13,14,15])
    assert any(f'ANALYTICS_SCHEMA_VERSION = {v}' in repo for v in [5,6,7])
    for name in ['RELEASE_NOTES_v2.8.6.md','INSTALL_v2.8.6.md','MIGRATION_v2.8.6.md','CRON_SETUP_v2.8.6.md','ARCHITECTURE_v2.8.6.md']:
        assert (ROOT/name).is_file()
    assert 'No SQL migration is required' in text('MIGRATION_v2.8.6.md')

def test_cron_schedule_unchanged_and_v286_reset_script_present():
    cron=text('CRON_SETUP_v2.8.6.md'); script=ROOT/'reset-install-cron-v2.8.6.sh'
    assert script.is_file() and os.access(script,os.X_OK)
    for token in ['*/5 * * * *','2-59/10 * * * *','7,37 * * * *','17 * * * *']:
        assert token in cron
    assert 'cron-club.php' in text('reset-install-cron-v2.8.6.sh') and 'cron-player.php' in text('reset-install-cron-v2.8.6.sh')

def test_manifest_declares_progressive_public_ux():
    manifest=json.loads(text('site-manifest.json'))
    assert manifest.get('version') in {'2.8.6','2.8.7','2.8.8','2.8.8.1', '2.8.8.2', '2.8.8.3','2.8.9','2.8.10','2.8.11','2.9.0','2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'} and manifest.get('releaseVersion')==manifest.get('version')
    feature=manifest.get('features',{}).get('v286ProgressivePublicUX',{})
    assert feature.get('dashboardAutomaticSecondWave') is True
    assert feature.get('viewportInsights') is True
    assert feature.get('serverPagedHallAndTournaments') is True
    for rel in ['assets/js/shared/progressive-loader.js','assets/js/pages/dashboard-insights-charts.js','server/tournaments/public/browse.php','server/team-points/public/hall-search.php']:
        assert rel in manifest.get('files',[])
