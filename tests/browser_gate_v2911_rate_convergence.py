#!/usr/bin/env python3
import json
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = '/usr/bin/chromium'

BOOTSTRAP = r'''
const NativeURL = window.URL;
function SafeURL(input, base) {
  const b = (!base || String(base)==='about:blank') ? 'https://p2k.test/converge?oauth=2' : base;
  return new NativeURL(input,b);
}
SafeURL.prototype = NativeURL.prototype;
for (const k of ['createObjectURL','revokeObjectURL','canParse']) if (NativeURL[k]) SafeURL[k]=NativeURL[k].bind(NativeURL);
window.URL = SafeURL;
window.P2K_SITE_CONFIG = { api:{jsonpFallback:false}, clubSlug:'promote-to-king' };
window.P2K_AUTH = { enabled:true, realOAuth:true, getCsrf(){return 'test';}, getSession(){return {username:'X',realOAuth:true,oauthVerified:true};} };
'''

def main():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, executable_path=CHROMIUM, args=['--no-sandbox','--disable-dev-shm-usage'])
        page = browser.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.set_content('<!doctype html><html><body></body></html>')
        page.add_script_tag(content=BOOTSTRAP)
        page.add_script_tag(path=str(ROOT/'assets/js/shared/api-request-semantics.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-oauth-context.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-transport.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-request-coordinator.js')); page.add_script_tag(path=str(ROOT/'assets/js/shared/api-client.js'))
        page.evaluate('window.P2K_API_CLIENT.setOAuthBearerMode(true)')
        result = page.evaluate('''() => {
          const rows = [];
          const snapshots = [
            {rate:8,safe:8,unsafe:0,reason:'clean'},
            {rate:12,safe:12,unsafe:0,reason:'clean'},
            {rate:18,safe:18,unsafe:0,reason:'clean'},
            {rate:20.2,safe:20.2,unsafe:22.3,reason:'rate-limit-boundary'},
            {rate:19.9,safe:19.9,unsafe:22.3,reason:'boundary-hold'}
          ];
          for (const row of snapshots) {
            const results = Array.from({length:32}, (_,n) => ({url:`https://api.chess.com/pub/match/${1000+n}`}));
            window.P2K_API_CLIENT.observeOAuthBatch({
              results, requested_rate_cps:120, rate_cps:row.rate, launch_cps:row.rate,
              requested_concurrency:32, concurrency:32, processed:32,
              rate_429:row.reason==='rate-limit-boundary'?1:0, errors:0, cps:row.rate,
              median_latency_ms:150, p95_latency_ms:260, transport_cap:256,
              endpoint_class:'match-detail', retry_after_seconds:row.reason==='rate-limit-boundary'?1:0,
              controller:{rate_target_cps:row.rate,safe_rate_cps:row.safe,unsafe_rate_cps:row.unsafe,latency_baseline_ms:{'match-detail':150},reason:row.reason}
            });
            const after = window.P2K_API_CLIENT.diagnostics();
            rows.push({next:after.oauthGatewayRateTarget,safe:after.oauthGatewaySafeRateTarget,unsafe:after.oauthGatewayUnsafeRateTarget});
          }
          return { rows, diagnostics:window.P2K_API_CLIENT.diagnostics() };
        }''')
        assert not errors, errors
        rows = result['rows']
        assert [round(row['next'],1) for row in rows] == [8,12,18,20.2,19.9], rows
        diag = result['diagnostics']
        assert abs(diag['oauthGatewayRateTarget'] - 19.9) < 0.01, diag
        assert abs(diag['oauthGatewaySafeRateTarget'] - 19.9) < 0.01, diag
        assert abs(diag['oauthGatewayUnsafeRateTarget'] - 22.3) < 0.01, diag
        assert diag['oauthGatewayRateTarget'] < diag['oauthGatewayUnsafeRateTarget'] * 0.94, diag
        browser.close()
        print(json.dumps({
            'backlashCount': 1,
            'settledRate': diag['oauthGatewayRateTarget'],
            'safeRate': diag['oauthGatewaySafeRateTarget'],
            'unsafeRate': diag['oauthGatewayUnsafeRateTarget'],
            'pageErrors': 0
        }, indent=2))

if __name__ == '__main__':
    main()
