#!/usr/bin/env python3
import json
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
CHROMIUM='/usr/bin/chromium'
BOOT=r'''
window.__batches=[]; window.__active=0; window.__maxActive=0; window.__call=0;
const NativeURL=window.URL;
function SafeURL(input,base){return new NativeURL(input,(!base||String(base)==='about:blank')?'https://p2k.test/TaskControl.html?oauth=2':base)}
SafeURL.prototype=NativeURL.prototype; window.URL=SafeURL;
window.P2K_SITE_CONFIG={api:{jsonpFallback:false,defaultConcurrency:5,maximumConcurrency:12},clubSlug:'promote-to-king'};
window.P2K_AUTH={realOAuth:true,getCsrf(){return 'csrf'},getSession(){return {username:'Ximoon',oauthVerified:true,realOAuth:true,authMode:'real-oauth'}}};
window.P2K_API_CACHE={async get(){return null},async getMany(){return []},policyFor(){return {freshFor:0,usableFor:0,staleIfErrorFor:0,networkPreferred:true}},async put(){},async coordinate(_u,task){return task(null)}};
window.fetch=async(input,options={})=>{
 const url=String(input);
 if(url.includes('/oauth.php')&&url.includes('action=batch')){
   const body=JSON.parse(String(options.body||'{}')); const reqs=Array.isArray(body.requests)?body.requests:[];
   window.__call++; window.__active++; window.__maxActive=Math.max(window.__maxActive,window.__active);
   window.__batches.push({size:reqs.length,concurrency:Number(body.concurrency||0),traffic:String(body.traffic_class||''),active:window.__active});
   await new Promise(r=>setTimeout(r,80));
   window.__active--;
   const results=reqs.map(r=>({id:String(r.id),url:String(r.url),status:200,status_text:'OK',headers:{'content-type':'application/json'},body:JSON.stringify({ok:true}),elapsed_ms:900}));
   const legacyTiny=window.__call===1;
   return new Response(JSON.stringify({ok:true,mode:'oauth-bearer',results,processed:results.length,successes:results.length,rate_429:0,errors:0,elapsed_ms:900,cps:26.7,launch_cps:26.5,rate_cps:26.7,requested_concurrency:Number(body.concurrency||0),concurrency:Number(body.concurrency||0),peak_in_flight:Number(body.concurrency||0),transport_cap:legacyTiny?reqs.length:256,...(legacyTiny?{}:{transport_capacity:256,batch_size:reqs.length}),median_latency_ms:900,p95_latency_ms:1100,endpoint_class:'match-detail',traffic_class:String(body.traffic_class||''),controller:{rate_target_cps:26.7,safe_rate_cps:26.7,unsafe_rate_cps:28.4,latency_baseline_ms:{'match-detail':900},reason:'gate'}}),{status:200,headers:{'content-type':'application/json'}});
 }
 return new Response(JSON.stringify({ok:true}),{status:200,headers:{'content-type':'application/json'}});
};
'''
def main():
 with sync_playwright() as p:
  b=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage'])
  page=b.new_page(); errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
  page.set_content('<html><body></body></html>'); page.add_script_tag(content=BOOT); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-request-semantics.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-client.js'))
  page.evaluate('window.P2K_API_CLIENT.setOAuthBearerMode(true)')
  tiny=page.evaluate('''async()=>{const xs=[1,2].map(i=>`https://api.chess.com/pub/match/${i}`);await window.P2K_API_CLIENT.processPriority(xs,u=>window.P2K_API_CLIENT.json(u,{attempts:1,trafficClass:'background'}),{concurrency:2,getKey:u=>u});return window.P2K_API_CLIENT.diagnostics()}''')
  assert tiny['oauthGatewayMax']==256, tiny
  assert tiny['recommendedBatchConcurrency']==256, tiny
  deep=page.evaluate('''async()=>{window.__batches=[];window.__maxActive=0;const xs=Array.from({length:256},(_,i)=>`https://api.chess.com/pub/match/${1000+i}`);const r=await window.P2K_API_CLIENT.processPriority(xs,u=>window.P2K_API_CLIENT.json(u,{attempts:1,trafficClass:'background'}),{concurrency:256,getKey:u=>u});return {ok:r.succeeded.length,fail:r.failures.length,d:window.P2K_API_CLIENT.diagnostics(),batches:window.__batches,maxActive:window.__maxActive}}''')
  assert not errors, errors
  assert deep['ok']==256 and deep['fail']==0, deep
  assert deep['d']['oauthGatewayMax']==256, deep['d']
  assert deep['d']['oauthGatewayTarget']>=30, deep['d']
  assert deep['d']['recommendedBatchConcurrency']==256, deep['d']
  assert deep['maxActive']>=4, deep
  assert max(x['size'] for x in deep['batches'])==32, deep['batches']
  assert all(x['traffic']=='background' for x in deep['batches']), deep['batches']
  b.close()
  print(json.dumps({'tinyCap':tiny['oauthGatewayMax'],'logicalFeeder':deep['d']['recommendedBatchConcurrency'],'gatewayTarget':deep['d']['oauthGatewayTarget'],'maxActivePosts':deep['maxActive'],'batches':len(deep['batches']),'pageErrors':0},indent=2))
if __name__=='__main__':main()
