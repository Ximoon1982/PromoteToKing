#!/usr/bin/env python3
from pathlib import Path
import json,re,sys
root=Path(__file__).resolve().parents[1]

def req(cond,msg):
    if not cond: raise AssertionError(msg)
    print('PASS - '+msg)

req((root/'VERSION').read_text().strip()=='2.10.8','VERSION 2.10.8')
req((root/'MIGRATION_VERSION').read_text().strip()=='2.10.8','MIGRATION_VERSION 2.10.8')
manifest=json.loads((root/'site-manifest.json').read_text())
req(manifest.get('version')=='2.10.8','manifest version 2.10.8')
req(manifest.get('schemaVersion')==8,'manifest schema restored to 8')
site=(root/'assets/js/site-config.js').read_text()
req('version: "2.10.8"' in site,'site-config version 2.10.8')
# Every root app page that loads site-config must force the same current cache key.
bad=[]; seen=0
for p in list(root.glob('*.html'))+list(root.glob('*.htm')):
    text=p.read_text(errors='ignore')
    if 'assets/js/site-config.js' not in text: continue
    if p.name.startswith(('RELEASE_NOTES_','INSTALL_','MIGRATION_','HOTFIX_','AUDIT_')): continue
    seen+=1
    if 'assets/js/site-config.js?v=2.10.8' not in text: bad.append(p.name)
req(seen==21 and not bad,'all active site-config cache keys are 2.10.8')
diag=(root/'assets/js/pages/runtime-diagnostics.js').read_text()
req('Loaded asset cache markers include' not in diag,'diagnostics no longer flags intentional mixed asset generations')
req('Loaded site-config cache markers include' in diag,'diagnostics checks site-config cache generation')
health=(root/'InsightsHealth.html').read_text()
req('runtime-diagnostics.js?v=2.10.8' in health,'diagnostics script cache-busted')
req('InsightsHealth.html?embedded=1&release=2.10.8' in (root/'ui-v2.html').read_text() and 'InsightsHealth.html?embedded=1&release=2.10.8' in (root/'assets/js/pages/dashboard-v2.js').read_text(),'diagnostics iframe route cache-busted')
coord=(root/'server/team-points/src/CronMaintenanceCoordinator.php').read_text()
req("runClass('achievements', 15.0, 11.0" in coord,'achievement scheduler grants compatible budget')
builder=(root/'server/team-points/src/AnalyticsBuilder.php').read_text()
req('logic:achievement-v2108-canonical-counts' in builder,'achievement logic watermark bumped')
req("achievement_key IN ('groups-1','groups-20')" in builder,'transient v2.9.3 breadth cleanup is targeted')
repo=(root/'server/team-points/src/Repository.php').read_text()
req('private function achievementCatalogSqlList()' in repo,'authoritative catalogue SQL helper exists')
req(repo.count('achievement_key IN ({$validAchievementKeys})')==3,'all three player achievement aggregate count paths are catalogue-filtered')
req("if(!isset($catalogByKey[$key]))continue" in repo,'player profile retains current-catalogue filtering')
print('PASS - 16 v2.10.8 source gates')
