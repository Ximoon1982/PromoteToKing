#!/usr/bin/env python3
import json
import re
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = '/usr/bin/chromium'

HTML = (ROOT/'MatchCreationAnalyzer.htm').read_text(encoding='utf-8')
HTML = re.sub(r'<script\b[^>]*>.*?</script>', '', HTML, flags=re.S|re.I)
HTML = re.sub(r'<meta[^>]+Content-Security-Policy[^>]*>', '', HTML, flags=re.I)

BOOTSTRAP = r'''
window.__gateway=[]; window.__direct=[]; window.__serverCalls=0;
const NativeURL=window.URL;
function SafeURL(input,base){return new NativeURL(input,(!base||String(base)==='about:blank')?'https://p2k.test/MatchCreationAnalyzer.htm?oauth=2':base)}
SafeURL.prototype=NativeURL.prototype;
for(const k of ['createObjectURL','revokeObjectURL','canParse']) if(NativeURL[k]) SafeURL[k]=NativeURL[k].bind(NativeURL);
window.URL=SafeURL;
window.P2K_SITE_CONFIG={clubSlug:'promote-to-king',leagueAcronyms:[],api:{jsonpFallback:false,defaultAttempts:1,defaultConcurrency:5,maximumConcurrency:12}};
window.P2K_AUTH={enabled:true,realOAuth:true,getCsrf(){return 'x'},getSession(){return {username:'X',realOAuth:true,oauthVerified:true}}};
const summaries=Array.from({length:80},(_,i)=>({'@id':`https://api.chess.com/pub/match/${1000+i}`,name:`Test ${i}`,time_class:'daily',opponent:'https://api.chess.com/pub/club/opponent-'+i,start_time:Math.floor(Date.now()/1000)+86400}));
window.fetch=async(input,options={})=>{const url=String(input);
 if(url.includes('/server/team-points/public/oauth.php')&&url.includes('action=batch')){
   const q=JSON.parse(String(options.body||'{}')); const reqs=Array.isArray(q.requests)?q.requests:[];
   const ceiling=Number(q.rate_cps||0); const conc=Number(q.concurrency||1); const isDetail=reqs.some(x=>String(x.url||'').includes('/pub/match/'));
   const latency=isDetail?2500:120; window.__serverCalls += 1; const learnedRate=isDetail&&window.__serverCalls>=3?9:8; const cps=learnedRate;
   window.__gateway.push({size:reqs.length,rate:learnedRate,ceiling,conc,isDetail,cps,latency});
   const results=reqs.map(row=>{const u=String(row.url||''); let body;
     if(u.includes('/pub/club/promote-to-king/matches')) body={registered:summaries,in_progress:[],finished:[]};
     else body={'@id':u,name:'Detail',time_class:'daily',start_time:Math.floor(Date.now()/1000)+86400,boards:10,teams:{'promote-to-king':{'@id':'https://api.chess.com/pub/club/promote-to-king',score:0,players:[]},opponent:{'@id':'https://api.chess.com/pub/club/opponent',score:0,players:[]}}};
     return {id:String(row.id||''),url:u,status:200,status_text:'OK',headers:{'content-type':'application/json'},body:JSON.stringify(body),elapsed_ms:latency};
   });
   return new Response(JSON.stringify({ok:true,mode:'oauth-bearer',results,processed:results.length,successes:results.length,rate_429:0,errors:0,elapsed_ms:Math.max(1,results.length/(cps||1)*1000),cps,launch_cps:cps,requested_rate_cps:ceiling,rate_cps:learnedRate,requested_concurrency:conc,concurrency:conc,peak_in_flight:Math.min(conc,Math.ceil(learnedRate*latency/1000)),transport_cap:256,retry_after_seconds:0,median_latency_ms:latency,p95_latency_ms:latency*1.1,http2_seen:true,curl_http2_capable:true,endpoint_class:isDetail?'match-detail':'club-index',controller:{rate_target_cps:learnedRate,safe_rate_cps:learnedRate,unsafe_rate_cps:0,latency_baseline_ms:isDetail?{'club-index':120,'match-detail':2500}:{'club-index':120},reason:'test-server-sync'}}),{status:200,headers:{'content-type':'application/json'}});
 }
 if(url.startsWith('https://api.chess.com/')){window.__direct.push(url);return new Response('{}',{status:200});}
 if(url.includes('observe.php')) return new Response(JSON.stringify({ok:true,accepted:0,queued:0,updated:0}),{status:200,headers:{'content-type':'application/json'}});
 return new Response('{}',{status:200,headers:{'content-type':'application/json'}});
};
'''

def main():
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage'])
        page=browser.new_page(); errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content(HTML)
        page.add_script_tag(content=BOOTSTRAP)
        page.add_script_tag(path=str(ROOT/'assets/js/shared/api-request-semantics.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-transport.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-client.js'))
        page.evaluate('window.P2K_API_CLIENT.setOAuthBearerMode(true)')
        page.add_script_tag(path=str(ROOT/'assets/js/pages/match-creation-analyzer.js'))
        page.wait_for_function("document.getElementById('p2kCreationAnalyzeButton') && !document.getElementById('p2kCreationAnalyzeButton').disabled",timeout=10000)
        page.click('#p2kCreationAnalyzeButton')
        page.wait_for_function("document.getElementById('p2kCreationAnalyzeButton').textContent.includes('Refresh')",timeout=10000)
        assert not errors, errors
        batches=page.evaluate('window.__gateway')
        details=[row for row in batches if row['isDetail']]
        assert details and details[0]['conc'] == 3, details
        assert abs(details[0]['rate'] - 8) < 0.01 and abs(details[0]['cps'] - 8) < 0.01, details[0]
        assert details[0]['ceiling'] == 120, details[0]
        assert min(row['rate'] for row in details) >= 8, details
        assert max(row['rate'] for row in details) >= 9, details
        diag=page.evaluate('window.P2K_API_CLIENT.diagnostics()')
        assert diag['oauthGatewayUnsafeRateTarget'] == 0, diag
        assert diag['oauthGatewayLatencyByClass']['club-index'] == 120, diag
        assert diag['oauthGatewayLatencyByClass']['match-detail'] == 2500, diag
        assert page.evaluate('window.__direct') == [], page.evaluate('window.__direct')
        browser.close()
        print(json.dumps({'detailBatches':details,'finalRate':diag['oauthGatewayRateTarget'],'latencyBaselines':diag['oauthGatewayLatencyByClass'],'pageErrors':0},indent=2))

if __name__=='__main__':
    main()
