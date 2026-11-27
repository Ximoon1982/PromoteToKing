"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs"), vm = require("node:vm"), path = require("node:path");
const ROOT = path.join(__dirname, "../assets/js/shared");
const scripts = ["api-request-semantics.js", "api-oauth-context.js", "api-transport.js", "api-request-coordinator.js", "api-client.js"];
const url = name => `https://api.chess.com/pub/club/fixture-${name}`;
function fixture(handler, { bearer = true, search = "", cache = null, publicHandler = null, requireOAuth = false } = {}) {
  const epoch = Date.UTC(2026, 10, 27, 9), timers = new Map(), posts = [], publicCalls = [], events = [];
  let now = epoch, nextTimer = 0, jsonps = 0;
  const math = Object.create(Math); math.random = () => 0; // worst-case downward jitter
  class Clock extends Date { constructor(...args) { super(...(args.length ? args : [now])); } static now() { return now; } }
  const setTimeout = (fn, delay = 0) => { const id = ++nextTimer; timers.set(id, {at: now + Math.max(0, Math.ceil(delay)), fn}); return id; };
  const clearTimeout = id => timers.delete(id);
  const window = {
    location: {href: "https://p2k.test/FindMatch.htm" + search, search},
    P2K_AUTH: {realOAuth: bearer, getSession: () => bearer ? {oauthVerified:true} : null, getCsrf: () => "fixture-csrf"},
    P2K_REAL_OAUTH_READY: Promise.resolve(), P2K_API_CACHE: cache,
    setTimeout, clearTimeout, addEventListener() {}, dispatchEvent(e) { events.push(e); },
    fetch: async (target, options) => {
      const u = new URL(target);
      if (u.origin === "https://p2k.test" && u.searchParams.get("action") === "batch") {
        const body = JSON.parse(options.body), entry = {at: now - epoch, body}; posts.push(entry);
        assert.equal(body.rate_cps, 30);
        return handler(entry, posts.length, window.P2K_API_CLIENT);
      }
      if (u.origin === "https://api.chess.com") {
        publicCalls.push({at:now-epoch, target, options});
        if (publicHandler) return publicHandler(target, options);
        throw Error("Unexpected public acquisition");
      }
      throw Error("Unexpected fetch: " + target);
    }
  };
  const document = { querySelector:()=>requireOAuth?{}:null, readyState:"complete", createElement:()=>({remove(){}}), head:{appendChild(script){
    jsonps++; const cb = new URL(script.src).searchParams.get("callback"); setTimeout(()=>window[cb]?.({jsonp:true}), 0);
  }}};
  const context = vm.createContext({window,document,URL,URLSearchParams,Response,Headers,AbortController,AbortSignal,Date:Clock,Math:math,TypeError,Error,console,CustomEvent:class {constructor(type,options){this.type=type;this.detail=options?.detail;}},sessionStorage:{getItem:()=>null,setItem(){}},setTimeout,clearTimeout});
  for (const script of scripts) vm.runInContext(fs.readFileSync(path.join(ROOT,script),"utf8"),context,{filename:script});
  const api=window.P2K_API_CLIENT; if(bearer)api.setOAuthBearerMode(true);
  async function micro() { for(let i=0;i<80;i++)await Promise.resolve(); }
  async function advance(ms=0) {
    const end=now+ms; await micro();
    for(let i=0;i<2000;i++){
      const next=[...timers].filter(([,v])=>v.at<=end).sort((a,b)=>a[1].at-b[1].at||a[0]-b[0])[0];
      if(!next){now=end;await micro();if(![...timers.values()].some(t=>t.at<=end))return;continue;}
      now=Math.max(now,next[1].at);timers.delete(next[0]);next[1].fn();await micro();
    }
    throw Error("Timer loop did not converge");
  }
  return {api,posts,publicCalls,events,advance,epoch,get now(){return now;},get jsonps(){return jsonps;}};
}
const row=(r,status=200,extra={})=>({id:r.id,status,body:status===200?'{"ok":true}':'',headers:{},...extra});
const batch=(entry,rows,controller={})=>new Response(JSON.stringify({ok:true,mode:"oauth-bearer",controller:{rate_target_cps:24, ...controller},results:rows||entry.body.requests.map(r=>row(r))}),{status:200});
const outcome = p => { const x={done:false,value:null,error:null}; p.then(v=>{x.done=true;x.value=v;},e=>{x.done=true;x.error=e;}); return x; };
const opts={cacheMode:"no-store",attempts:1};
(async()=>{
  // In one actual shared client POST, separate real errors and never-launched rows.
  const f=fixture((e,n)=>n===1?batch(e,e.body.requests.map(r=>r.url.endsWith("-limited")?row(r,429,{headers:{"retry-after":"120"}}):r.url.endsWith("-deferred")?row(r,503,{deferred:true,headers:{"retry-after":"120"}}):r.url.endsWith("-server")?row(r,503):row(r)),{cooldown_remaining_ms:120000}):batch(e));
  const ok=outcome(f.api.jsonDetailed(url("ok"),opts)), limited=outcome(f.api.jsonDetailed(url("limited"),opts)), deferred=outcome(f.api.jsonDetailed(url("deferred"),opts)), server=outcome(f.api.jsonDetailed(url("server"),opts));
  await f.advance(); assert.equal(f.posts.length,1); assert.equal(f.posts[0].body.requests.length,4); assert(ok.done&&ok.value.data.ok); assert.equal(limited.error.category,"rate-limit"); assert.equal(limited.error.deferred,false); assert.equal(server.error.category,"server"); assert.equal(server.error.deferred,false); assert(!deferred.done); assert.equal(f.api.diagnostics().counters.deferredRequests,1); assert.equal(f.api.diagnostics().counters.retries,0); assert.equal(f.api.diagnostics().recentErrors.length,2);
  await f.advance(1000); const queued=outcome(f.api.jsonDetailed(url("queued"),opts)); const c=new AbortController();const cancelled=outcome(f.api.jsonDetailed(url("cancelled"),{...opts,signal:c.signal})); await f.advance();c.abort();await f.advance();assert.equal(cancelled.error.category,"cancelled");
  f.api.observeOAuthBatch({controller:{rate_target_cps:20,cooldown_remaining_ms:130000}}); await f.advance(129999);assert.equal(f.posts.length,1);assert(!queued.done&&!deferred.done);await f.advance(1); assert.equal(f.posts.length,2);assert.equal(f.posts[1].at,131000);assert(deferred.done&&queued.done);assert.equal(deferred.value.attempts,1);assert.equal(deferred.value.deferrals,1);assert.equal(f.api.diagnostics().counters.retries,0);assert.equal(f.publicCalls.length,0);assert.equal(f.jsonps,0);
  console.log("PASS mixed success/429/deferred/503, 120s cooldown, extension, pending new work, cancellation, no consumed retry");
  // Never reuse cached data as a substitute for an unlaunched verification.
  const saved={url:url("cached"),body:'{"old":true}',status:200,etag:'"a"',fetchedAt:Date.UTC(2026,10,27,9)-1000};
  const cache={get:async()=>saved,policyFor:()=>({freshFor:0,usableFor:10000,staleIfErrorFor:10000,networkPreferred:true}),put:async()=>{},coordinate:async(_url,task,{signal})=>task(signal)};
  const g=fixture((e,n)=>n===1?batch(e,e.body.requests.map(r=>row(r,503,{deferred:true,headers:{"retry-after":"2"}})),{cooldown_remaining_ms:2000}):batch(e),{cache});
  const fresh=outcome(g.api.jsonDetailed(url("cached"),{cacheMode:"network-only",attempts:1}));await g.advance();assert(!fresh.done);assert.equal(g.posts[0].body.requests[0].headers["if-none-match"],'"a"');await g.advance(1999);assert.equal(g.posts.length,1);await g.advance(1);assert(fresh.value.data.ok);assert.equal(fresh.value.attempts,1);assert.equal(g.api.diagnostics().counters.staleIfErrorFallbacks,0);
  console.log("PASS deferred network-only read with cached validators does not settle via stale fallback");
  // Real transient errors spend attempts; Retry-After is a floor even at minimum jitter.
  for(const kind of ["row-delta","row-date","post-503","post-429"]){
    let h;h=fixture((e,n)=>{if(n!==1)return batch(e);const header=kind==="row-date"?new Date(h.epoch+120000).toUTCString():"120";if(kind.startsWith("post"))return new Response(JSON.stringify({ok:false,error:{code:"FIXTURE_LIMIT"}}),{status:kind==="post-429"?429:503,headers:{"Retry-After":header}});return batch(e,e.body.requests.map(r=>row(r,503,{headers:{"Retry-After":header}})));});
    const x=outcome(h.api.jsonDetailed(url(kind),{...opts,attempts:2}));await h.advance();assert.equal(h.posts.length,1);await h.advance(119999);assert.equal(h.posts.length,1);await h.advance(1);assert.equal(h.posts.length,2);assert.equal(h.posts[1].at,120000);assert(x.done&&x.value.data.ok);assert.equal(x.value.attempts,2);assert.equal(x.value.deferrals,0);assert.equal(h.api.diagnostics().counters.retries,1);assert.equal(h.jsonps,0);
  }
  console.log("PASS Retry-After delta/date and top-level 429/503, zero premature retries with minimum jitter");
  // A true cURL status 0 must stay on the selected authenticated route.
  const k=fixture((e,n)=>n===1?batch(e,e.body.requests.map(r=>row(r,0))):batch(e),{cache});
  // Use no-store to inspect actual network error (network-only permits existing stale-if-error on real errors).
  const network=outcome(k.api.jsonDetailed(url("network"),{...opts,attempts:2}));await k.advance();assert.equal(k.api.diagnostics().recentErrors[0].category,"network");assert.equal(k.api.diagnostics().recentErrors[0].code,"OAUTH_UPSTREAM_TRANSPORT");await k.advance(680);assert(network.value.data.ok);assert.equal(network.value.attempts,2);assert.equal(k.publicCalls.length,0);assert.equal(k.jsonps,0);
  const conditional=fixture((e)=>batch(e,e.body.requests.map(r=>row(r,0))),{cache:{...cache,policyFor:()=>({usableFor:0,staleIfErrorFor:0,networkPreferred:true})}});
  const bad=outcome(conditional.api.jsonDetailed(url("conditional"),{cacheMode:"network-only",attempts:1}));await conditional.advance();assert.equal(bad.error.category,"network");assert.equal(conditional.posts.length,1);assert.equal(conditional.api.diagnostics().counters.plainFetchRetries,0);assert.equal(conditional.publicCalls.length,0);assert.equal(conditional.jsonps,0);
  const expired=fixture(()=>new Response(JSON.stringify({ok:false,error:{code:"OAUTH_EXPIRED"}}),{status:401})); const rejected=outcome(expired.api.jsonDetailed(url("expired"),{...opts,attempts:3}));await expired.advance();assert.equal(rejected.error.category,"forbidden");assert.equal(expired.api.oauthBearerMode,true);assert.equal(expired.posts.length,1);assert.equal(expired.publicCalls.length,0);
  const signedOut=fixture((e,n,api)=>{api.setOAuthBearerMode(false);throw new TypeError("gateway disconnected");});const pinned=outcome(signedOut.api.jsonDetailed(url("pinned"),{...opts,attempts:3}));await signedOut.advance();await signedOut.advance(680);assert.equal(pinned.error.category,"forbidden");assert.equal(signedOut.posts.length,1);assert.equal(signedOut.publicCalls.length,0);assert.equal(signedOut.jsonps,0);
  console.log("PASS status zero, conditional failure, session rejection and expiry never fall back to public CORS/JSONP");
  // Pending work can be cancelled by the batch owner without converting to failures.
  const b=fixture(e=>batch(e,e.body.requests.map(r=>row(r,503,{deferred:true,headers:{"retry-after":"120"}})),{cooldown_remaining_ms:120000}));const ac=new AbortController();const work=outcome(b.api.processPriority(["candidate"],item=>b.api.json(url(item),{...opts,signal:ac.signal}),{signal:ac.signal}));await b.advance();assert(!work.done);ac.abort();await b.advance();assert(work.done);assert.equal(work.value.pending.length,1);assert.equal(work.value.failures.length,0);assert.equal(work.value.cancelled,true);assert.equal(b.posts.length,1);
  // Preserve anonymous and explicitly simulated mode, including JSONP fallback.
  for(const search of ["","?oauth=1"]){const a=fixture(()=>{throw Error("Unexpected OAuth");},{bearer:false,search,publicHandler:()=>{throw new TypeError("CORS");}});const result=outcome(a.api.jsonDetailed(url("anonymous"),opts));await a.advance();assert(result.value.data.jsonp);assert.equal(a.publicCalls.length,1);assert.equal(a.jsonps,1);assert.equal(a.posts.length,0);}
  const a=fixture(()=>{throw Error("Unexpected OAuth");},{bearer:false,publicHandler:()=>new Response('{"ok":true}',{status:200})});const publicSuccess=outcome(a.api.jsonDetailed(url("public"),opts));await a.advance();assert(publicSuccess.value.data.ok);assert.equal(a.posts.length,0);
  console.log("PASS batch cancellation leaves pending items, anonymous and simulated public acquisition preserved");
  const strict=fixture(()=>{throw Error("Unexpected OAuth");},{bearer:false,requireOAuth:true});const protectedRead=outcome(strict.api.jsonDetailed(url("protected"),opts));await strict.advance();assert.equal(protectedRead.error.category,"forbidden");assert.equal(strict.publicCalls.length,0);assert.equal(strict.jsonps,0);
  const bg=fixture(e=>batch(e,e.body.requests.map(r=>row(r,503,{deferred:true,headers:{"retry-after":"120"}})),{cooldown_remaining_ms:120000}));const bgwork=outcome(bg.api.processPriority(["gab","heatmap"],item=>bg.api.json(url(item),{...opts,trafficClass:"background",deferMode:"return"}),{concurrency:1}));await bg.advance();assert(bgwork.done);assert.equal(bgwork.value.pending.length,2);assert.equal(bgwork.value.failures.length,0);assert.equal(bg.posts.length,1);assert.equal(bg.api.diagnostics().counters.retries,0);await bg.advance(119999);assert.equal(bg.posts.length,1);
  console.log("PASS protected iframe fails closed, background return mode releases pending tasks without extra gateway calls");
})().catch(e=>{console.error(e);process.exitCode=1;});
