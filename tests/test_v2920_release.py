from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding="utf-8",errors="ignore")

def test_v2920_release_identity_and_schema():
    assert text("VERSION").strip() in {"2.9.20","2.9.21","2.9.22","2.9.22.1","2.9.22.2"}
    assert any(f'version: "{v}"' in text("assets/js/site-config.js") for v in ('2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    assert any(f'RUNTIME_VERSION = "{v}"' in text("assets/js/shared/api-client.js") for v in ('2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    assert any(f"VERSION = '{v}'" in text("server/team-points/src/OAuthSession.php") for v in ('2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    repo=text("server/team-points/src/Repository.php")
    assert any(f'CORE_SCHEMA_VERSION = {v};' in repo for v in (14,15)) and 'ANALYTICS_SCHEMA_VERSION = 7;' in repo

def test_v2920_scripts_and_cache_identity():
    for f in ["cron-dispatch-v2.9.20.sh","reset-install-cron-v2.9.20.sh","install-oauth-v2.9.20.sh","install-miac-seed-v2.9.20.sh","weekly-backup-v2.9.20.sh","update-v2.9.19-to-v2.9.20.sh"]:
        assert (ROOT/f).is_file(),f
    assert any(f'?v={v}' in text('ui-v2.html') and f'?v={v}' in text('index.html') for v in ('2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    assert any(f'{v}-acsr-v1' in text('assets/js/shared/active-convergence-refresh.js') for v in ('2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'))
    assert any(f'dashboard-match-board-hydration.js?v={v}' in text('ui-v2.html') for v in ('2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'))

def test_v2920_updater_from_exact_predecessor():
    u=text('update-v2.9.19-to-v2.9.20.sh')
    assert 'Expected verified production VERSION 2.9.19' in u
    assert 'Promote to King is already VERSION 2.9.20' in u
    assert 'PromoteToKing_v2.9.19_to_v2.9.20_INCREMENTAL.zip' in u
    assert 'INCREMENTAL_MANIFEST_v2.9.19_to_v2.9.20.json' in u

def test_v2920_scope_documented():
    n=text('RELEASE_NOTES_v2.9.20.md')
    for marker in ['MCA OAuth Rate Capture Fix (MORCF)','Dashboard Match Board Hydration Fix (DMBHF)','ACSR Canonical Drain Mode (ACDM)','25-row page size']:
        assert marker in n
    assert 'No database reset, reseed or schema migration' in n
