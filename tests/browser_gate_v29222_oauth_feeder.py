#!/usr/bin/env python3
from pathlib import Path
import json
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
CHROMIUM='/usr/bin/chromium'

def main():
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage'])
        page=browser.new_page()
        errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content('<!doctype html><html><head></head><body></body></html>')
        page.evaluate("""()=>{
          window.P2K_SITE_CONFIG={api:{allowedOrigins:['https://api.chess.com'],oauthGatewayEndpoint:'https://p2k.local/server/team-points/public/oauth.php'}};
          window.P2K_AUTH={realOAuth:true,getCsrf:()=> 'csrf',getSession:()=>({realOAuth:true,oauthVerified:true,authMode:'real-oauth'})};
          window.P2K_REAL_OAUTH_READY=Promise.resolve(true);
          window.P2K_API_CACHE={get:async()=>null,getMany:async()=>[],put:async()=>{},remove:async()=>{},policyFor:()=>({freshFor:0,usableFor:0,staleIfErrorFor:0,networkPreferred:true})};
          window.__gateway=[]; window.__active=0; window.__maxActive=0;
          const realFetch=window.fetch.bind(window);
          window.fetch=async(input,options={})=>{
            const url=String(input);
            if(url.includes('oauth.php')&&url.includes('action=batch')){
              const q=JSON.parse(String(options.body||'{}')); const reqs=q.requests||[];
              window.__active++; window.__maxActive=Math.max(window.__maxActive,window.__active);
              window.__gateway.push({size:reqs.length,concurrency:q.concurrency,rate:q.rate_cps});
              await new Promise(r=>setTimeout(r,80));
              const results=reqs.map(r=>({id:String(r.id),url:String(r.url),status:200,status_text:'OK',headers:{'content-type':'application/json'},body:JSON.stringify({status:'finished','@id':r.url,boards:10}),elapsed_ms:900}));
              window.__active--;
              return new Response(JSON.stringify({ok:true,mode:'oauth-bearer',results,processed:results.length,successes:results.length,rate_429:0,errors:0,elapsed_ms:1000,cps:26.5,launch_cps:26.5,requested_rate_cps:q.rate_cps,rate_cps:26.7,requested_concurrency:q.concurrency,concurrency:q.concurrency,peak_in_flight:q.concurrency,
                // Legacy broken server semantics: tiny batch reported cap=2. Hotfix must ignore it as a ceiling.
                transport_cap:2,median_latency_ms:900,p95_latency_ms:1100,endpoint_class:'match-detail',traffic_class:'background',controller:{rate_target_cps:26.7,safe_rate_cps:26.7,unsafe_rate_cps:0,reason:'gate'}}),{status:200,headers:{'content-type':'application/json'}});
            }
            return realFetch(input,options);
          };
        }""")
        page.add_script_tag(path=str(ROOT/'assets/js/shared/api-request-semantics.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-transport.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-request-coordinator.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-client.js'))
        result=page.evaluate("""async()=>{
          P2K_API_CLIENT.setOAuthBearerMode(true);
          const urls=Array.from({length:96},(_,i)=>`https://api.chess.com/pub/match/${800000+i}`);
          const batch=await P2K_API_CLIENT.processPriority(urls,u=>P2K_API_CLIENT.json(u,{cacheMode:'network-only',networkOnly:true,trafficClass:'background',attempts:1}),{concurrency:128,getKey:u=>u});
          await new Promise(r=>setTimeout(r,50));
          return {settled:batch.settled,failed:batch.failures.length,diag:P2K_API_CLIENT.diagnostics(),gateway:window.__gateway,maxActive:window.__maxActive};
        }""")
        assert result['settled']==96 and result['failed']==0,result
        assert result['diag']['oauthGatewayMax']==256,result
        assert result['diag']['oauthTransportCapacity']==256,result
        assert result['diag']['oauthLogicalFeederConcurrency']==256,result
        assert result['diag']['oauthGatewayTarget']>=8,result
        assert result['maxActive']>=2,result
        assert len(result['gateway'])>=3,result
        assert max(x['size'] for x in result['gateway'])>=16,result
        assert not errors,errors
        print(json.dumps({'settled':result['settled'],'maxActiveGatewayPosts':result['maxActive'],'gatewayCalls':len(result['gateway']),'gatewayTarget':result['diag']['oauthGatewayTarget'],'transportCapacity':result['diag']['oauthTransportCapacity'],'pageErrors':0},indent=2))
        browser.close()
if __name__=='__main__': main()
