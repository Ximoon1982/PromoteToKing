#!/usr/bin/env python3
"""Real browser and protected PHP endpoint regression, fixture-only upstream data."""
from pathlib import Path
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from threading import Thread
from tempfile import TemporaryDirectory
from urllib.request import Request,urlopen
from urllib.error import HTTPError
import functools,json,shutil,time,subprocess,socket
from playwright.sync_api import sync_playwright,expect

ROOT=Path(__file__).resolve().parents[1]
ID='2019768'
URL='https://api.chess.com/pub/match/'+ID
ENDPOINT='/server/team-points/public/detailed-match-analysis.php'
match={'@id':URL,'url':'https://www.chess.com/club/matches/'+ID,'name':'TCMAC regression match','time_class':'daily','start_time':int(time.time())+86400,'settings':{'rules':'chess','time_class':'daily','min_rating':1000,'max_rating':2000,'min_team_players':2,'max_team_players':5},'teams':{'team1':{'@id':'https://api.chess.com/pub/club/promote-to-king','name':'Promote to King','locked':False,'players':[{'username':'one','rating':1500},{'username':'two','rating':1400}]},'team2':{'@id':'https://api.chess.com/pub/club/fixture-opponent','name':'Fixture Opponent','locked':False,'players':[{'username':'three','rating':1600},{'username':'four','rating':1550},{'username':'five','rating':1300}]}}}
api="const fixture="+json.dumps(match)+";window.P2K_API_CLIENT={json:async u=>{if(u.endsWith('/match/"+ID+"'))return fixture;if(u.endsWith('/club/promote-to-king/matches'))return {registered:[{'@id':fixture['@id'],time_class:'daily'}]};if(u.endsWith('/clubs'))return {clubs:[{'@id':'https://api.chess.com/pub/club/promote-to-king'}]};if(u.includes('/pub/player/'))return {username:'fixture-user'};throw Error('Unexpected fixture API '+u)},processPriority:async(items,worker)=>({succeeded:await Promise.all(items.map(async(item,index)=>({index,value:await worker(item,index)}))),failures:[],cancelled:false})};"
# Real endpoint and production Auth / PreviewIsolation classes; only the OAuth session
# and network transport are mocked in a temporary, isolated PHP fixture.
tmp=TemporaryDirectory()
fixture=Path(tmp.name)
target=fixture/ENDPOINT.lstrip('/')
target.parent.mkdir(parents=True)
shutil.copy(ROOT/ENDPOINT.lstrip('/'),target)
src=target.parent.parent/'src'
src.mkdir()
for name in ('Auth.php','PreviewIsolation.php'):shutil.copy(ROOT/'server/team-points/src'/name,src/name)
(src/'bootstrap.php').write_text(r'''<?php
namespace P2K\TeamPoints {
    require_once __DIR__.'/Auth.php';
    require_once __DIR__.'/PreviewIsolation.php';
    final class OAuthSession {
        const SESSION_NAME='P2KOAUTH';
        public static function authenticatedUsername(bool $close=true): string {
            return match($_COOKIE['P2KOAUTH']??'') {
                'admin','outage'=>'admin-user', 'super'=>'super-user', 'member'=>'member-user', default=>''
            };
        }
    }
}
namespace P2K\Shared {
    final class SharedChessGateway {
        public function __construct($pdo,$app) {}
        public function json($url,$options): ?array {
            if (($_COOKIE['P2KOAUTH']??'')==='outage') throw new \RuntimeException('fixture outage');
            if ($url!=='https://api.chess.com/pub/club/promote-to-king') throw new \RuntimeException('unexpected club');
            if ($options['allow_stale_on_error']!==false) throw new \RuntimeException('stale fallback');
            return ['admin'=>['https://api.chess.com/pub/player/admin-user'],'super_admin'=>['super-user']];
        }
    }
}
namespace {
    function p2k_tp_config(): array {return ['app'=>['club_slug'=>'promote-to-king']];}
    if (($_COOKIE['P2KOAUTH']??'')==='preview-admin') {
        putenv('P2K_PREVIEW_ACTIVE=1');putenv('P2K_PREVIEW_USERNAME=preview-user');
        putenv('P2K_PREVIEW_ADMIN_AUTHORIZED=1');putenv('P2K_PREVIEW_ADMIN_USERNAME=preview-user');
    } elseif (($_COOKIE['P2KOAUTH']??'')==='preview-denied') {
        putenv('P2K_PREVIEW_ACTIVE=1');
    }
}
''')
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0));phpport=sock.getsockname()[1]
php=subprocess.Popen(['php','-S',f'127.0.0.1:{phpport}','-t',str(fixture)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
def php_request(query,role):
    headers={'Cookie':'P2KOAUTH='+role} if role else {}
    try:
        response=urlopen(Request(f'http://127.0.0.1:{phpport}'+ENDPOINT+query,headers=headers),timeout=5)
    except HTTPError as e:response=e
    with response:return response.status,response.headers,response.read()
for _ in range(100):
    try:php_request('?access=1','');break
    except OSError:time.sleep(.05)
for role,status in [('',401),('expired',401),('member',403),('admin',200),('super',200),('outage',503),('preview-admin',200),('preview-denied',403)]:
    code,headers,body=php_request('?access=1',role)
    assert code==status,(role,code,body)
    assert json.loads(body)['allowed']==(status==200)
    assert 'no-store' in headers['Cache-Control']
    code,headers,body=php_request('?match='+ID+'&name=admin-user&admin=1',role)
    assert code==status,(role,code)
    assert (b'id="p2kUpcomingAnalyzer"' in body)==(status==200)
    assert 'no-store' in headers['Cache-Control']
    assert (b'name="p2k-oauth-required"' in body)==(status==200 and role!='preview-admin'),role
print('PASS PHP gate: anonymous, expired, member, admin, super admin, outage, authorized and denied preview; query spoofing does not grant access')

class Quiet(SimpleHTTPRequestHandler):
    def log_message(self,*a):pass
server=ThreadingHTTPServer(('127.0.0.1',0),functools.partial(Quiet,directory=str(ROOT)))
Thread(target=server.serve_forever,daemon=True).start()
origin='http://127.0.0.1:'+str(server.server_port)
shell='<!doctype html><html><head><link rel="stylesheet" href="/assets/css/detailed-analysis-host.css"></head><body><iframe id="assistant" style="width:100%;height:820px" src="/FindMatch.htm"></iframe><script src="/assets/js/shared/detailed-analysis-host.js"></script></body></html>'
def route(r,role):
    url=r.request.url
    if not url.startswith(origin+'/'):
        if len(role)>1 and 'api.chess.com/' in url:role[1].append(('public',url))
        return r.abort()
    path=url.split('?',1)[0]
    if path.endswith(ENDPOINT):
        query=url[len(path):]
        code,headers,body=php_request(query,role[0])
        return r.fulfill(status=code,headers={k:headers[k] for k in ('Content-Type','Cache-Control','Content-Security-Policy','X-Frame-Options')},body=body)
    detail=ENDPOINT in r.request.frame.url or ENDPOINT in r.request.headers.get('referer','')
    if detail and path.endswith('/oauth.php'):
        from urllib.parse import urlsplit,parse_qs
        action=parse_qs(urlsplit(url).query).get('action',[''])[0]
        if action=='session':
            signed=not (len(role)>2 and role[2])
            return r.fulfill(json={'ok':True,'enabled':True,'authenticated':signed,'profile':{'username':'admin-user'} if signed else None,'csrf':'fixture-oauth'})
        if action=='batch':
            assert r.request.headers.get('x-p2k-oauth-csrf')=='fixture-oauth'
            payload=r.request.post_data_json
            assert payload['rate_cps']<=30
            rows=[]
            for req in payload['requests']:
                u=req['url']
                data=match if u.endswith('/match/'+ID) else {'registered':[{'@id':URL,'time_class':'daily'}]} if u.endswith('/matches') else {'clubs':[{'@id':'https://api.chess.com/pub/club/promote-to-king'}]} if u.endswith('/clubs') else {'username':'fixture-user'}
                rows.append({'id':req['id'],'url':u,'status':200,'body':json.dumps(data),'headers':{'content-type':'application/json'}})
            if len(role)>1:role[1].append(('bearer',len(rows)))
            return r.fulfill(json={'ok':True,'results':rows,'controller':{'rate_target_cps':24,'safe_rate_cps':24,'rate_ceiling_cps':30}})
    if detail and any(path.endswith('/assets/js/shared/'+x+'.js') for x in ('api-client','real-oauth')):return r.continue_()
    if path.endswith('/test-shell.html'):return r.fulfill(content_type='text/html',body=shell)
    if path.endswith('/assets/js/shared/api-client.js'):return r.fulfill(content_type='application/javascript',body=api)
    if any(path.endswith('/assets/js/shared/'+s+'.js') for s in ['real-oauth','simulated-oauth','authenticated-member-refresh','analytics','analysis-coordinator','tab-activity']):return r.fulfill(content_type='application/javascript',body='/* fixture */')
    if path.endswith('/player-stats.php'):return r.fulfill(json={'ok':True,'chess_daily':{'last':{'rating':1700}},'chess960_daily':{'last':{'rating':1600}}})
    if '/api/match-history/' in path:return r.fulfill(json={'snapshots':[{'match':match,'trackedAt':'2026-11-23T10:00:00Z'}]})
    if '/api/' in path or '/server/' in path:return r.fulfill(json={'ok':True})
    return r.continue_()
def verify_analysis(frame):
    expect(frame.locator('.p2k-match-title')).to_have_text(match['name'],timeout=20000)
    expect(frame.locator('.p2k-expanded-details')).to_be_visible()
    expect(frame.locator('table[aria-label="Team registration and strength comparison"]')).to_be_visible()
    expect(frame.locator('table[aria-label="Predicted match result"]')).to_be_visible()
    expect(frame.locator('.p2k-recruitment-main')).to_be_visible()
    expect(frame.locator('.p2k-history-chart')).to_be_visible(timeout=20000)
    expect(frame.locator('.p2k-chart-svg')).to_have_count(3)
    expect(frame.locator('#p2kCopyChoiceModal')).to_have_count(0)
    frame.locator('.p2k-chart-expand[data-chart-type="cumulative"]').click()
    expect(frame.locator('#p2kChartModal')).to_be_visible()
    frame.locator('#p2kChartModalClose').click()
    expect(frame.locator('#p2kChartModal')).to_be_hidden()
def run(browser,embedded,role,allowed,query=''):
    page=browser.new_page(viewport={'width':1280,'height':900})
    errors=[];state=[role,[]]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.route('**/*',lambda r:route(r,state))
    page.goto(origin+('/test-shell.html' if embedded else '/FindMatch.htm'+query))
    assistant=page.frame_locator('#assistant') if embedded else page
    assistant.locator('#p2kUsername').fill('fixture-user')
    assistant.locator('#p2kSearchButton').click()
    expect(assistant.locator('.match-card')).to_have_count(1,timeout=20000)
    assistant.locator('.recommendation-info').hover()
    expect(assistant.locator('.recommendation-info-tooltip')).to_be_visible()
    expect(assistant.locator('.recommendation-info-tooltip')).to_contain_text('Score calculation')
    if allowed:
        expect(assistant.locator('.recommendation-analysis-button')).to_be_visible()
        assistant.locator('.recommendation-analysis-button').click()
        modal='#p2kShellDetailedAnalysisModal' if embedded else '#p2kDetailedAnalysisModal'
        expect(page.locator(modal)).to_be_visible()
        expect(page.locator(modal+' iframe')).to_have_attribute('src',origin+'/AnalyzeMatchModal.html?match='+ID)
        verify_analysis(page.frame_locator(modal+' iframe'))
        assert any(k=='bearer' for k,v in state[1]),state
        assert not any(k=='public' for k,v in state[1]),state
        page.locator(modal+' button').first.click()
        expect(page.locator(modal)).to_be_hidden()
        expect(assistant.locator('.match-card')).to_have_count(1)
        state[0]='member'
        assistant.locator('body').evaluate("(el)=>el.ownerDocument.defaultView.dispatchEvent(new CustomEvent('p2k-auth-change',{detail:null}))")
        expect(assistant.locator('.recommendation-analysis-button')).to_have_count(0)
    else:
        expect(assistant.locator('.recommendation-analysis-button')).to_have_count(0)
        assistant.locator('body').evaluate("(el)=>el.ownerDocument.defaultView.p2kOpenDetailedAnalysis('2019768')")
        expect(page.locator('#p2kDetailedAnalysisModal,#p2kShellDetailedAnalysisModal')).to_have_count(0)
    assert not errors,errors
    page.close()
    print('PASS assistant',role or 'anonymous','embedded' if embedded else 'standalone',query,'allowed' if allowed else 'denied')
try:
    with sync_playwright() as p:
        executable=shutil.which('google-chrome') or shutil.which('chromium') or shutil.which('chromium-browser')
        browser=p.chromium.launch(executable_path=executable,headless=True,args=['--no-sandbox'])
        for embedded in (False,True):
            run(browser,embedded,'admin',True)
            run(browser,embedded,'member',False)
        run(browser,False,'',False)
        run(browser,False,'admin',False,'?memberView=1')
        run(browser,False,'admin',False,'?oauth=1')
        run(browser,False,'member',False,'?name=admin-user')
        for role in ('member',''):
            page=browser.new_page();page.route('**/*',lambda r:route(r,[role]))
            page.goto(origin+'/AnalyzeMatchModal.html?match='+ID)
            expect(page.locator('body')).to_contain_text('Administrator access required')
            expect(page.locator('#p2kUpcomingAnalyzer')).to_have_count(0)
            page.close()
        page=browser.new_page();page.route('**/*',lambda r:route(r,['admin']))
        page.goto(origin+'/AnalyzeMatchModal.html')
        expect(page.locator('#p2kResults')).to_contain_text('missing or invalid')
        page.close()
        page=browser.new_page();state=['admin',[],True];page.route('**/*',lambda r:route(r,state))
        page.goto(origin+ENDPOINT+'?match='+ID)
        page.wait_for_function('window.P2K_REAL_OAUTH_READY && window.P2K_API_CLIENT')
        code=page.evaluate("""async () => {await window.P2K_REAL_OAUTH_READY;try {await window.P2K_API_CLIENT.jsonDetailed('https://api.chess.com/pub/match/2019768',{cacheMode:'no-store',attempts:1});return 'unexpected success';} catch(e){return e.code;}}""")
        assert code=='OAUTH_SESSION_NOT_READY',(code,state)
        assert not state[1],state
        page.close()
        browser.close()
finally:
    server.shutdown();php.terminate();php.wait(timeout=5);tmp.cleanup()
print('PASS original direct URL redirects to authorized PHP route; non-admin direct access denied')
