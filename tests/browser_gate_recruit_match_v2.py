#!/usr/bin/env python3
import json, os, threading, time
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
      const replacementMatch={...match,id:888,name:"Replacement match"};
      const live={eligible:{last_online:Math.floor(Date.now()/1000)-3600,timeout:2,games:3},eligible2:{last_online:Math.floor(Date.now()/1000)-1800,timeout:1,gamesError:true},offline:{last_online:Math.floor(Date.now()/1000)-172800,timeout:1,games:1},timeout:{last_online:Math.floor(Date.now()/1000)-60,timeout:8,games:2},broken:{error:"fixture unavailable"}};
      window.__p2kCalls=[];window.__gamesCompleted=0;window.__gamesAborted=0;
      window.__profileOpens=[];
      window.open=url=>{window.__profileOpens.push(url);return null};
      window.P2K_API_CLIENT={
        userMessage:e=>e.message,
        json:async(url,options={})=>{window.__p2kCalls.push({url,cacheMode:options.cacheMode||'default'});if(url.includes('/pub/match/777'))return match;if(url.includes('/pub/match/888'))return replacementMatch;if(url.includes('/pub/club/rivals/members')){if(window.__failRoster){const error=Error('roster unavailable');error.category='not-found';error.status=404;throw error};return {weekly:[{username:"Opponent"}],monthly:[],all_time:[]}}const m=url.match(/player\/([^/]+)/),key=m&&decodeURIComponent(m[1]).toLowerCase(),x=live[key];if(url.endsWith('/clubs')){if(key==='opponent')return {clubs:[{"@id":"https://api.chess.com/pub/club/rivals"}]};if(key==='broken'&&window.__failRoster)throw Error('player clubs unavailable');return {clubs:[{"@id":"https://api.chess.com/pub/club/not-rivals"}]}}if(!x)throw Error('unexpected '+url);if(url.endsWith('/games')){await new Promise((resolve,reject)=>{const timer=setTimeout(resolve,900);options.signal?.addEventListener('abort',()=>{clearTimeout(timer);window.__gamesAborted++;reject(new DOMException('Aborted','AbortError'))},{once:true})});window.__gamesCompleted++;if(x.gamesError)throw Error('optional games unavailable');return {games:Array(x.games).fill({})}}await new Promise(resolve=>setTimeout(resolve,60));if(x.error)throw Error(x.error);if(key==='eligible'&&window.__staleProfile&&!url.endsWith('/stats')){if(options.cacheMode==='no-store')throw Error('current profile unavailable')}if(key==='eligible'&&window.__staleStats&&url.endsWith('/stats')){if(options.cacheMode==='no-store')throw Error('current stats unavailable')}if(url.endsWith('/stats'))return {chess_daily:{record:{timeout_percent:x.timeout}}};return {username:key,last_online:x.last_online};},
        processPriority:async(items,worker)=>{const settled=await Promise.all(items.map(async(item,index)=>{try{return {ok:true,item,index,value:await worker(item)}}catch(error){return {ok:false,item,index,error}}})),succeeded=settled.filter(x=>x.ok),failures=settled.filter(x=>!x.ok);return {succeeded,failures,pending:[],cancelled:false,get partialValues(){return succeeded.map(x=>x.value)}};}
      };
    '''
    pool={"ok":True,"summary":{"rated":7},"rows":[
      {"username":"Eligible","username_key":"eligible","rating":1500,"rating_updated_at":"2026-09-11T12:00:00Z"},
      {"username":"Eligible2","username_key":"eligible2","rating":1510,"rating_updated_at":"2026-09-11T12:00:00Z"},
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
        pool_state={"rows":pool}
        page.route("**/server/team-points/public/recruitment-pool.php*",lambda route: route.fulfill(status=200,content_type="application/json",body=json.dumps(pool_state["rows"])))
        page.goto(f"http://127.0.0.1:{server.server_port}/RecruitMatch.html?match=777",wait_until="networkidle")
        page.wait_for_selector("#p2kSettingsPanel:not([hidden])")
        started=time.monotonic();page.click("#p2kScanButton")
        page.locator("#p2kStatusText", has_text="Scan complete").wait_for()
        assert time.monotonic()-started < .7
        assert page.evaluate("window.__gamesCompleted")==0
        assert page.locator(".p2k-result-card.p2k-good .p2k-result-card-value").inner_text()=="2"
        assert page.locator("#p2kResultBody tr").count()==2
        assert "Eligible" in page.locator("#p2kResultBody").inner_text()
        assert "—" in page.locator("#p2kResultBody").inner_text()  # optional /games failure did not block eligibility
        assert "1 candidate(s) could not be verified" in page.locator(".p2k-partial-note").inner_text()
        assert "eligible" in page.locator("#p2kFunnel").inner_text().lower()
        # Loading a different match aborts optional enrichment and prevents stale mutation.
        deadline=time.monotonic()+3
        while not page.locator("#p2kScanButton").is_enabled() and time.monotonic()<deadline:
            time.sleep(.02)
        assert page.locator("#p2kScanButton").is_enabled()
        page.fill("#p2kMatchReference", "888"); page.click("#p2kLoadButton")
        page.locator("#p2kStatusText",has_text="Match resolved").wait_for()
        assert "Replacement match" in page.locator("#p2kResults").inner_text()
        assert page.evaluate("window.__gamesAborted") >= 2
        assert page.evaluate("window.__gamesCompleted") == 0
        time.sleep(1)
        assert "Replacement match" in page.locator("#p2kResults").inner_text()
        assert page.evaluate("window.__gamesCompleted") == 0
        page.click("#p2kScanButton")
        page.locator("#p2kStatusText",has_text="Scan complete").wait_for()
        page.locator('[data-profile="Eligible"]').click()
        assert page.evaluate("window.__profileOpens.at(-1)").endswith("/Eligible")
        page.fill("#p2kResultSearch","Eligible2")
        page.locator('[data-profile="Eligible2"]').click()
        assert page.evaluate("window.__profileOpens.at(-1)").endswith("/Eligible2")
        page.fill("#p2kResultSearch","")
        page.locator('th[data-sort="rating"]').click()
        page.locator('[data-profile="Eligible"]').click()
        assert page.evaluate("window.__profileOpens.at(-1)").endswith("/Eligible")
        page.locator('tr:has([data-profile="Eligible"]) td').nth(5).get_by_text("3",exact=True).wait_for(timeout=3000)
        api_calls=page.evaluate("window.__p2kCalls")
        assert sum("/pub/club/rivals/members" in x["url"] for x in api_calls)==2
        assert next(x for x in api_calls if "/pub/club/rivals/members" in x["url"])["cacheMode"]=="no-store"
        assert all(x["cacheMode"]=="no-store" for x in api_calls if "/player/" in x["url"] and not x["url"].endswith("/games"))
        assert not any("/player/low" in x["url"] or "/player/already" in x["url"] or "/player/opponent" in x["url"] for x in api_calls)
        assert all("api.chess.com/pub/player/" in x["url"] for x in api_calls if "/player/" in x["url"])
        with page.expect_download() as download:
            page.click("#p2kCsv")
        assert download.value.suggested_filename=="p2k-match-888-eligible.csv"
        csv=Path(download.value.path()).read_text(encoding="utf-8-sig")
        assert '"3"' in csv and '"Eligible"' in csv
        pool_state["rows"]={"ok":True,"summary":{"rated":1},"rows":[{"username":"Low","username_key":"low","rating":900}]}
        page.reload(wait_until="networkidle");page.wait_for_selector("#p2kSettingsPanel:not([hidden])");page.evaluate("window.__p2kCalls=[]");page.click("#p2kScanButton")
        page.locator("#p2kStatusText",has_text="Scan complete").wait_for()
        assert not any("/pub/club/rivals/members" in x["url"] for x in page.evaluate("window.__p2kCalls"))
        pool_state["rows"]=pool
        page.reload(wait_until="networkidle")
        page.wait_for_selector("#p2kSettingsPanel:not([hidden])")
        page.evaluate("window.__failRoster=true;window.__p2kCalls=[]")
        page.click("#p2kScanButton")
        page.locator("#p2kStatusText",has_text="Scan complete").wait_for()
        failed_calls=page.evaluate("window.__p2kCalls")
        assert sum("/pub/club/rivals/members" in x["url"] for x in failed_calls)==1
        club_calls=[x for x in failed_calls if x["url"].endswith("/clubs")]
        assert len(club_calls)==6
        assert all(x["cacheMode"]=="no-store" for x in club_calls)
        assert not any("/player/opponent/stats" in x["url"] or x["url"].endswith("/player/opponent") for x in failed_calls)
        assert not any("/player/broken/stats" in x["url"] or x["url"].endswith("/player/broken") for x in failed_calls)
        assert page.locator(".p2k-result-card.p2k-good .p2k-result-card-value").inner_text()=="2"
        assert page.locator(".p2k-result-card.p2k-bad .p2k-result-card-value").inner_text()=="1"
        browser.close()
    finally:
      server.shutdown()
    print("Recruit Match v2 browser gate passed.")

if __name__ == "__main__": main()
