#!/usr/bin/env python3
import json
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
CHROMIUM='/usr/bin/chromium'
BOOTSTRAP=r'''
window.__bgActive=0;window.__fgActive=0;window.__maxBg=0;window.__maxTotal=0;window.__fgStartDelay=-1;window.__interactiveEnqueueAt=0;window.__calls=[];
const NativeURL=window.URL;function SafeURL(input,base){return new NativeURL(input,(!base||String(base)==='about:blank')?'https://p2k.test/ui-v2.html?oauth=2':base)}SafeURL.prototype=NativeURL.prototype;window.URL=SafeURL;
window.P2K_SITE_CONFIG={clubSlug:'promote-to-king',api:{jsonpFallback:false,defaultAttempts:1,defaultConcurrency:5,maximumConcurrency:12,requestTimeoutMs:1000}};
window.P2K_AUTH={enabled:true,realOAuth:true,getCsrf(){return'x'},getSession(){return{username:'X',realOAuth:true,oauthVerified:true}}};
window.fetch=async(input,options={})=>{const url=String(input);
 if(url.includes('/server/team-points/public/oauth.php')&&url.includes('action=batch')){
   const q=JSON.parse(String(options.body||'{}'));const traffic=q.traffic_class==='background'?'background':'foreground';
   if(traffic==='background')window.__bgActive++;else{window.__fgActive++;if(window.__fgStartDelay<0)window.__fgStartDelay=Date.now()-window.__interactiveEnqueueAt;}
   window.__maxBg=Math.max(window.__maxBg,window.__bgActive);window.__maxTotal=Math.max(window.__maxTotal,window.__bgActive+window.__fgActive);
   window.__calls.push({traffic,size:(q.requests||[]).length,concurrency:q.concurrency,rate:q.rate_cps,at:Date.now()});
   await new Promise(r=>setTimeout(r,traffic==='background'?700:180));
   const results=(q.requests||[]).map(row=>({id:String(row.id||''),url:String(row.url||''),status:200,status_text:'OK',headers:{'content-type':'application/json'},body:JSON.stringify({ok:true,url:row.url}),elapsed_ms:traffic==='background'?650:120}));
   if(traffic==='background')window.__bgActive--;else window.__fgActive--;
   return new Response(JSON.stringify({ok:true,mode:'oauth-bearer',results,processed:results.length,successes:results.length,rate_429:0,errors:0,elapsed_ms:200,cps:30,launch_cps:30,rate_cps:30,requested_rate_cps:q.rate_cps,requested_concurrency:q.concurrency,concurrency:q.concurrency,peak_in_flight:q.concurrency,transport_cap:256,median_latency_ms:traffic==='background'?650:120,p95_latency_ms:traffic==='background'?700:140,endpoint_class:'player-profile',traffic_class:traffic,controller:{rate_target_cps:30,safe_rate_cps:30,unsafe_rate_cps:0,reason:'demand-limited-hold',latency_baseline_ms:{'player-profile':120}}}),{status:200,headers:{'content-type':'application/json'}});
 }
 if(url.includes('observe.php'))return new Response(JSON.stringify({ok:true,accepted:0,queued:0,updated:0}),{status:200,headers:{'content-type':'application/json'}});
 if(url.startsWith('https://api.chess.com/'))throw new Error('direct Chess.com bypass');
 return new Response('{}',{status:200,headers:{'content-type':'application/json'}});
};
'''

def main():
  with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage'])
    page=browser.new_page(); errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<!doctype html><meta charset="utf-8"><title>P0</title>')
    page.add_script_tag(content=BOOTSTRAP)
    page.add_script_tag(path=str(ROOT/'assets/js/shared/api-client.js'))
    page.evaluate('P2K_API_CLIENT.setOAuthBearerMode(true)')
    initial=page.evaluate('P2K_API_CLIENT.diagnostics()')
    assert initial['oauthGatewayRateTarget']==30,initial
    assert initial['oauthGatewayTarget'] in (3,8),initial
    assert initial['oauthForegroundReservedPosts']==1,initial
    # Seven background waves worth of work. Only five POSTs may be active because one
    # physical gateway lane is permanently reserved for interactive work.
    page.evaluate('''()=>{window.__bgPromise=Promise.all(Array.from({length:224},(_,i)=>P2K_API_CLIENT.jsonDetailed(`https://api.chess.com/pub/player/bg-${i}`,{cacheMode:'network-only',attempts:1,trafficClass:'background',priority:-200})));}''')
    page.wait_for_function('window.__bgActive===5',timeout=5000)
    assert page.evaluate('window.__maxBg')==5,page.evaluate('window.__calls')
    # Enqueue one user request while every background-admissible lane is saturated.
    result=page.evaluate('''async()=>{window.__interactiveEnqueueAt=Date.now();const p=P2K_API_CLIENT.jsonDetailed('https://api.chess.com/pub/player/interactive-user',{cacheMode:'network-only',attempts:1,priority:500});await p;return{delay:window.__fgStartDelay,diag:P2K_API_CLIENT.diagnostics(),maxBg:window.__maxBg,maxTotal:window.__maxTotal};}''')
    assert result['delay']>=0 and result['delay']<=250,result
    assert result['maxBg']<=5,result
    assert result['maxTotal']>=6,result
    assert result['diag']['oauthForegroundWaitMaxMs']<=250,result['diag']
    assert result['diag']['oauthBackgroundAdmissionSuppressions']>0,result['diag']
    # Let background resume only after interactive protection clears.
    page.evaluate('window.__bgPromise')
    final=page.evaluate('P2K_API_CLIENT.diagnostics()')
    assert final['oauthGatewayForegroundQueued']==0 and final['oauthGatewayBackgroundQueued']==0,final
    assert not errors,errors
    print(json.dumps({'initialRate':initial['oauthGatewayRateTarget'],'initialCap':initial['oauthGatewayTarget'],'reservedForegroundPosts':initial['oauthForegroundReservedPosts'],'maxBackgroundPosts':result['maxBg'],'maxTotalPosts':result['maxTotal'],'interactiveGatewayDelayMs':result['delay'],'maxForegroundWaitMs':result['diag']['oauthForegroundWaitMaxMs'],'backgroundSuppressions':result['diag']['oauthBackgroundAdmissionSuppressions'],'pageErrors':0},indent=2))
    browser.close()
if __name__=='__main__': main()
