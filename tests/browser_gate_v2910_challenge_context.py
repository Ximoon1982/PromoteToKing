import json,re
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
html=(ROOT/'ChallengeListAssistant.html').read_text()
html=re.sub(r'<script\b[^>]*>.*?</script>','',html,flags=re.I|re.S); html=re.sub(r'<meta[^>]+Content-Security-Policy[^>]*>','',html,flags=re.I)
BOOT=r'''
const NativeURL=window.URL; function SafeURL(input,base){return new NativeURL(input,(!base||String(base)==='about:blank')?'https://p2k.test/ChallengeListAssistant.html':base)}; SafeURL.prototype=NativeURL.prototype; for(const k of ['createObjectURL','revokeObjectURL','canParse'])if(NativeURL[k])SafeURL[k]=NativeURL[k].bind(NativeURL); window.URL=SafeURL;
window.P2K_SITE_CONFIG={clubSlug:'promote-to-king',api:{jsonpFallback:false,defaultConcurrency:5,maximumConcurrency:12,oauthGatewayEndpoint:'server/team-points/public/oauth.php'},serverStorage:{opportunisticObservationEndpoint:'server/team-points/public/observe.php'}};
window.fetch=async(input,options={})=>{const url=String(input); if(url.includes('oauth.php')&&url.includes('action=session'))return new Response(JSON.stringify({ok:true,enabled:true,authenticated:true,real_oauth:true,oauth_verified:true,csrf:'x',profile:{username:'Ximoon',realOAuth:true,oauthVerified:true,authMode:'real-oauth',apiConcurrent:true},transport:{batch_available:true,max_concurrency:256}}),{status:200,headers:{'content-type':'application/json'}}); if(url.includes('oauth.php')&&url.includes('action=batch')){const b=JSON.parse(options.body||'{}'); await new Promise(r=>setTimeout(r,300)); return new Response(JSON.stringify({ok:true,mode:'oauth-bearer',results:(b.requests||[]).map(r=>{let body={}; const u=String(r.url); if(u.includes('/club/promote-to-king/matches')) body={registered:[],in_progress:[],finished:[]}; else if(/\/club\/[^/]+\/matches/.test(u)) body={registered:[],in_progress:[],finished:[]}; else if(/\/club\/[^/]+$/.test(u)) body={name:u.split('/').pop(),url:u.replace('/pub/','/')}; return {id:String(r.id),url:u,status:200,status_text:'OK',headers:{'content-type':'application/json'},body:JSON.stringify(body),elapsed_ms:300}}),processed:(b.requests||[]).length,successes:(b.requests||[]).length,rate_429:0,errors:0,elapsed_ms:300,cps:20,requested_concurrency:Number(b.concurrency||1),concurrency:Number(b.concurrency||1),peak_in_flight:Number(b.concurrency||1),transport_cap:256,retry_after_seconds:0,median_latency_ms:300,p95_latency_ms:300}),{status:200,headers:{'content-type':'application/json'}})}; if(url.includes('observe.php'))return new Response(JSON.stringify({ok:true}),{status:200,headers:{'content-type':'application/json'}}); return new Response(JSON.stringify({ok:true}),{status:200,headers:{'content-type':'application/json'}})};
'''
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
 page=b.new_page(); errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
 page.set_content(html); page.add_script_tag(content=BOOT); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-request-semantics.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-client.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/real-oauth.js')); page.add_script_tag(path=str(ROOT/'assets/js/pages/challenge-list-assistant.js'))
 page.evaluate('window.P2K_REAL_OAUTH_READY')
 page.fill('#p2kRecommendationInput','alpha-club\nbeta-club\ngamma-club\ndelta-club')
 page.dispatch_event('#p2kRecommendationInput','input')
 page.click('#p2kRecommendationStart')
 page.wait_for_function("document.querySelector('#p2kRecommendationCurrent').textContent.includes('Analyzing')",timeout=5000)
 current=page.locator('#p2kRecommendationCurrent').inner_text()
 assert 'team' in current and any(x in current for x in ['alpha-club','beta-club','gamma-club','delta-club']),current
 assert not errors,errors
 print(json.dumps({'current':current,'pageErrors':0},indent=2)); b.close()
