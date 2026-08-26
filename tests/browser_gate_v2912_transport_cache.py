#!/usr/bin/env python3
import json,re
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]; CHROMIUM='/usr/bin/chromium'
HTML=(ROOT/'MatchCreationAnalyzer.htm').read_text(encoding='utf-8')
HTML=re.sub(r'<script\b[^>]*>.*?</script>','',HTML,flags=re.S|re.I)
HTML=re.sub(r'<meta[^>]+Content-Security-Policy[^>]*>','',HTML,flags=re.I)
BOOTSTRAP=r'''
window.__gateway=[];window.__direct=[];window.__activeGateway=0;window.__maxActiveGateway=0;
const NativeURL=window.URL;function SafeURL(input,base){return new NativeURL(input,(!base||String(base)==='about:blank')?'https://p2k.test/MatchCreationAnalyzer.htm?oauth=2':base)}SafeURL.prototype=NativeURL.prototype;for(const k of ['createObjectURL','revokeObjectURL','canParse'])if(NativeURL[k])SafeURL[k]=NativeURL[k].bind(NativeURL);window.URL=SafeURL;
window.P2K_SITE_CONFIG={clubSlug:'promote-to-king',leagueAcronyms:[],api:{jsonpFallback:false,defaultAttempts:1,defaultConcurrency:5,maximumConcurrency:12,requestTimeoutMs:1000,cache:{calibrationProposal:{finishedMatchMaximumAgeDays:365}}}};
window.P2K_AUTH={enabled:true,realOAuth:true,getCsrf(){return'x'},getSession(){return{username:'X',realOAuth:true,oauthVerified:true}}};
window.__summaries=Array.from({length:700},(_,i)=>({'@id':`https://api.chess.com/pub/match/${10000+i}`,name:`Long ${i}`,time_class:'daily',opponent:'https://api.chess.com/pub/club/opponent-'+i,start_time:Math.floor(Date.now()/1000)+86400}));
function detail(u){return {'@id':u,name:'Detail',status:'finished',time_class:'daily',start_time:Math.floor(Date.now()/1000)-86400,boards:10,teams:{'promote-to-king':{'@id':'https://api.chess.com/pub/club/promote-to-king',score:5,players:[]},opponent:{'@id':'https://api.chess.com/pub/club/opponent',score:5,players:[]}}};}
window.fetch=async(input,options={})=>{const url=String(input);
 if(url.includes('/server/team-points/public/oauth.php')&&url.includes('action=batch')){const q=JSON.parse(String(options.body||'{}'));const reqs=Array.isArray(q.requests)?q.requests:[];const isDetail=reqs.some(x=>String(x.url||'').includes('/pub/match/'));window.__activeGateway++;window.__maxActiveGateway=Math.max(window.__maxActiveGateway,window.__activeGateway);window.__gateway.push({size:reqs.length,isDetail,traffic:q.traffic_class,rate:q.rate_cps,concurrency:q.concurrency});await new Promise(r=>setTimeout(r,isDetail?1200:80));const results=reqs.map(row=>{const u=String(row.url||'');const body=u.includes('/pub/club/promote-to-king/matches')?{registered:window.__summaries,in_progress:[],finished:[]}:detail(u);return{id:String(row.id||''),url:u,status:200,status_text:'OK',headers:{'content-type':'application/json'},body:JSON.stringify(body),elapsed_ms:isDetail?2500:80}});window.__activeGateway--;return new Response(JSON.stringify({ok:true,mode:'oauth-bearer',results,processed:results.length,successes:results.length,rate_429:0,errors:0,elapsed_ms:isDetail?2500:80,cps:46.5,launch_cps:46.5,rate_cps:46.5,requested_rate_cps:q.rate_cps,requested_concurrency:q.concurrency,concurrency:q.concurrency,peak_in_flight:q.concurrency,transport_cap:256,retry_after_seconds:0,median_latency_ms:isDetail?2500:80,p95_latency_ms:isDetail?2800:100,endpoint_class:isDetail?'match-detail':'club-index',traffic_class:q.traffic_class,controller:{rate_target_cps:46.5,safe_rate_cps:46.5,unsafe_rate_cps:50.7,backlashes:1,clean_samples:20,reason:'boundary-hold',latency_baseline_ms:{'club-index':80,'match-detail':2500}}}),{status:200,headers:{'content-type':'application/json'}})}
 if(url.startsWith('https://api.chess.com/')){window.__direct.push(url);return new Response('{}',{status:200})}
 if(url.includes('observe.php'))return new Response(JSON.stringify({ok:true,accepted:0,queued:0,updated:0}),{status:200,headers:{'content-type':'application/json'}});
 return new Response('{}',{status:200,headers:{'content-type':'application/json'}})};
'''
def main():
  with sync_playwright() as p:
    b=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage']);page=b.new_page();errs=[];page.on('pageerror',lambda e:errs.append(str(e)))
    page.set_content(HTML);page.add_script_tag(content=BOOTSTRAP);page.add_script_tag(path=str(ROOT/'assets/js/shared/api-cache.js'));page.add_script_tag(path=str(ROOT/'assets/js/shared/api-client.js'));page.evaluate('window.P2K_API_CLIENT.setOAuthBearerMode(true)')
    cache_result=page.evaluate('''async()=>{const urls=Array.from({length:200},(_,i)=>`https://api.chess.com/pub/match/${900000+i}`);const old=Date.now()-2*86400000;await Promise.all(urls.slice(0,150).map(u=>P2K_API_CACHE.put({url:u,body:JSON.stringify({status:'finished','@id':u}),status:200,statusText:'OK',headers:{'content-type':'application/json'},etag:'',lastModified:'',fetchedAt:old,transport:'fetch',matchState:'finished'})));window.__gateway=[];const batch=await P2K_API_CLIENT.processPriority(urls,u=>P2K_API_CLIENT.json(u),{getKey:u=>u});await new Promise(r=>setTimeout(r,150));return{settled:batch.settled,diag:P2K_API_CLIENT.diagnostics(),cache:P2K_API_CACHE.diagnostics(),gateway:window.__gateway.slice()}}''')
    assert cache_result['settled']==200,cache_result
    detail_network=sum(x['size'] for x in cache_result['gateway'] if x['isDetail'])
    assert detail_network==50,(detail_network,cache_result['gateway'])
    assert cache_result['diag']['oauthGatewayBackgroundQueued']==0,cache_result['diag']
    # Old finished entries are now ordinary fresh hits, not stale-triggered refreshes.
    assert cache_result['diag']['counters']['cacheHits']>=150,cache_result['diag']['counters']
    page.evaluate('window.__gateway=[];window.__maxActiveGateway=0')
    page.add_script_tag(path=str(ROOT/'assets/js/pages/match-creation-analyzer.js'))
    page.wait_for_function("document.getElementById('p2kCreationAnalyzeButton')&&!document.getElementById('p2kCreationAnalyzeButton').disabled",timeout=10000)
    page.click('#p2kCreationAnalyzeButton')
    page.wait_for_function("document.getElementById('p2kCreationAnalyzeButton').textContent.includes('Refresh')",timeout=30000)
    assert not errs,errs
    batches=page.evaluate('window.__gateway');details=[x for x in batches if x['isDetail']]
    assert sum(x['size'] for x in details)==700,(sum(x['size'] for x in details),details[:3],details[-3:])
    max_active=page.evaluate('window.__maxActiveGateway');assert max_active>=4,max_active
    assert all(x['traffic']=='foreground' for x in details),details[:3]
    assert all(x['rate']==120 for x in details),details[:3]
    diag=page.evaluate('window.P2K_API_CLIENT.diagnostics()')
    assert diag['oauthGatewayRateTarget']==46.5,diag
    assert diag['oauthGatewayUnsafeRateTarget']==50.7,diag
    assert diag['oauthGatewayMaximumActivePosts']>=4,diag
    assert page.evaluate('window.__direct')==[],page.evaluate('window.__direct')
    print(json.dumps({'cacheHits':cache_result['diag']['counters']['cacheHits'],'cacheNetworkMisses':detail_network,'matchCreationDetails':sum(x['size'] for x in details),'gatewayBatches':len(details),'maxActiveGatewayPosts':max_active,'settledRate':diag['oauthGatewayRateTarget'],'pageErrors':0},indent=2));b.close()
if __name__=='__main__':main()
