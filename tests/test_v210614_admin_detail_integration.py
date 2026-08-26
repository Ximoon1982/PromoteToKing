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
    assert (ROOT/'VERSION').read_text().strip()=='2.10.6.14'
    assert (ROOT/'MIGRATION_VERSION').read_text().strip()=='2.10.6.14'
    soup=BeautifulSoup((ROOT/'ui-v2.html').read_text(),'html.parser')
    for node_id, expected in EXPECTED_PUBLIC.items():
        assert sha(str(soup.find(id=node_id)))==expected,node_id

def test_admin_detail_host_is_full_height_and_activity_aware():
    js=(ROOT/'assets/js/pages/dashboard-v2.js').read_text()
    css=(ROOT/'assets/css/dashboard-v2.css').read_text()
    assert 'targetUrl.searchParams.set("active","1")' in js
    assert 'ensureIntegratedFrame("adminShellDetailFrame")' in js
    assert 'byId("adminShellDetailFrame")].find' in js
    assert 'frame?.id === "adminShellDetailFrame" ? 100000 : 12000' in js
    assert 'min-height:610px' not in css[css.index('/* v2.10.6.13 admin detail routing'):]
    assert 'min-height:720px' not in css[css.index('/* v2.10.6.13 admin detail routing'):]
    assert '.dashboard-admin-detail-frame-wrap{width:100%;min-height:360px;border:0' in css

def test_mca_no_longer_embeds_global_task_control():
    js=(ROOT/'assets/js/pages/dashboard-v2.js').read_text()
    block=js.split('mca: { title:"Multi Club Arenas"',1)[1].split('tournaments:',1)[0]
    assert 'TeamPointsAdmin.html?embedded=1&tab=live-ranks' in block
    assert 'TaskControl.html' not in block

def test_embedded_chrome_is_reduced_without_standalone_removal():
    css=(ROOT/'assets/css/admin-embedded.css').read_text()
    for token in [
        '.ci-tabs{display:none!important}',
        '.task-surface-tabs{display:none!important}',
        'body.p2k-runtime-diagnostics .hero>div:first-child{display:none!important}',
        'body.p2k-tournament-management .embedded-intro{display:none!important}',
        'body.p2k-migration-page .mig-head{display:none!important}',
    ]: assert token in css
    assert 'ci-tabs' in (ROOT/'ClubIntelligence.html').read_text()
    assert 'task-surface-tabs' in (ROOT/'TaskControl.html').read_text()

def test_every_admin_detail_target_reports_embedded_height():
    for f in ['TeamPointsAdmin.html','TaskControl.html','ClubIntelligence.html','AnalyzeMatches.htm','MatchCreationAnalyzer.htm','ChallengeListAssistant.html','AnalyzeMatch.html','TournamentManagement.html','InsightsHealth.html','DataReconciliation.html','TaskLogs.html','TeamPointsMigration.html']:
        assert 'embedded-page.js' in (ROOT/f).read_text(),f
