#!/usr/bin/env python3
"""v2.8.10 browser semantic gate: startup/auth, source-of-truth, ACAMR and protected write headers."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import json, os, re, shutil

ROOT=Path(__file__).resolve().parents[1]
CHROMIUM=os.environ.get('P2K_CHROMIUM') or shutil.which('chromium')

def clean_html(name):
    html=(ROOT/name).read_text(encoding='utf-8',errors='ignore')
    html=re.sub(r'<script\b[^>]*>.*?</script>','',html,flags=re.I|re.S)
    html=re.sub(r'<meta[^>]+http-equiv=["\']Content-Security-Policy["\'][^>]*>','',html,flags=re.I)
    html=re.sub(r'\sdata-src="[^"]*"','',html)
    return html

def inline_scripts(name):
    html=(ROOT/name).read_text(encoding='utf-8',errors='ignore')
    return [m.group('body') for m in re.finditer(r'<script(?P<attrs>[^>]*)>(?P<body>.*?)</script>',html,re.I|re.S) if 'src=' not in m.group('attrs').lower() and m.group('body').strip()]

SAFE_URL=r'''
const NativeURL=window.URL;
function SafeURL(input,base){const b=(!base||String(base)==='about:blank')?'https://p2k.test/ui-v2.html?oauth=1':base;return new NativeURL(input,b)}
SafeURL.prototype=NativeURL.prototype;for(const k of ['createObjectURL','revokeObjectURL','canParse'])if(NativeURL[k])SafeURL[k]=NativeURL[k].bind(NativeURL);window.URL=SafeURL;
'''

DASH=SAFE_URL+r'''
window.P2K_SITE_CONFIG={clubSlug:'promote-to-king',clubUrl:'https://www.chess.com/club/promote-to-king',auth:{adminUsernames:['Ximoon']},features:{simulatedOAuth:true},serverStorage:{acamrPlanEndpoint:'server/team-points/public/acamr-plan.php'}};
window.P2K_AUTH={enabled:true,mode:'simulated',getSession(){return {username:'Ximoon',authMode:'simulated'}}};
window.P2K_TEAM_POINTS_CLIENT={publicRequest:async()=>({team:{current_members:4000,club_points:12345,finished_matches_available:true,finished_matches:100,finished_boards:1000,finished_games:2000}}),endpointRequest:async()=>({ok:true,tasks:[]})};
window.__apiCalls=[];
window.P2K_API_CLIENT={json:async url=>{const s=String(url);window.__apiCalls.push(s);
  if(s.endsWith('/matches')) return {registered:[{id:1},{id:2}],in_progress:[{id:3}],finished:[1,2,3,4,5,6,7]};
  if(s.includes('/members')) return {all_time:new Array(999)};
  if(s.includes('/pub/club/promote-to-king')) return {name:'Promote to King',members:999,admin:['Ximoon']};
  if(s.includes('/pub/player/')) return {username:'Ximoon'};
  return {};
}};
window.P2K_PROGRESSIVE={snapshotGet(){return null},snapshotSet(){},afterFirstPaint(fn){setTimeout(fn,0)},afterIdle(){},canPrefetch(){return false},schedule(fn){return Promise.resolve().then(fn)}};
window.fetch=async()=>({ok:true,status:200,json:async()=>({ok:true,team:{}})});
'''

ACAMR=SAFE_URL+r'''
window.P2K_SITE_CONFIG={features:{simulatedOAuth:true},serverStorage:{acamrPlanEndpoint:'server/team-points/public/acamr-plan.php'}};
window.P2K_AUTH={enabled:true,mode:'simulated',getSession(){return {username:'Ximoon',authMode:'simulated'}}};window.P2K_TAB_ACTIVITY={isActive(){return true}};try{Object.defineProperty(document,'visibilityState',{value:'visible',configurable:true})}catch(_){}
window.__acamrCalls=[];window.P2K_API_CLIENT={json:async(url,options)=>{window.__acamrCalls.push({url:String(url),source:options?.observationSource||''});return {data:{},url:String(url)}}};
window.fetch=async()=>({ok:true,status:200,json:async()=>({ok:true,claim:{username:'Ximoon',next_cursor:1,tasks:[{kind:'stats',url:'https://api.chess.com/pub/player/Ximoon/stats'},{kind:'tournament',url:'https://api.chess.com/pub/tournament/forbidden'},{kind:'registration',url:'https://api.chess.com/pub/club/promote-to-king/matches'}]},policy:{pulse_ms:10000}})});
'''

HEADER=SAFE_URL+r'''
window.P2K_SITE_CONFIG={serverStorage:{teamPointsEndpoint:'server/team-points/public/api.php',teamPointsSessionEndpoint:'server/team-points/public/session.php',teamPointsPublicEndpoint:'server/team-points/public/public.php'}};
window.P2K_AUTH={getSession(){return {username:'Ximoon'}}};window.__writes=[];
window.fetch=async(url,opt={})=>{const u=String(url);if(u.includes('/session.php'))return new Response(JSON.stringify({ok:true,csrf:'csrf-test',username:'ximoon'}),{status:200,headers:{'Content-Type':'application/json'}});window.__writes.push({url:u,method:opt.method||'GET',kind:new Headers(opt.headers||{}).get('X-Club-Tools-Request'),csrf:new Headers(opt.headers||{}).get('X-P2K-CSRF')});return new Response(JSON.stringify({ok:true}),{status:200,headers:{'Content-Type':'application/json'}})};
'''

TASK_UI=SAFE_URL+r'''
document.documentElement.classList.remove('admin-access-pending');history.replaceState=()=>{};window.P2K_ADMIN_ACCESS_READY=Promise.resolve(true);
window.P2K_AUTH={getSession(){return {username:'Ximoon'}}}; window.P2K_ADMIN_USERNAME='ximoon';
const tracked={matchId:'4242',name:'Recovered League Match',url:'https://www.chess.com/club/matches/4242',status:'ongoing',boardCount:32,followed:true,fileCount:3,hasData:true,startTime:1790000000,lastTrackedAt:'2026-08-09T00:00:00Z',nextCaptureAt:'2026-08-09T01:00:00Z',samplingLabel:'hourly',teams:['Promote to King','Opponent'],leagueAcronyms:['PCL']};
const tasks=[
 {task_key:'match-tracking',label:'Match monitoring',health:'warning',health_message:'Last endpoint failed',status:'idle',last_success_at:null,expected_interval_seconds:3600,legacy_endpoint:'api/track-upcoming-league-matches/',work:{summary:{known_matches:1,followed_matches:1},work_report:'1 tracked match'},cron_shell:{observed:true,status:'http_error',last_started_at:'2026-08-09T00:30:00Z',http_status:403,exit_code:0}},
 {task_key:'tournaments',label:'Tournaments',health:'healthy',health_message:'Ready',status:'idle',last_success_at:'2026-08-09T00:00:00Z',expected_interval_seconds:600,legacy_endpoint:'server/tournaments/public/cron.php',work:{summary:{known_tournaments:1},work_report:'1 tournament stored'},cron_shell:{observed:true,status:'success',last_started_at:'2026-08-09T00:50:00Z',http_status:200,exit_code:0}}
];
window.__taskWrites=[];
window.P2K_TEAM_POINTS_CLIENT={
 connect:async()=>({username:'ximoon',schema_version:7}),
 endpointRequest:async(url,opt={})=>{const u=String(url),action=opt.action||'';
   if(u.includes('server/control/public/api.php')&&action==='status')return {ok:true,gateway:{health_status:'healthy',health_message:'ok',cache_entries:1,fresh_cache_entries:1,false_404_protection:true},tasks};
   if(u.includes('server/control/public/api.php')&&action==='logs')return {ok:true,logs:[],has_more:false,next_before_id:0};
   if(u.includes('api/tracked-match-data/'))return {ok:true,matches:[tracked],summary:{followed:1,ongoing:1,finished:0,files:3}};
   if(u.includes('server/tournaments/public/tournaments.php')){window.__taskWrites.push({u,body:opt.body});return {ok:true,message:'Tournament update jobs queued.'}}
   return {ok:true};
 }};
'''

TOURNAMENT_UI=SAFE_URL+r'''
const __store=new Map();const __storage={getItem:k=>__store.has(String(k))?__store.get(String(k)):null,setItem:(k,v)=>__store.set(String(k),String(v)),removeItem:k=>__store.delete(String(k)),clear:()=>__store.clear(),key:i=>[...__store.keys()][i]||null,get length(){return __store.size}};
try{Object.defineProperty(window,'localStorage',{value:__storage,configurable:true});Object.defineProperty(window,'sessionStorage',{value:__storage,configurable:true})}catch(_){}
window.ResizeObserver=class{constructor(cb){this.cb=cb}observe(){}};window.parent={postMessage(){}};window.__tournamentWrites=[];
const archive={schemaVersion:3,generatedAt:'2026-08-09T00:00:00Z',lastStatusRefresh:'2026-08-09T00:00:00Z',scanState:{lastSync:'2026-08-09T00:00:00Z'},tournaments:[{slug:'known-event',name:'Known Event',status:'finished',podium:{gold:['Winner'],silver:['Second'],bronze:['Third']}}]};
window.fetch=async(url,opt={})=>{const u=String(url),method=String(opt.method||'GET').toUpperCase();
  if(u.includes('server/tournaments/public/tournaments.php')){
    if(method==='POST'){const h=new Headers(opt.headers||{});window.__tournamentWrites.push({kind:h.get('X-Club-Tools-Request'),body:JSON.parse(opt.body||'{}')});return new Response(JSON.stringify({ok:true,message:'Tournament statuses refreshed.',archive,result:{checked:1,updated:1}}),{status:200,headers:{'Content-Type':'application/json'}})}
    return new Response(JSON.stringify({ok:true,archive}),{status:200,headers:{'Content-Type':'application/json'}});
  }
  if(u.includes('server/tournaments/public/logs.php'))return new Response(JSON.stringify({ok:true,logs:[{startedAt:'2026-08-09T00:00:00Z',trigger:'cron',result:'success',checked:1,updated:1,excluded:0,message:'ok'}]}),{status:200,headers:{'Content-Type':'application/json'}});
  return new Response(JSON.stringify({}),{status:200,headers:{'Content-Type':'application/json'}});
};
'''

def main():
  with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage'])
    page=browser.new_page(); errors=[]; page.on('pageerror',lambda e: errors.append(str(e)))
    page.set_content(clean_html('ui-v2.html'),wait_until='domcontentloaded');page.add_script_tag(content=DASH);page.add_script_tag(path=str(ROOT/'assets/js/admin/admin-shell.js'));page.add_script_tag(path=str(ROOT/'assets/js/admin/admin-session-controller.js'));page.add_script_tag(path=str(ROOT/'assets/js/admin/embedded-detail-host.js'));page.add_script_tag(path=str(ROOT/'assets/js/admin/tool-registry.js'));page.add_script_tag(path=str(ROOT/'assets/js/dashboard/personal-home.js'));page.add_script_tag(path=str(ROOT/'assets/js/dashboard/insights-controller.js'));page.add_script_tag(path=str(ROOT/'assets/js/dashboard/team-summary.js'));page.add_script_tag(path=str(ROOT/'assets/js/dashboard/match-assistant.js'));page.add_script_tag(path=str(ROOT/'assets/js/dashboard/match-list-dialog.js'));page.add_script_tag(path=str(ROOT/'assets/js/dashboard/dashboard-bootstrap.js'));page.add_script_tag(path=str(ROOT/'assets/js/pages/dashboard-v2.js'));page.wait_for_timeout(700)
    dash=page.evaluate('''() => ({status:document.getElementById('teamStatusBadge')?.textContent||'',members:document.getElementById('teamMembers')?.textContent||'',finished:document.getElementById('teamFinishedMatches')?.textContent||'',registered:document.getElementById('teamOpenRegistrations')?.textContent||'',ongoing:document.getElementById('teamActiveMatches')?.textContent||'',admin:window.P2K_ADMIN_MODE===true,calls:window.__apiCalls||[]})''')
    if page.locator('#dashboardAdministrationTab').count(): page.locator('#dashboardAdministrationTab').click(); page.wait_for_timeout(100)
    admin_hidden=page.evaluate("() => document.getElementById('administrationPage')?.hidden??null")
    if errors or dash['status']!='Database ready' or dash['members']!='4,000' or dash['finished']!='100' or dash['registered']!='2' or dash['ongoing']!='1' or not dash['admin'] or admin_hidden or any('/members' in x for x in dash['calls']) or sum('member-intelligence.php' in x for x in dash['calls'])!=1:
      raise AssertionError(json.dumps({'errors':errors,'dashboard':dash,'adminHidden':admin_hidden},indent=2))

    ac=browser.new_page(); ae=[]; ac.on('pageerror',lambda e:ae.append(str(e)));ac.set_content('<html><body></body></html>');ac.add_script_tag(content=ACAMR);ac.add_script_tag(path=str(ROOT/'assets/js/shared/authenticated-member-refresh.js'));ac.wait_for_timeout(1500)
    ar=ac.evaluate("() => ({active:window.P2K_ACAMR?.active?.()===true,calls:window.__acamrCalls||[]})")
    if ae or not ar['active'] or len(ar['calls'])!=1 or '/stats' not in ar['calls'][0]['url'] or ar['calls'][0]['source']!='acamr': raise AssertionError(json.dumps({'errors':ae,'acamr':ar},indent=2))

    hp=browser.new_page(); he=[]; hp.on('pageerror',lambda e:he.append(str(e)));hp.set_content('<html><body></body></html>');hp.add_script_tag(content=HEADER);hp.add_script_tag(path=str(ROOT/'assets/js/shared/team-points-client.js'))
    hp.evaluate('''async()=>{const c=window.P2K_TEAM_POINTS_CLIENT;await c.endpointRequest('server/tournaments/public/tournaments.php',{method:'POST',body:{mode:'enqueue-update'}});await c.endpointRequest('api/tracked-match-data/',{method:'POST',body:{action:'follow',match:'123'}});await c.endpointRequest('api/record-league-match/',{method:'POST',body:{match:'123'}});await c.endpointRequest('api/track-upcoming-league-matches/',{method:'POST',body:{}});}''')
    writes=hp.evaluate('() => window.__writes')
    kinds=[x['kind'] for x in writes]; expected=['tournaments-update','tracked-match-data','record-league-match','track-upcoming-league-matches']
    if he or kinds!=expected or any(x['csrf']!='csrf-test' for x in writes): raise AssertionError(json.dumps({'errors':he,'writes':writes},indent=2))

    # Unified Task Control must actually display Match Tracking and Tournament tools, not only send valid headers.
    tc=browser.new_page(); te=[]; tc.on('pageerror',lambda e:te.append(str(e)));tc.set_content(clean_html('TaskControl.html'),wait_until='domcontentloaded');tc.add_script_tag(content=TASK_UI);tc.add_script_tag(path=str(ROOT/'assets/js/pages/task-control.js'));tc.wait_for_timeout(350)
    task_view=tc.evaluate("""() => ({trackingHidden:document.getElementById('match-monitoring')?.hidden,trackedText:document.getElementById('trackedRows')?.textContent||'',feedback:document.getElementById('trackedFeedback')?.textContent||'',metrics:document.getElementById('taskDetailMetrics')?.textContent||''})""")
    if te or task_view['trackingHidden'] or 'Recovered League Match' not in task_view['trackedText'] or '1 tracked match loaded' not in task_view['feedback'] or '403' not in task_view['metrics']:
      raise AssertionError(json.dumps({'errors':te,'taskControl':task_view},indent=2))
    tc.evaluate("() => document.querySelector('[data-task-card=\"tournaments\"]')?.click()");tc.wait_for_timeout(80)
    tournament_panel_hidden=tc.evaluate("() => document.getElementById('manual-tournament')?.hidden")
    if tournament_panel_hidden: raise AssertionError('Tournament management panel remained hidden in Task Control')
    tc.evaluate("() => document.getElementById('manualTournamentUpdate')?.click()");tc.wait_for_timeout(80)
    if 'queued' not in tc.locator('#manualTournamentFeedback').inner_text().lower(): raise AssertionError('Task Control tournament update feedback did not render')

    # Dedicated Tournament Management must load a recovered/stored archive and refresh without the historical request-header error.
    tm=browser.new_page(); tme=[]; tm.on('pageerror',lambda e:tme.append(str(e)));tm.set_content(clean_html('TournamentManagement.html'),wait_until='domcontentloaded');tm.add_script_tag(content=TOURNAMENT_UI);scripts=inline_scripts('TournamentManagement.html');tm.add_script_tag(content=scripts[-1]);tm.wait_for_timeout(250)
    tm_initial=tm.evaluate("() => ({stored:document.getElementById('t')?.textContent||'',finished:document.getElementById('f')?.textContent||'',logs:document.getElementById('logs')?.innerText||''})")
    if tme or tm_initial['stored']!='1' or tm_initial['finished']!='1' or 'success' not in tm_initial['logs'].lower(): raise AssertionError(json.dumps({'errors':tme,'tournament':tm_initial},indent=2))
    tm.evaluate("() => document.getElementById('status')?.click()");tm.wait_for_timeout(120)
    tm_refresh=tm.evaluate("() => ({result:document.getElementById('result')?.textContent||'',writes:window.__tournamentWrites||[]})")
    if 'refreshed' not in tm_refresh['result'].lower() or not tm_refresh['writes'] or tm_refresh['writes'][-1]['kind']!='tournaments-update': raise AssertionError(json.dumps(tm_refresh,indent=2))

    browser.close(); print(json.dumps({'dashboard':dash,'adminHidden':admin_hidden,'acamr':ar,'writeKinds':kinds,'taskControl':task_view,'tournamentManagement':tm_refresh,'pageErrors':0},indent=2))

if __name__=='__main__': main()
