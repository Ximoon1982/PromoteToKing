#!/usr/bin/env python3
"""Real-origin v2.11.2 oracle for the shared API-client compatibility contract."""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
BASELINE = "4ececcc230ca07099b346cb47396ad00bedd5c21"
CHROMIUM = os.environ.get("P2K_CHROMIUM") or shutil.which("chromium") or "/usr/bin/chromium"


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:  # noqa: N802
        body = b"<!doctype html><html><body><main id='runtime-origin'></main></body></html>"
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, _format: str, *_args: object) -> None:
        return


def baseline_source() -> str:
    return subprocess.run(
        ["git", "show", f"{BASELINE}:assets/js/shared/api-client.js"],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    ).stdout


BOOTSTRAP = r"""
window.__calls = [];
window.__counts = Object.create(null);
window.__auth = false;
window.P2K_SITE_CONFIG = {
  clubSlug: 'promote-to-king',
  api: {
    allowedOrigins: ['https://api.chess.com'],
    jsonpFallback: false,
    defaultAttempts: 1,
    defaultConcurrency: 5,
    maximumConcurrency: 12,
    requestTimeoutMs: 1000,
    oauthGatewayEndpoint: '/server/team-points/public/oauth.php'
  },
  serverStorage: {opportunisticObservationEndpoint: '/server/team-points/public/observe.php'}
};
window.P2K_AUTH = {
  enabled: true,
  realOAuth: true,
  getCsrf: () => 'csrf-runtime-oracle',
  getSession: () => window.__auth ? {username:'Ximoon',realOAuth:true,oauthVerified:true} : null
};
window.P2K_REAL_OAUTH_READY = Promise.resolve();
const coordinated = new Map();
window.P2K_API_CACHE = {
  get: async () => null,
  policyFor: () => ({freshFor:0,usableFor:0,staleIfErrorFor:0,networkPreferred:false}),
  put: async () => {},
  coordinate: (key, task) => {
    if (coordinated.has(key)) return coordinated.get(key);
    const promise = Promise.resolve().then(() => task(null)).finally(() => coordinated.delete(key));
    coordinated.set(key, promise);
    return promise;
  }
};
window.fetch = (input, options = {}) => {
  const url = String(input);
  const headers = Object.fromEntries(new Headers(options.headers || {}).entries());
  const call = {url, method:options.method || 'GET', mode:options.mode || '', cache:options.cache || '', credentials:options.credentials || '', referrerPolicy:options.referrerPolicy || '', headers, body:String(options.body || '')};
  window.__calls.push(call);
  window.__counts[url] = (window.__counts[url] || 0) + 1;
  if (url.includes('/oauth.php') && url.includes('action=batch')) {
    const envelope = JSON.parse(call.body);
    return Promise.resolve(new Response(JSON.stringify({
      ok:true, mode:'oauth-bearer',
      results:envelope.requests.map(row => ({id:String(row.id),url:String(row.url),status:200,status_text:'OK',headers:{'content-type':'application/json'},body:JSON.stringify({kind:'oauth',url:String(row.url)}),elapsed_ms:3})),
      processed:envelope.requests.length, successes:envelope.requests.length, errors:0, rate_429:0,
      elapsed_ms:3, cps:1, requested_concurrency:envelope.concurrency, concurrency:envelope.concurrency,
      peak_in_flight:1, transport_cap:256, retry_after_seconds:0, median_latency_ms:3, p95_latency_ms:3
    }), {status:200,headers:{'content-type':'application/json'}}));
  }
  if (url.includes('/observe.php')) return Promise.resolve(new Response(JSON.stringify({ok:true,accepted:0,queued:0,updated:0}), {status:200,headers:{'content-type':'application/json'}}));
  if (url.includes('/permanent-failure')) return Promise.resolve(new Response('{}', {status:400,headers:{'content-type':'application/json'}}));
  if (url.includes('/retry')) {
    if (window.__counts[url] === 1) return Promise.resolve(new Response('{}', {status:503,headers:{'content-type':'application/json','retry-after':'0'}}));
    return Promise.resolve(new Response(JSON.stringify({kind:'retry-ok'}), {status:200,headers:{'content-type':'application/json'}}));
  }
  if (url.includes('/timeout')) return new Promise((_resolve, reject) => options.signal.addEventListener('abort', () => reject(new DOMException('aborted','AbortError')), {once:true}));
  const delay = url.includes('/distinct-') || url.includes('/dedupe') ? 40 : 0;
  return new Promise(resolve => setTimeout(() => resolve(new Response(JSON.stringify({kind:'direct',url}), {status:200,headers:{'content-type':'application/json','x-runtime':'oracle'}})), delay));
};
"""


SCENARIO = r"""async () => {
  const api = window.P2K_API_CLIENT;
  const surface = Object.keys(api).sort();
  const synchronous = typeof api.json === 'function' && typeof api.jsonDetailed === 'function' && typeof api.processPriority === 'function';
  const directUrl = 'https://api.chess.com/pub/player/runtime-direct';
  const direct = await api.jsonDetailed(directUrl, {attempts:1,cacheMode:'no-store',headers:{Accept:'application/json','X-Oracle':'direct'}});
  const directCall = window.__calls.find(row => row.url === directUrl);
  const dedupeUrl = 'https://api.chess.com/pub/player/dedupe';
  await Promise.all([api.json(dedupeUrl,{attempts:1,cacheMode:'network-only'}), api.json(dedupeUrl,{attempts:1,cacheMode:'network-only'})]);
  const dedupeCount = window.__counts[dedupeUrl];
  const distinctUrls = ['https://api.chess.com/pub/player/distinct-a','https://api.chess.com/pub/player/distinct-b'];
  await Promise.all(distinctUrls.map(url => api.json(url,{attempts:1,cacheMode:'no-store'})));
  const distinctCounts = distinctUrls.map(url => window.__counts[url]);
  let permanent;
  try { await api.json('https://api.chess.com/pub/player/permanent-failure',{attempts:3,cacheMode:'no-store'}); }
  catch (error) { permanent = api.describeError(error); }
  const retry = await api.json('https://api.chess.com/pub/player/retry',{attempts:2,cacheMode:'no-store'});
  const retryCount = window.__counts['https://api.chess.com/pub/player/retry'];
  let timeout;
  try { await api.json('https://api.chess.com/pub/player/timeout',{attempts:1,timeoutMs:1000,cacheMode:'no-store'}); }
  catch (error) { timeout = api.describeError(error); }
  window.__auth = true;
  api.setOAuthBearerMode(true);
  const oauthUrl = 'https://api.chess.com/pub/player/runtime-oauth';
  const oauth = await api.json(oauthUrl,{attempts:1,cacheMode:'no-store',headers:{Accept:'application/json','X-Oracle':'oauth'}});
  const gateway = window.__calls.find(row => row.url.includes('/oauth.php') && row.url.includes('action=batch'));
  const envelope = JSON.parse(gateway.body);
  return {
    origin: location.origin, surface, synchronous,
    direct:{data:direct.data,cacheState:direct.cacheState,transport:direct.transport,attempts:direct.attempts,call:directCall},
    dedupeCount, distinctCounts,
    permanent:{category:permanent.category,code:permanent.code,status:permanent.status,retryable:permanent.retryable},
    retry, retryCount,
    timeout:{category:timeout.category,code:timeout.code,retryable:timeout.retryable},
    oauth,
    gateway:{method:gateway.method,credentials:gateway.credentials,headers:gateway.headers,envelope:{csrf:envelope.csrf,traffic_class:envelope.traffic_class,requests:envelope.requests.map(row => ({url:row.url,headers:row.headers}))}},
    diagnostics:{oauthBearerMode:api.diagnostics().oauthBearerMode,transportMode:api.diagnostics().transportMode}
  };
}"""


def main() -> None:
    server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    origin = f"http://127.0.0.1:{server.server_port}"
    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(headless=True, executable_path=CHROMIUM, args=["--no-sandbox", "--disable-dev-shm-usage"])
            snapshots: dict[str, dict] = {}
            errors: dict[str, list[str]] = {}
            for mode, source in (("baseline", baseline_source()), ("current", (ROOT / "assets/js/shared/api-client.js").read_text(encoding="utf-8"))):
                page = browser.new_page()
                errors[mode] = []
                page.on("pageerror", lambda error, key=mode: errors[key].append(str(error)))
                page.goto(origin + "/runtime.html", wait_until="domcontentloaded")
                page.add_script_tag(content=BOOTSTRAP)
                if mode == "current":
                    page.add_script_tag(path=str(ROOT / "assets/js/shared/api-request-semantics.js"))
                    page.add_script_tag(path=str(ROOT / "assets/js/shared/api-transport.js"))
                    page.add_script_tag(path=str(ROOT / "assets/js/shared/api-request-coordinator.js"))
                page.add_script_tag(content=source)
                snapshots[mode] = page.evaluate(SCENARIO)
                page.close()
            browser.close()
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2)

    assert snapshots["baseline"] == snapshots["current"], json.dumps(snapshots, indent=2, sort_keys=True)
    assert errors == {"baseline": [], "current": []}, errors
    result = snapshots["current"]
    assert result["origin"] == origin and result["synchronous"] is True, result
    assert result["dedupeCount"] == 1 and result["distinctCounts"] == [1, 1], result
    assert result["retryCount"] == 2 and result["permanent"]["status"] == 400, result
    assert result["timeout"]["code"] == "TIMEOUT", result
    assert result["gateway"]["method"] == "POST" and result["gateway"]["credentials"] == "same-origin", result
    assert result["gateway"]["headers"]["x-p2k-oauth-csrf"] == "csrf-runtime-oracle", result
    print(json.dumps({"api_client_runtime_equivalence":"passed","origin":origin,"page_errors":0,"deduplicated_fetches":result["dedupeCount"],"retry_fetches":result["retryCount"]}, indent=2))


if __name__ == "__main__":
    main()
