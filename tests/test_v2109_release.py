from pathlib import Path
import json

ROOT=Path(__file__).resolve().parents[1]

def test_release_identity_and_cache_convergence():
    assert (ROOT/'VERSION').read_text().strip()=='2.10.9'
    assert (ROOT/'MIGRATION_VERSION').read_text().strip()=='2.10.9'
    manifest=json.loads((ROOT/'site-manifest.json').read_text())
    assert manifest['version']=='2.10.9'
    assert manifest['schemaVersion']==8
    assert manifest['release']['databaseSchemaChange'] is True
    assert 'version: "2.10.9"' in (ROOT/'assets/js/site-config.js').read_text()
    seen=[]; bad=[]
    for p in list(ROOT.glob('*.html'))+list(ROOT.glob('*.htm')):
        text=p.read_text(errors='ignore')
        if 'assets/js/site-config.js' not in text: continue
        if p.name.startswith(('RELEASE_NOTES_','INSTALL_','MIGRATION_','HOTFIX_','AUDIT_')): continue
        seen.append(p.name)
        if 'assets/js/site-config.js?v=2.10.9' not in text: bad.append(p.name)
    assert len(seen)==21
    assert bad==[]

def test_v2108_achievement_and_scheduler_invariants_are_retained():
    coord=(ROOT/'server/team-points/src/CronMaintenanceCoordinator.php').read_text()
    assert "runClass('achievements', 15.0, 11.0" in coord
    builder=(ROOT/'server/team-points/src/AnalyticsBuilder.php').read_text()
    assert 'logic:achievement-v2108-canonical-counts' in builder
    assert "achievement_key IN ('groups-1','groups-20')" in builder
    repo=(ROOT/'server/team-points/src/Repository.php').read_text()
    assert 'private function achievementCatalogSqlList()' in repo
    assert repo.count('achievement_key IN ({$validAchievementKeys})')==3
    assert "if(!isset($catalogByKey[$key]))continue" in repo

def test_runtime_diagnostics_tracks_current_site_config_generation():
    diag=(ROOT/'assets/js/pages/runtime-diagnostics.js').read_text()
    assert 'Loaded asset cache markers include' not in diag
    assert 'Loaded site-config cache markers include' in diag
    health=(ROOT/'InsightsHealth.html').read_text()
    assert 'runtime-diagnostics.js?v=2.10.9' in health
    assert 'InsightsHealth.html?embedded=1&release=2.10.9' in (ROOT/'ui-v2.html').read_text()
    assert 'InsightsHealth.html?embedded=1&release=2.10.9' in (ROOT/'assets/js/pages/dashboard-v2.js').read_text()

def test_arena_release_assets_do_not_include_private_fixture():
    assert not (ROOT/'arena.html').exists()
