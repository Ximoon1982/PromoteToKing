from pathlib import Path
from bs4 import BeautifulSoup
import hashlib
import re

ROOT=Path(__file__).resolve().parents[1]

EXPECTED_PUBLIC={
    'publicDashboardPanel':'a18bc0f9b470edcfd8572e2eb5498e30f37c53ac22dc89ac4c7a3dc94ae4ee6b',
    'hallOfFamePage':'8cba68b3b0aa8f697ffd838f6358d4b424b41700eda8c5609df17148ef881c36',
    'teamInsightsPage':'33f953f29211016d922718cb30e8c7b7991ec8943a7048ffd0bcbc9813b7750f',
}
EXPECTED_PRE_613_CSS='a0d2ad10508314a969ee62d30fe67c5b9d0829180b7c54c8ca81554218d8f677'

def sha(data: str) -> str:
    return hashlib.sha256(data.encode()).hexdigest()

def test_release_identity_and_public_visual_lock():
    assert (ROOT/'VERSION').read_text().strip()=='2.10.6.13'
    assert (ROOT/'MIGRATION_VERSION').read_text().strip()=='2.10.6.13'
    soup=BeautifulSoup((ROOT/'ui-v2.html').read_text(), 'html.parser')
    for node_id, expected in EXPECTED_PUBLIC.items():
        assert sha(str(soup.find(id=node_id)))==expected, node_id
    css=(ROOT/'assets/css/dashboard-v2.css').read_text()
    prefix=css.split('/* v2.10.6.13 admin detail routing')[0].rstrip()+'\n'
    assert sha(prefix)==EXPECTED_PRE_613_CSS

def test_admin_detail_router_and_canonical_urls():
    js=(ROOT/'assets/js/pages/dashboard-v2.js').read_text()
    for token in ['ADMIN_DETAIL_DEFS','adminDetail:','adminDetailTab:','adminToolTab:','adminShellOpenDetail','renderAdminShellDetail','dashboard-admin-shell-detail']:
        assert token in js
    assert 'url.searchParams.set("page", state.publicPage || "dashboard")' in js
    assert 'url.searchParams.set("adminCategory", state.category || "competitions")' in js
    assert '"reconciliation","members","migration"' in js
    assert 'writeNavigationState({ replace: true });' not in js[js.index('host.querySelectorAll("[data-admin-category]")'):js.index('byId("adminShellRefresh")')]
    assert 'if (state.view === "public" && state.publicPage !== "administration") showPublicPage' in js

def test_embedded_admin_tabs_propagate_and_restore():
    tp=(ROOT/'assets/js/pages/team-points-admin.js').read_text()
    ci=(ROOT/'assets/js/pages/club-intelligence.js').read_text()
    tasks=(ROOT/'assets/js/pages/task-control.js').read_text()
    for text, tool in [(tp,'team-points-admin'),(ci,'club-intelligence'),(tasks,'task-control')]:
        assert 'p2k-embedded-tab-change' in text and tool in text
        assert 'popstate' in text
    assert 'history.pushState' in tp
    assert 'history.pushState' in ci
    assert 'history.pushState' in tasks

def test_dashboard_assistant_uses_dedicated_atomic_reveal_and_real_history():
    js=(ROOT/'assets/js/pages/dashboard-v2.js').read_text()
    block=js[js.index('function openMatchAssistant({'):js.index('function openMatchAssistantWithFilter')]
    assert 'ensureDedicatedMatchAssistant(state.pendingAssistantFilter)' in block
    assert 'promoteMatchAssistantFrame(frame)' not in block
    assert 'recommendationsDefaultView").hidden = true' not in block
    assert 'writeNavigationState();' in block
    reveal=js[js.index('function revealMatchAssistantFrame'):js.index('function handleAdminEmbeddedNavigation')]
    assert 'state.assistantFullReady' in reveal
    assert 'recommendationsDefaultView' in reveal and 'dashboardMatchAssistant' in reveal
    close=js[js.index('function closeMatchAssistant'):js.index('let dashboardHallModulePromise')]
    assert 'writeNavigationState();' in close

def test_datetime_formatters_do_not_use_browser_local_timezone():
    for path in ROOT.rglob('*'):
        if path.suffix.lower() not in {'.js','.html','.htm'} or 'tests' in path.parts:
            continue
        text=path.read_text(errors='ignore')
        for m in re.finditer(r'Intl\.DateTimeFormat\(',text):
            head=text[m.start():m.start()+520].split(').format',1)[0]
            assert 'timeZone' in head, f'Intl.DateTimeFormat without timezone in {path.relative_to(ROOT)}'
    core=(ROOT/'assets/js/shared/upcoming-analysis-core.js').read_text()
    assert 'getUTCDate()' in core and 'getUTCHours()' in core
