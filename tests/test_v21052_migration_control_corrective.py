from __future__ import annotations
import json, re
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
TARGET='2.10.6'

def test_release_identity_and_browser_site_config():
    assert (ROOT/'VERSION').read_text().strip()==TARGET
    assert (ROOT/'MIGRATION_VERSION').read_text().strip()==TARGET
    manifest=json.loads((ROOT/'site-manifest.json').read_text())
    assert manifest['version']==TARGET
    assert manifest['releaseVersion']==TARGET
    assert manifest['migrationVersion']==TARGET
    cfg=(ROOT/'assets/js/site-config.js').read_text()
    assert f'version: "{TARGET}"' in cfg
    assert 'builtAt: "2026-08-19T06:13:48Z"' in cfg

def test_every_active_page_uses_one_cache_generation():
    pages=sorted(list(ROOT.glob('*.html'))+list(ROOT.glob('*.htm')))
    assert pages
    for page in pages:
        text=page.read_text(encoding='utf-8')
        versions=set(re.findall(r'\\?v=(2\\.10\\.[0-9.]+)',text))
        assert not versions or versions=={TARGET}, (page.name, sorted(versions))
    cfg=(ROOT/'assets/js/site-config.js').read_text(encoding='utf-8')
    versions=set(re.findall(r'\\?v=(2\\.10\\.[0-9.]+)',cfg))
    assert not versions or versions=={TARGET}, sorted(versions)

def test_green_runtime_release_marker_matches_package():
    cfg=(ROOT/'server/team-points-green/src/GreenConfig.php').read_text()
    api=(ROOT/'server/team-points-green/public/api.php').read_text()
    migration=(ROOT/'assets/js/pages/team-points-migration.js').read_text()
    assert f"public const VERSION = '{TARGET}';" in cfg
    assert f"'release'=>'{TARGET}'" in api
    assert f'const HOTFIX_VERSION="{TARGET}"' in migration


def test_migration_phase_writes_are_fail_closed_and_validation_gated():
    api=(ROOT/'server/team-points-green/public/api.php').read_text()
    assert "Green validation is not ready; migration phase was not changed." in api
    assert "Could not confirm Blue maintenance; migration phase was not changed." in api
    assert "$cmp->setBluePaused(false)" in api
    assert "$state=$repo->setControl($changes)" in api
    assert api.index("$cmp->setBluePaused(false)") < api.index("$state=$repo->setControl($changes)")
    assert "'blue_comparison_available'" in api
    assert "'comparison_error'" in api
