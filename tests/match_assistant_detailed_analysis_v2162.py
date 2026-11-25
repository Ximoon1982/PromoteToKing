#!/usr/bin/env python3
"""Browser regression: real Match Assistant click, modal core, fixture-only network."""
from pathlib import Path
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from threading import Thread
import functools,json,os,shutil,time
from playwright.sync_api import sync_playwright, expect

ROOT=Path(__file__).resolve().parents[1]
ID='2019768'
URL='https://api.chess.com/pub/match/'+ID
match={'@id':URL,'url':'https://www.chess.com/club/matches/'+ID,'name':'TCMAC regression match','time_class':'daily','start_time':int(time.time())+86400,'settings':{'rules':'chess','time_class':'daily','min_rating':1000,'max_rating':2000,'min_team_players':2,'max_team_players':5},'teams':{'team1':{'@id':'https://api.chess.com/pub/club/promote-to-king','name':'Promote to King','locked':False,'players':[{'username':'one','rating':1500},{'username':'two','rating':1400}]},'team2':{'@id':'https://api.chess.com/pub/club/fixture-opponent','name':'Fixture Opponent','locked':False,'players':[{'username':'three','rating':1600},{'username':'four','rating':1550},{'username':'five','rating':1300}]}}}
api= "const fixture="+json.dumps(match)+";window.P2K_API_CLIENT={json:async u=>{if(u.endsWith('/match/"+ID+"'))return fixture;if(u.endsWith('/club/promote-to-king/matches'))return {registered:[{'@id':fixture['@id'],time_class:'daily'}]};if(u.endsWith('/clubs'))return {clubs:[{'@id':'https://api.chess.com/pub/club/promote-to-king'}]};if(u.includes('/pub/player/'))return {username:'fixture-user'};throw Error('Unexpected fixture API '+u)},processPriority:async(items,worker)=>({succeeded:await Promise.all(items.map(async(item,index)=>({index,value:await worker(item,index)}))),failures:[],cancelled:false})};"
class Quiet(SimpleHTTPRequestHandler):
    def log_message(self,*a): pass
server=ThreadingHTTPServer(('127.0.0.1',0),functools.partial(Quiet,directory=str(ROOT)))
Thread(target=server.serve_forever,daemon=True).start()
origin='http://127.0.0.1:'+str(server.server_port)
shell='<!doctype html><html><head><link rel="stylesheet" href="/assets/css/detailed-analysis-host.css"></head><body><iframe id="assistant" style="width:100%;height:820px" src="/FindMatch.htm"></iframe><script src="/assets/js/shared/detailed-analysis-host.js"></script></body></html>'
def route(r):
    url=r.request.url
    if not url.startswith(origin+'/'): return r.abort()
    path=url.split('?',1)[0]
    if path.endswith('/test-shell.html'): return r.fulfill(content_type='text/html',body=shell)
    if path.endswith('/assets/js/shared/api-client.js'): return r.fulfill(content_type='application/javascript',body=api)
    if any(path.endswith('/assets/js/shared/'+s+'.js') for s in ['real-oauth','simulated-oauth','authenticated-member-refresh','analytics','analysis-coordinator','tab-activity']): return r.fulfill(content_type='application/javascript',body='/* unrelated integration disabled for isolated browser regression */')
    if path.endswith('/player-stats.php'): return r.fulfill(json={'ok':True,'chess_daily':{'last':{'rating':1700}},'chess960_daily':{'last':{'rating':1600}}})
    if '/api/match-history/' in path: return r.fulfill(json={'snapshots':[{'match':match,'trackedAt':'2026-11-23T10:00:00Z'}]})
    if '/api/' in path or '/server/' in path: return r.fulfill(json={'ok':True})
    return r.continue_()
def verify_analysis(frame):
    expect(frame.locator('.p2k-match-title')).to_have_text(match['name'],timeout=20000)
    expect(frame.locator('.p2k-expanded-details')).to_be_visible()
    expect(frame.locator('table[aria-label="Team registration and strength comparison"]')).to_be_visible()
    expect(frame.locator('table[aria-label="Predicted match result"]')).to_be_visible()
    expect(frame.locator('.p2k-recruitment-main')).to_be_visible()
    expect(frame.locator('.p2k-chart-svg').first).to_be_visible()
    expect(frame.locator('.p2k-history-chart')).to_be_visible(timeout=20000)
    expect(frame.locator('.p2k-chart-svg')).to_have_count(3)
    expect(frame.locator('#p2kCopyChoiceModal')).to_have_count(0)
    frame.locator('.p2k-chart-expand[data-chart-type="cumulative"]').click()
    expect(frame.locator('#p2kChartModal')).to_be_visible()
    frame.locator('#p2kChartModalClose').click()
    expect(frame.locator('#p2kChartModal')).to_be_hidden()
def run(browser,embedded):
    page=browser.new_page(viewport={'width':1280,'height':900})
    errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.route('**/*',route)
    page.goto(origin+('/test-shell.html' if embedded else '/FindMatch.htm'))
    assistant=page.frame_locator('#assistant') if embedded else page
    assistant.locator('#p2kUsername').fill('fixture-user')
    assistant.locator('#p2kSearchButton').click()
    expect(assistant.locator('.match-card')).to_have_count(1,timeout=20000)
    assistant.locator('.recommendation-info').hover()
    expect(assistant.locator('.recommendation-analysis-button')).to_be_visible()
    assistant.locator('.recommendation-analysis-button').click()
    modal='#p2kShellDetailedAnalysisModal' if embedded else '#p2kDetailedAnalysisModal'
    expect(page.locator(modal)).to_be_visible()
    iframe=page.locator(modal+' iframe')
    expect(iframe).to_have_attribute('src',origin+'/AnalyzeMatchModal.html?match='+ID)
    verify_analysis(page.frame_locator(modal+' iframe'))
    page.locator(modal+' button').first.click()
    expect(page.locator(modal)).to_be_hidden()
    expect(assistant.locator('.match-card')).to_have_count(1)
    assert not errors,errors
    page.close()
    print('PASS', 'parent-host embedded' if embedded else 'standalone', 'popover click, selected match, 3 charts, chart expansion, closing and retained results')
with sync_playwright() as p:
    executable=shutil.which('google-chrome') or shutil.which('chromium') or shutil.which('chromium-browser')
    assert executable, 'No Chromium executable'
    browser=p.chromium.launch(executable_path=executable,headless=True,args=['--no-sandbox'])
    for embedded in (False,True): run(browser,embedded)
    page=browser.new_page()
    page.route('**/*',route)
    page.goto(origin+'/AnalyzeMatchModal.html')
    expect(page.locator('#p2kResults')).to_contain_text('missing or invalid')
    page.close()
    browser.close()
server.shutdown()
print('PASS invalid reference is displayed rather than blank')
