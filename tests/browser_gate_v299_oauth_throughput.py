#!/usr/bin/env python3
import json
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = '/usr/bin/chromium'

BOOTSTRAP = r'''
window.__gatewayBatches = []; window.__serverBatchCount = 0;
window.__directChess = [];
const NativeURL = window.URL;
function SafeURL(input, base) {
  const b = (!base || String(base)==='about:blank') ? 'https://p2k.test/throughput?oauth=2' : base;
  return new NativeURL(input,b);
}
SafeURL.prototype = NativeURL.prototype;
for (const k of ['createObjectURL','revokeObjectURL','canParse']) if (NativeURL[k]) SafeURL[k]=NativeURL[k].bind(NativeURL);
window.URL = SafeURL;
window.P2K_SITE_CONFIG = { api:{jsonpFallback:false,defaultConcurrency:5,maximumConcurrency:12}, clubSlug:'promote-to-king' };
window.P2K_AUTH = { enabled:true, realOAuth:true, mode:'real-oauth', getCsrf(){return 'test-csrf';}, getSession(){return {username:'Ximoon',realOAuth:true};} };
window.fetch = async (input, options={}) => {
  const url = String(input);
  if (url.includes('/server/team-points/public/oauth.php') && url.includes('action=batch')) {
    const payload = JSON.parse(String(options.body||'{}'));
    const requests = Array.isArray(payload.requests) ? payload.requests : [];
    const requested = Math.max(1, Number(payload.concurrency||1));
    const ceiling = Math.max(0.5, Number(payload.rate_cps||120));
    window.__serverBatchCount += 1;
    const rate = window.__serverBatchCount >= 3 ? 9 : 8;
    window.__gatewayBatches.push({requested, rate, ceiling, size:requests.length});
    const results = requests.map(row => ({
      id:String(row.id||''), url:String(row.url||''), status:200, status_text:'OK',
      headers:{'content-type':'application/json'}, body:JSON.stringify({ok:true,url:String(row.url||'')}), elapsed_ms:80
    }));
    return new Response(JSON.stringify({
      ok:true,mode:'oauth-bearer',results,processed:results.length,successes:results.length,
      rate_429:0,errors:0,elapsed_ms:Math.max(1, requests.length / rate * 1000),cps:rate,
      requested_rate_cps:ceiling,rate_cps:rate,launch_cps:rate,requested_concurrency:requested,concurrency:requested,peak_in_flight:Math.min(requested, Math.ceil(rate * 0.12)),
      transport_cap:256,retry_after_seconds:0,median_latency_ms:80,p95_latency_ms:120,endpoint_class:'club-profile',
      controller:{rate_target_cps:rate,safe_rate_cps:rate,unsafe_rate_cps:0,latency_baseline_ms:{'club-profile':80},reason:'test-server-sync'},
      http2_seen:true,curl_http2_capable:true
    }), {status:200,headers:{'content-type':'application/json'}});
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
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.set_content('<!doctype html><html><body><header class="site-header"></header></body></html>')
        page.add_script_tag(content=BOOTSTRAP)
        page.add_script_tag(path=str(ROOT/'assets/js/shared/api-request-semantics.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-client.js'))
        page.evaluate('window.P2K_API_CLIENT.setOAuthBearerMode(true)')

        result = page.evaluate("""async () => {
          const items = Array.from({length:256}, (_,i) => `https://api.chess.com/pub/club/oauth-throughput-${i}`);
          const batch = await window.P2K_API_CLIENT.processPriority(
            items,
            url => window.P2K_API_CLIENT.json(url, {attempts:1, forceNetwork:true, jsonpFallback:false}),
            {getKey:url => url}
          );
          return {
            succeeded:batch.succeeded.length,
            failed:batch.failures.length,
            diagnostics:window.P2K_API_CLIENT.diagnostics(),
            gatewayBatches:window.__gatewayBatches,
            directChess:window.__directChess
          };
        }""")
        assert not errors, errors
        assert result['succeeded'] == 256 and result['failed'] == 0, result
        assert result['directChess'] == [], result['directChess'][:3]
        batches = result['gatewayBatches']
        assert len(batches) >= 4, batches
        assert batches[0]['requested'] in (3,8) and abs(batches[0]['rate'] - 8) < 0.01, batches[0]
        assert batches[0]['ceiling'] == 120, batches[0]
        assert batches[0]['size'] == 32, batches[0]
        assert max(row['rate'] for row in batches) >= 9, batches
        assert result['diagnostics']['oauthGatewayRateTarget'] >= 9, result['diagnostics']

        page.evaluate("""() => window.P2K_API_CLIENT.observeOAuthBatch({
          requested_rate_cps:120, rate_cps:19.5, launch_cps:19.5, requested_concurrency:32, concurrency:32, processed:32, rate_429:1, errors:0,
          cps:19.5, median_latency_ms:200, p95_latency_ms:400, transport_cap:256, retry_after_seconds:1, endpoint_class:'club-profile',
          controller:{rate_target_cps:19.5,safe_rate_cps:19.5,unsafe_rate_cps:22,latency_baseline_ms:{'club-profile':80},reason:'rate-limit-boundary'}
        })""")
        backed = page.evaluate('window.P2K_API_CLIENT.diagnostics()')
        assert backed['oauthGatewayRateTarget'] < 22, backed
        assert abs(backed['oauthGatewayUnsafeRateTarget'] - 22) < 0.01, backed
        browser.close()
        print(json.dumps({'batches':batches[:5],'backedOffRate':backed['oauthGatewayRateTarget'],'pageErrors':0}, indent=2))

if __name__ == '__main__':
    main()
