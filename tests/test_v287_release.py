from pathlib import Path
import json, os

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')


def test_v287_release_identity_docs_and_schema():
    # Historical v2.8.7 release artifacts remain shipped for upgrade provenance.
    assert tuple(map(int,text('VERSION').strip().split('.'))) >= (2,8,7)
    for name in ['RELEASE_NOTES_v2.8.7.md','INSTALL_v2.8.7.md','MIGRATION_v2.8.7.md','CRON_SETUP_v2.8.7.md','ARCHITECTURE_v2.8.7.md']:
        assert (ROOT/name).is_file()
    migration=text('MIGRATION_v2.8.7.md').lower()
    assert 'no sql migration' in migration and 'core remains schema **5**' in migration and 'analytics remains schema **5**' in migration


def test_v287_acamr_release_contract_is_documented_and_shipped():
    notes=text('RELEASE_NOTES_v2.8.7.md')
    for phrase in ['Real OAuth authentication', 'Simulated authentication', 'Club Points and Member Points', 'tournaments', 'match-registration']:
        assert phrase in notes
    assert (ROOT/'assets/js/shared/authenticated-member-refresh.js').is_file()
    assert (ROOT/'server/team-points/public/acamr-plan.php').is_file()
    assert (ROOT/'tests/test_next_release_acamr.py').is_file()


def test_v287_argumentless_cron_helper_and_cadence():
    script=ROOT/'reset-install-cron-v2.8.7.sh'
    assert script.is_file() and os.access(script,os.X_OK)
    src=text('reset-install-cron-v2.8.7.sh')
    assert '[[ $# -ne 0 ]]' in src
    assert 'config.local.php' in src and 'data/server-config.json' in src
    for token in ['*/5 * * * *','2-59/10 * * * *','7,37 * * * *','17 * * * *']:
        assert token in src and token in text('CRON_SETUP_v2.8.7.md')


def test_v287_manifest_contract():
    manifest=json.loads(text('site-manifest.json'))
    assert tuple(map(int,str(manifest.get('version','0.0.0')).split('.'))) >= (2,8,7)
    feature=manifest.get('features',{}).get('v287AuthenticatedClientAssistedMemberRefresh',{})
    assert feature.get('realOAuthAlwaysOn') is True
    assert feature.get('simulatedRequiresOAuthFlag') is True
    assert feature.get('clubAndMemberPoints') is True
    assert feature.get('tournaments') is False
    assert feature.get('matchRegistration') is False
    for rel in ['assets/js/shared/authenticated-member-refresh.js','server/team-points/public/acamr-plan.php']:
        assert rel in manifest.get('files',[])
