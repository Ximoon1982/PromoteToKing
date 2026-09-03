#!/usr/bin/env python3
import json
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = '/usr/bin/chromium'

BOOTSTRAP = r'''
const NativeURL = window.URL;
function SafeURL(input, base) {
  const b = (!base || String(base)==='about:blank') ? 'https://p2k.test/ui-v2.html' : base;
  return new NativeURL(input,b);
}
SafeURL.prototype = NativeURL.prototype;
for (const k of ['createObjectURL','revokeObjectURL','canParse']) if (NativeURL[k]) SafeURL[k]=NativeURL[k].bind(NativeURL);
window.URL = SafeURL;
window.__sessionChecks = 0;
window.__gatewayBatches = [];
window.__directChess = [];
window.__observationPosts = [];
window.P2K_SITE_CONFIG = {
  clubSlug:'promote-to-king',
  api:{jsonpFallback:false,defaultConcurrency:5,maximumConcurrency:12,oauthGatewayEndpoint:'server/team-points/public/oauth.php'},
  serverStorage:{opportunisticObservationEndpoint:'server/team-points/public/observe.php'}
};
window.fetch = async (input, options={}) => {
  const url = String(input);
  if (url.includes('oauth.php') && url.includes('action=session')) {
    window.__sessionChecks++;
    return new Response(JSON.stringify({
      ok:true,enabled:true,authenticated:true,real_oauth:true,oauth_verified:true,csrf:'csrf-persisted',
      profile:{username:'Ximoon',realOAuth:true,oauthVerified:true,authMode:'real-oauth',apiConcurrent:true},
      transport:{batch_available:true,max_concurrency:256}
    }), {status:200,headers:{'content-type':'application/json'}});
  }
  if (url.includes('oauth.php') && url.includes('action=batch')) {
    const body = JSON.parse(String(options.body||'{}'));
    const requests = Array.isArray(body.requests) ? body.requests : [];
    const concurrency = Number(body.concurrency||1);
    window.__gatewayBatches.push({size:requests.length,concurrency});
    return new Response(JSON.stringify({
      ok:true,mode:'oauth-bearer',
      results:requests.map(r=>({id:String(r.id),url:String(r.url),status:200,status_text:'OK',headers:{'content-type':'application/json'},body:JSON.stringify({ok:true}),elapsed_ms:50})),
      processed:requests.length,successes:requests.length,rate_429:0,errors:0,elapsed_ms:1000,cps:concurrency*2,
      requested_concurrency:concurrency,concurrency,peak_in_flight:concurrency,transport_cap:256,retry_after_seconds:0,median_latency_ms:50,p95_latency_ms:75
    }), {status:200,headers:{'content-type':'application/json'}});
  }
  if (url.includes('observe.php')) {
    try { window.__observationPosts.push(JSON.parse(String(options.body||'{}'))); } catch (_) {}
    return new Response(JSON.stringify({ok:true,accepted:0,updated:0,queued:0}), {status:200,headers:{'content-type':'application/json'}});
  }
  if (url.startsWith('https://api.chess.com/')) {
    window.__directChess.push(url);
    return new Response(JSON.stringify({unexpected:true}), {status:200,headers:{'content-type':'application/json'}});
  }
  return new Response(JSON.stringify({ok:true}), {status:200,headers:{'content-type':'application/json'}});
};
'''

def main():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, executable_path=CHROMIUM, args=['--no-sandbox','--disable-dev-shm-usage'])
        page = browser.new_page()
        errors=[]
        page.on('pageerror', lambda e: errors.append(str(e)))
        # Deliberately no ?oauth=2: this simulates returning to a clean URL with a persisted P2KOAUTH cookie/server session.
        page.set_content('<!doctype html><html><body><header class="site-header"></header></body></html>')
        page.add_script_tag(content=BOOTSTRAP)
        page.add_script_tag(path=str(ROOT/'assets/js/shared/api-request-semantics.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-client.js'))
        page.add_script_tag(path=str(ROOT/'assets/js/shared/real-oauth.js'))
        page.evaluate('window.P2K_REAL_OAUTH_READY')
        state = page.evaluate('''async () => {
          await window.P2K_REAL_OAUTH_READY;
          const session = window.P2K_AUTH?.getSession?.();
          const before = window.P2K_API_CLIENT.diagnostics();
          const urls = Array.from({length:64},(_,i)=>`https://api.chess.com/pub/player/persisted-oauth-${i}`);
          const result = await window.P2K_API_CLIENT.processPriority(urls, u => window.P2K_API_CLIENT.json(u,{attempts:1,cacheMode:'network-only'}), {getKey:u=>u});
          const relevant = [
            'https://api.chess.com/pub/club/promote-to-king',
            'https://api.chess.com/pub/club/promote-to-king/members',
            'https://api.chess.com/pub/club/promote-to-king/matches',
            'https://api.chess.com/pub/match/123456',
            'https://api.chess.com/pub/player/ximoon/stats',
            'https://api.chess.com/pub/player/ximoon/matches'
          ];
          await Promise.all(relevant.map(u => window.P2K_API_CLIENT.json(u,{attempts:1,cacheMode:'network-only'})));
          await new Promise(resolve => setTimeout(resolve,350));
          const observed = window.__observationPosts.flatMap(post => Array.isArray(post.observations) ? post.observations.map(o=>o.url) : []);
          return {session,before,after:window.P2K_API_CLIENT.diagnostics(),ok:result.succeeded.length,fail:result.failures.length,checks:window.__sessionChecks,batches:window.__gatewayBatches,direct:window.__directChess,observed,observationBatchSizes:window.__observationPosts.map(p=>p.observations?.length||0),relevant};
        }''')
        assert not errors, errors
        assert state['checks'] >= 1, state
        assert state['session']['username'] == 'Ximoon' and state['session']['realOAuth'] is True, state
        assert state['before']['oauthBearerMode'] is True, state['before']
        assert state['before']['configuredConcurrency'] == 256, state['before']
        assert state['ok'] == 64 and state['fail'] == 0, state
        assert state['direct'] == [], state['direct']
        assert all(url in state['observed'] for url in state['relevant']), (state['relevant'], state['observed'][-20:])
        assert state['observationBatchSizes'] and max(state['observationBatchSizes']) <= 48, state['observationBatchSizes']
        assert state['batches'] and state['batches'][0]['concurrency'] >= 3 and state['batches'][0]['size'] >= 32, state['batches']
        browser.close()
        print(json.dumps({'cleanUrlAuthenticated':True,'configuredConcurrency':state['before']['configuredConcurrency'],'firstBatch':state['batches'][0],'observationBatchSizes':state['observationBatchSizes'],'directChessCalls':0,'pageErrors':0}, indent=2))

if __name__ == '__main__':
    main()
