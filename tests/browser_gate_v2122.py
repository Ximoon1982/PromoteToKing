from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
rec=(ROOT/'assets/js/shared/recruitment-lineup-core.js').read_text()
core=(ROOT/'assets/js/shared/events-showcase-core.js').read_text()
trophy=(ROOT/'assets/js/admin/trophy-card-presentation-v2122.js').read_text()
admin=(ROOT/'assets/js/admin/events-showcase-v2122.js').read_text().replace('const ROOT=new URL("../../../",document.currentScript?.src||location.href);','const ROOT=new URL("https://p2k.test/");')
html='''<!doctype html><html><head></head><body>
<div data-admin-shell-panel="competitions"><div class="dashboard-admin-shell-grid"><article data-admin-shell-card="daily"></article></div></div>
<section class="p2k-trophy-root"><div data-groups><section class="p2k-trophy-group"><div class="p2k-trophy-grid"><article class="p2k-trophy-card" data-trophy-id="t1"><button type="button" data-open="t1"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="><div><small>League</small><h4>Historic title</h4><p>Old detail</p></div></button></article></div></section></div></section>
</body></html>'''
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True, executable_path='/usr/bin/chromium')
    page=browser.new_page()
    page.route('https://p2k.test/**', lambda route: route.fulfill(status=200, content_type='text/html', body='<html></html>'))
    page.set_content(html)
    page.evaluate('''() => {
      window.P2K_SITE_CONFIG={clubSlug:'promote-to-king',leagueAcronyms:['1WL','TCMAC','PCL'],api:{defaultAttempts:1}};
      window.__saveCalls=[];
      window.__state={ok:true,schemaVersion:4,revision:2,items:[{matchId:'1001',enabled:true,urgent:false}],arenas:[{active:false,name:'Test Arena',link:'https://www.chess.com/play/arena/test',date:'2099-01-01',time:'18:00',timeControl:'3+2',duration:'2h',durationMinutes:120,startAt:'2099-01-01T18:00:00+01:00'}]};
      const registered=[];for(let i=0;i<45;i++)registered.push({'@id':`https://api.chess.com/pub/match/${2000+i}`,name:(i%2?'Friendly':'1WL')+` Match ${i}`,start_time:4070908800+i*3600,url:`https://www.chess.com/club/matches/promote-to-king/${2000+i}`,settings:{min_rating:0,max_rating:0,time_control:'1/259200'}});
      window.P2K_API_CLIENT={json:async(url)=>{if(String(url).includes('/club/promote-to-king/matches'))return{registered};const id=String(url).match(/(\\d+)$/)?.[1]||'0';return{'@id':String(url),name:`Detail ${id}`,start_time:4070908800,settings:{min_rating:0,max_rating:0,min_team_players:2,max_team_players:10,time_control:'1/259200'},teams:{a:{'@id':'https://api.chess.com/pub/club/promote-to-king',name:'Promote to King',players:[{rating:1400},{rating:1300}]},b:{'@id':'https://api.chess.com/pub/club/opponent',name:'Opponent',players:[{rating:1350},{rating:1250}]}}};}};
      window.P2K_TEAM_POINTS_CLIENT={connect:async()=>({username:'Admin'}),endpointRequest:async(url,opt)=>{window.__saveCalls.push({url:String(url),body:structuredClone(opt.body)});window.__state={...window.__state,...opt.body,revision:Number(opt.body.revision)+1,ok:true};return structuredClone(window.__state);}};
      window.fetch=async(url,opt)=>{if(String(url).includes('server/events-showcase/public/api.php'))return new Response(JSON.stringify(window.__state),{status:200,headers:{'Content-Type':'application/json'}});return new Response('',{status:404});};
    }''')
    page.add_script_tag(content=rec)
    page.add_script_tag(content=core)
    # Satisfy the admin module's immutable-script loader without network loading.
    page.evaluate('''() => { for (const path of ['assets/js/shared/recruitment-lineup-core.js','assets/js/shared/events-showcase-core.js']) { const s=document.createElement('script'); s.src='https://p2k.test/'+path; s.dataset.p2kLoaded='1'; document.head.appendChild(s); } }''')
    page.add_script_tag(content=trophy)
    page.add_script_tag(content=admin)
    page.wait_for_selector('[data-v2122-events-showcase]')
    assert page.locator('[data-es-arena-metric]').inner_text()=='1 · 0 enabled'
    assert page.locator('[data-es-match-metric]').inner_text()=='1 · 1 enabled'
    page.click('[data-es-open]')
    page.wait_for_selector('#p2kEventsShowcaseEditor[open]')
    page.wait_for_function("document.querySelectorAll('[data-es-search-results] [data-es-add]').length===20")
    assert 'page 1/3' in page.locator('[data-es-search-count]').inner_text()
    page.click('[data-es-add-arena]')
    page.fill('[data-es-arena-name]','Keyboard Arena')
    page.fill('[data-es-arena-link]','https://www.chess.com/play/arena/keyboard')
    page.fill('[data-es-arena-date]','2099-01-02')
    page.fill('[data-es-arena-time]','19:30')
    page.fill('[data-es-arena-tc]','3+2')
    page.fill('[data-es-arena-duration]','2h')
    assert page.evaluate('window.__saveCalls.length')==0, 'typing/editing arena fields must not live-save'
    page.click('[data-es-arena-form] button[type=submit]')
    page.wait_for_function('window.__saveCalls.length===1')
    page.locator('[data-es-arena-enabled]').first.check()
    page.wait_for_function('window.__saveCalls.length===2')
    # Duplicate URL rejected client-side without another save.
    page.click('[data-es-add-arena]')
    page.fill('[data-es-arena-name]','Duplicate')
    page.fill('[data-es-arena-link]','https://www.chess.com/play/arena/keyboard/')
    page.fill('[data-es-arena-date]','2099-01-03')
    page.fill('[data-es-arena-time]','20:00')
    page.fill('[data-es-arena-duration]','1h')
    page.click('[data-es-arena-form] button[type=submit]')
    assert 'already configured' in page.locator('[data-es-arena-error]').inner_text()
    assert page.evaluate('window.__saveCalls.length')==2
    html_value=page.locator('[data-es-iframe-html]').input_value()
    assert html_value.startswith('<iframe ') and '<script' not in html_value and 'scrolling="no"' in html_value
    title=page.locator('[data-r538-title]')
    assert title.inner_text()=='Historic title'
    assert page.locator('[data-r538-card]').evaluate("e=>getComputedStyle(e).borderTopLeftRadius")=='11px'
    assert title.evaluate("e=>getComputedStyle(e).borderBottomWidth")=='0px'
    assert page.locator('[data-r538-art]').evaluate("e=>getComputedStyle(e).aspectRatio") in ('1 / 1','1')
    print('PASS browser Events Showcase + trophy presentation gate')
    browser.close()
