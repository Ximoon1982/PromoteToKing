from pathlib import Path
import json,re

ROOT=Path(__file__).resolve().parents[1]
def text(path): return (ROOT/path).read_text(encoding='utf-8')

def test_release_identity_and_all_html_cache_markers_are_one_generation():
    version=text('VERSION').strip()
    assert version in {'2.9.22.5','2.9.22.6','2.9.22.7','2.9.22.8','2.9.22.9','2.9.22.10','2.10.3'}
    cfg=text('assets/js/site-config.js')
    assert f'version: "{version}"' in cfg
    manifest=json.loads(text('site-manifest.json'))
    assert manifest['version']==version
    bad=[]; seen=0
    for p in list(ROOT.rglob('*.html'))+list(ROOT.rglob('*.htm')):
        x=p.read_text(encoding='utf-8',errors='ignore')
        for m in re.finditer(r'(?:\?|&)v=([^"\'< >\s&]+)'.replace(' ',''),x):
            seen+=1
            if m.group(1)!=version: bad.append((str(p.relative_to(ROOT)),m.group(1)))
    assert seen>250
    assert bad==[]

def test_task_control_status_is_lightweight_and_details_are_lazy():
    php=text('server/control/public/api.php')
    start=php.index("if ($action === 'status')")
    end=php.index("if ($action === 'task-detail')")
    status=php[start:end]
    assert 'p2k_control_work_details' not in status
    assert "['summary'=>[], 'deferred'=>true]" in status
    assert "'reconstruction'=>null" in status
    detail=php[end:php.index("if ($action === 'client-refresh-worker-pulse')")]
    assert 'p2k_control_work_details($taskKey' in detail
    js=text('assets/js/pages/task-control.js')
    assert 'SERVER_TASK_FALLBACKS' in js
    assert 'loadSelectedTaskDetail' in js
    assert 'timeoutMs: 15000' in js
    assert 'timeoutMs: 60000' in js
    assert 'state.taskDetails.clear()' not in js
    assert 'taskDetailLoads: new Map()' in js
    # v2.9.22.8: initial/periodic bootstrap is status-only.
    refresh=js[js.index('async function refresh'):js.index('async function command')]
    assert 'renderTasks()' in refresh
    assert 'P2K_FRESH_POINTS_RECONSTRUCTION.sync' not in refresh
    assert 'loadSelectedTaskDetail({ quiet: true })' in refresh
    assert 'state.selected' in refresh
    assert 'loadLogs()' not in refresh

def test_traffic_diagnostics_do_not_open_core_or_analytics():
    php=text('server/team-points/public/intelligence.php')
    traffic=php.index("if($scope==='traffic')")
    db=php.index('new Repository(Database::core(),Database::analytics())')
    assert traffic < db
    branch=php[traffic:db]
    assert 'TrafficAnalytics::report' in branch
    assert 'TrafficAnalytics::diagnostics' in branch
    assert 'TrafficAnalytics::selfTest' in branch

def test_runtime_diagnostics_has_endpoint_specific_deadlines():
    js=text('assets/js/pages/runtime-diagnostics.js')
    assert 'timeouts=[8000,20000,8000]' in js
    assert 'urls.map((url,index)=>fetchJSON(url,timeouts[index]))' in js
