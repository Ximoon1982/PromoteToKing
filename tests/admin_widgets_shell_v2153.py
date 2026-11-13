"""Check the actual v2 shell widget routing and the restored Match Showcase tab."""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT = Path(__file__).resolve().parents[1]
source = (ROOT/'assets/js/admin/admin-shell.js').read_text()
html = '''<!doctype html><html><body>
<section id="adminDashboardHost"><div id="adminShellDetail" hidden>
<h3 id="adminShellDetailTitle"></h3><p id="adminShellDetailBreadcrumb"></p>
<nav id="adminShellDetailTabs"></nav>
<div id="adminShellDetailFrameWrap"><iframe id="adminShellDetailFrame"></iframe></div>
<div id="adminShellNativeDetailHost" hidden></div>
</div></section></body></html>'''
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    page = browser.new_page(viewport={'width':1100,'height':850})
    page.set_content(html)
    page.evaluate("""() => {const OriginalURL=URL; window.URL=function(url,base){return new OriginalURL(url,!base || base==='about:blank' ? 'https://www.promotetoking.org/ui-v2.html':base)};window.URL.prototype=OriginalURL.prototype;}""")
    page.add_script_tag(content=source)
    page.evaluate('''() => {
      window.shellState={category:'team',adminDetail:'widgets',adminDetailTab:'club-pulse',adminToolTab:'',admin:true};
      window.P2K_WIDGETS_ADMIN={mount: id=>window.lastMountedWidget=id};
      window.P2K_EVENTS_SHOWCASE_ADMIN={mount:()=>window.showcaseMounted=true};
      window.shell = window.P2K_DASHBOARD_MODULES.adminShell.create({
        state:window.shellState,byId:id=>document.getElementById(id),
        escapeHTML:s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/\"/g,'&quot;'),
        number:String,setText:(id,t)=>{const e=document.getElementById(id);if(e)e.textContent=t;},
        applyOAuthContext:u=>u,setIntegratedFrameActivity:()=>{},ensureIntegratedFrame:()=>{},
        writeNavigationState:()=>{},tools:[]
      });
      window.shell.adminShellActivate();
    }''')
    assert page.locator('#adminShellDetailTabs a').count()==11
    assert page.locator('#adminShellNativeDetailHost').get_attribute('data-native-detail')=='p2k-widget-club-pulse'
    assert page.evaluate('window.lastMountedWidget')=='club-pulse'
    for tab,kind in [('match-showcase','match-showcase'),('recruitment-radar','p2k-widget-recruitment-radar'),('trophy-spotlight','p2k-widget-trophy-spotlight'),('rivalry-spotlight','p2k-widget-rivalry-spotlight'),('club-progress','p2k-widget-club-progress'),('around-the-world','p2k-widget-around-the-world'),('player-spotlight','p2k-widget-player-spotlight'),('tournament-winners','p2k-widget-tournament-winners'),('hall-of-fame','p2k-widget-hall-of-fame'),('competition','p2k-widget-competition')]:
        page.evaluate('(tab)=>{window.shellState.adminDetailTab=tab;window.shell.adminShellActivate()}',tab)
        assert page.locator('#adminShellNativeDetailHost').get_attribute('data-native-detail')==kind
    assert page.evaluate('window.showcaseMounted===true')
    page.evaluate('''() => {window.shellState.adminDetail='';window.shellState.adminDetailTab='';}''')
    markup=page.evaluate('''() => window.shell.adminPanelMarkup()''')
    assert 'data-admin-detail="widgets"' in markup
    assert markup.find('data-admin-shell-card="widgets"')!=-1
    assert 'data-v2122-events-showcase' not in markup, 'Existing showcase must not be duplicated under Competitions'
    assert len(page.errors if hasattr(page,'errors') else [])==0
    print('PASS: Team > Widgets card, 11 subtabs, Match Showcase migration, and native routing')
    browser.close()
