#!/usr/bin/env python3
import json, os, threading
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]

class Quiet(SimpleHTTPRequestHandler):
    def log_message(self, *_): pass

def main():
    os.chdir(ROOT)
    server = ThreadingHTTPServer(("127.0.0.1", 0), Quiet)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    calls = []
    init = r'''
      window.P2K_ADMIN_ACCESS_READY=Promise.resolve(true);
      window.P2K_SITE_CONFIG={clubSlug:"promote-to-king",api:{defaultAttempts:1}};
      const match={id:777,name:"P2K eligibility test",status:"registration",settings:{rules:"chess",min_rating:1200,max_rating:1600},teams:{team1:{name:"Promote to King","@id":"https://api.chess.com/pub/club/promote-to-king",players:[{username:"Already",rating:1400}]},team2:{name:"Rivals","@id":"https://api.chess.com/pub/club/rivals",players:[]}}};
      const live={eligible:{last_online:Math.floor(Date.now()/1000)-3600,timeout:2,games:3},offline:{last_online:Math.floor(Date.now()/1000)-172800,timeout:1,games:1},timeout:{last_online:Math.floor(Date.now()/1000)-60,timeout:8,games:2},broken:{error:"fixture unavailable"}};
      window.__p2kCalls=[];
      window.P2K_API_CLIENT={
        userMessage:e=>e.message,
        json:async url=>{window.__p2kCalls.push(url);if(url.includes('/pub/match/777'))return match;if(url.includes('/pub/club/rivals/members'))return {weekly:[{username:"Opponent"}],monthly:[],all_time:[]};const m=url.match(/player\/([^/]+)/),key=m&&decodeURIComponent(m[1]).toLowerCase(),x=live[key];if(!x)throw Error('unexpected '+url);if(x.error)throw Error(x.error);if(url.endsWith('/stats'))return {chess_daily:{record:{timeout_percent:x.timeout}}};if(url.endsWith('/games'))return {games:Array(x.games).fill({})};return {username:key,last_online:x.last_online};},
        processPriority:async(items,worker,options)=>{const succeeded=[],failures=[];for(let i=0;i<items.length;i++){try{succeeded.push({item:items[i],index:i,value:await worker(items[i])})}catch(error){failures.push({item:items[i],index:i,error})}}return {succeeded,failures,pending:[],cancelled:false,get partialValues(){return succeeded.map(x=>x.value)}};}
      };
    '''
    pool={"ok":True,"summary":{"rated":7},"rows":[
      {"username":"Eligible","username_key":"eligible","rating":1500,"rating_updated_at":"2026-09-11T12:00:00Z"},
      {"username":"Offline","username_key":"offline","rating":1450},
      {"username":"Timeout","username_key":"timeout","rating":1400},
      {"username":"Broken","username_key":"broken","rating":1350},
      {"username":"Already","username_key":"already","rating":1500},
      {"username":"Opponent","username_key":"opponent","rating":1500},
      {"username":"Low","username_key":"low","rating":900}]}
    try:
      with sync_playwright() as p:
        browser=p.chromium.launch(headless=True)
        page=browser.new_page()
        page.add_init_script(init)
        page.route("**/assets/js/shared/**",lambda route: route.fulfill(status=200,content_type="application/javascript",body=""))
        page.route("**/config/site-branding.js*",lambda route: route.fulfill(status=200,content_type="application/javascript",body=""))
        page.route("**/assets/js/site-config.js*",lambda route: route.fulfill(status=200,content_type="application/javascript",body=""))
        page.route("**/server/team-points/public/recruitment-pool.php*",lambda route: route.fulfill(status=200,content_type="application/json",body=json.dumps(pool)))
        page.goto(f"http://127.0.0.1:{server.server_port}/RecruitMatch.html?match=777",wait_until="networkidle")
        page.wait_for_selector("#p2kSettingsPanel:not([hidden])")
        page.click("#p2kScanButton")
        page.wait_for_function("document.querySelector('#p2kStatusText').textContent.includes('Scan complete')")
        assert page.locator(".p2k-result-card.p2k-good .p2k-result-card-value").inner_text()=="1"
        assert page.locator("#p2kResultBody tr").count()==1
        assert "Eligible" in page.locator("#p2kResultBody").inner_text()
        assert "1 candidate(s) could not be verified" in page.locator(".p2k-partial-note").inner_text()
        assert "eligible" in page.locator("#p2kFunnel").inner_text().lower()
        api_calls=page.evaluate("window.__p2kCalls")
        assert sum("/pub/club/rivals/members" in x for x in api_calls)==1
        assert not any("/player/low" in x or "/player/already" in x or "/player/opponent" in x for x in api_calls)
        assert all("api.chess.com/pub/player/" in x for x in api_calls if "/player/" in x)
        with page.expect_download() as download:
            page.click("#p2kCsv")
        assert download.value.suggested_filename=="p2k-match-777-eligible.csv"
        browser.close()
    finally:
      server.shutdown()
    print("Recruit Match v2 browser gate passed.")

if __name__ == "__main__": main()
