from pathlib import Path
from bs4 import BeautifulSoup
import hashlib

ROOT=Path(__file__).resolve().parents[1]
EXPECTED_PUBLIC={
    'publicDashboardPanel':'a18bc0f9b470edcfd8572e2eb5498e30f37c53ac22dc89ac4c7a3dc94ae4ee6b',
    'hallOfFamePage':'8cba68b3b0aa8f697ffd838f6358d4b424b41700eda8c5609df17148ef881c36',
    'teamInsightsPage':'33f953f29211016d922718cb30e8c7b7991ec8943a7048ffd0bcbc9813b7750f',
}
def sha(s): return hashlib.sha256(s.encode()).hexdigest()

def test_identity_and_public_dom_lock():
    assert (ROOT/'VERSION').read_text().strip()=='2.10.6.15'
    assert (ROOT/'MIGRATION_VERSION').read_text().strip()=='2.10.6.15'
    soup=BeautifulSoup((ROOT/'ui-v2.html').read_text(),'html.parser')
    for node_id, expected in EXPECTED_PUBLIC.items():
        assert sha(str(soup.find(id=node_id)))==expected,node_id

def test_task_control_native_green_navigation_is_visible_when_embedded():
    css=(ROOT/'assets/css/admin-embedded.css').read_text()
    task=(ROOT/'TaskControl.html').read_text()
    assert '.task-surface-tabs{display:none!important}' not in css
    assert '.task-surface-tabs{display:flex!important' in css
    assert 'data-task-tab="scheduled"' in task
    assert 'data-task-tab="green"' in task
    assert 'id="greenSchedulerControl"' in task

def test_new_admin_uses_task_control_parent_plus_native_green_child_tab():
    js=(ROOT/'assets/js/pages/dashboard-v2.js').read_text()
    block=js.split('tasks: { title:"Scheduled Task Control"',1)[1].split('logs:',1)[0]
    assert '{key:"control",label:"Task Control",src:"TaskControl.html?embedded=1&tab=scheduled&release=2.10.6.15"}' in block
    assert '{key:"migration",label:"Production migration"' in block
    assert '{key:"green",label:"Green Team Points"' not in block
    assert 'links:[{label:"Scheduled tasks",detailTab:"control",toolTab:"scheduled"},{label:"Green Team Points",detailTab:"control",toolTab:"green",secondary:true}]' in js
    assert 'function adminShellHref(category, detail="", detailTab="", toolTab="")' in js
    assert 'url.searchParams.set("adminToolTab",toolTab)' in js
    assert 'toolTab:u.searchParams.get("adminToolTab")||""' in js

def test_green_tab_is_url_persistent_in_new_and_legacy_admin_routes():
    js=(ROOT/'assets/js/pages/dashboard-v2.js').read_text()
    assert 'if (state.adminSubtab === "tasks" && state.adminToolTab) url.searchParams.set("adminToolTab", state.adminToolTab)' in js
    assert 'if (id === "adminTasksFrame" && ["scheduled","green"].includes(state.adminToolTab)) url.searchParams.set("tab", state.adminToolTab)' in js
    assert 'event.data?.type === "p2k-embedded-tab-change" && byId("adminTasksFrame")?.contentWindow === event.source' in js
    assert 'if(["scheduled","green"].includes(tab)){state.adminToolTab=tab;writeNavigationState();}' in js
    assert 'else state.adminToolTab=tab;' in js  # new Admin-shell child tab propagation

def test_green_unique_operational_controls_and_metrics_are_preserved():
    task=(ROOT/'TaskControl.html').read_text()
    task_js=(ROOT/'assets/js/pages/task-control.js').read_text()
    required_ids=[
        'greenSchedulerMetrics','greenCutoverMetrics','greenCycleProgress','greenInvocationRows',
        'greenGabMetrics','greenGabPhases','greenGabStart','greenGabRun',
        'greenGfflMetrics','greenGfflEnable','greenGfflDisable','greenGfflTarget',
        'greenAcceleratorMetrics','greenAcceleratorLogRows','greenAcceleratorStart','greenAcceleratorOnce','greenAcceleratorStop',
    ]
    for ident in required_ids:
        assert f'id="{ident}"' in task, ident
    for token in ['start-gab','run-gab-now','set-gffl','P2K_GREEN_ACCELERATOR?.start','P2K_GREEN_ACCELERATOR?.runOnce','P2K_GREEN_ACCELERATOR?.stop']:
        assert token in task_js, token
