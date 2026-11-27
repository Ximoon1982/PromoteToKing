"use strict";
const assert=require("node:assert/strict"),fs=require("node:fs"),vm=require("node:vm"),path=require("node:path");
const root=path.resolve(__dirname,"..");
const sources=["TeamFrance","TGP"].map(club=>fs.readFileSync(path.join(root,club,"MatchRecruitment/assets/js/recruitment.js"),"utf8"));
const extract=s=>s.slice(s.indexOf("function pause("),s.indexOf("\nfunction criteria("));
assert.equal(extract(sources[0]),extract(sources[1]),"Club scheduler implementations drifted");
function fixture(source,handler){
  let now=Date.UTC(2026,10,27,9),seq=0;const epoch=now,timers=new Map(),posts=[],logs=[];
  class Clock extends Date{constructor(...a){super(...(a.length?a:[now]))}static now(){return now}}
  const setTimeout=(fn,ms=0)=>{timers.set(++seq,{at:now+Math.max(0,Math.ceil(ms)),fn});return seq};
  const context=vm.createContext({Date:Clock,Math,Number,URL,AbortController,DOMException,performance:{now:()=>now-epoch},setTimeout,clearTimeout:i=>timers.delete(i),logEvent:(...a)=>logs.push(a),status(){},fetch:async(url,opt)=>{const entry={url,at:now-epoch,requests:opt.body?JSON.parse(opt.body).requests:[]};posts.push(entry);return handler(entry,posts.length,epoch)}});
  vm.runInContext('const queue=[],responseCache=new Map(),CACHE_TTL_MS=90000,BATCH_SIZE=12,MAX_GATEWAY_POSTS=2,BATCH_WINDOW_MS=28,csrf="test";let activePosts=0,pumpTimer=null,completedRequests=0,adaptiveWorkers=18,successStreak=0,rateLimitCount=0;'+extract(source)+';globalThis.test={chess,fetchRoster,metrics:()=>({completedRequests,deferredCount,rateLimitCount,activePosts,notBefore})};',context);
  async function micro(){for(let i=0;i<80;i++)await Promise.resolve()}
  async function advance(ms=0){const end=now+ms;await micro();for(let i=0;i<2000;i++){const next=[...timers].filter(([,v])=>v.at<=end).sort((a,b)=>a[1].at-b[1].at||a[0]-b[0])[0];if(!next){now=end;await micro();if(![...timers.values()].some(t=>t.at<=end))return;continue}now=Math.max(now,next[1].at);timers.delete(next[0]);next[1].fn();await micro()}throw Error("timer loop")}
  return{...context.test,advance,posts,logs,epoch};
}
const url=name=>"https://api.chess.com/pub/player/"+name+"/stats";
const response=(payload,status=200,headers={})=>({ok:status>=200&&status<300,status,headers:new Headers(headers),json:async()=>payload});
const row=(id,status=200,extra={})=>({id:String(id),status,json:status===200?{ok:true}:null,elapsed_ms:10,...extra});
const batch=(entry,rows,remaining=0)=>response({ok:true,mode:"oauth-bearer",results:rows||entry.requests.map((_,i)=>row(i)),cooldown_remaining_ms:remaining});
const outcome=p=>{const x={done:false};p.then(v=>{x.done=true;x.value=v},e=>{x.done=true;x.error=e});return x};
(async()=>{
 for(const source of sources){
   const f=fixture(source,(e,n)=>n<=4?batch(e,[row(0,503,{deferred:true,retry_after:"120"})],120000):batch(e));
   const a=outcome(f.chess(url("deferred")));
   await f.advance(28);assert.equal(f.posts.length,1);assert(!a.done);
   for(let i=1;i<=4;i++){const prev=f.posts.length;await f.advance(119999);assert.equal(f.posts.length,prev);await f.advance(29);assert.equal(f.posts.length,prev+1);assert(f.posts.at(-1).at-f.posts.at(-2).at>=120000)}
   assert(a.done&&a.value.ok);assert.equal(f.metrics().deferredCount,4);assert.equal(f.metrics().completedRequests,1);assert.equal(f.logs.filter(x=>x[0]==="error").length,0);
   const m=fixture(source,(e,n,epoch)=>n===1?batch(e,[row(0,429,{retry_after:new Date(epoch+121000).toUTCString()}),row(1,503,{deferred:true,retry_after:"120"})],120000):batch(e));
   const x=outcome(m.chess(url("limited"))),y=outcome(m.chess(url("pending")));await m.advance(28);assert.equal(m.posts[0].requests.length,2);await m.advance(120971);assert.equal(m.posts.length,1);await m.advance(100);assert(x.done&&y.done);assert.equal(m.metrics().rateLimitCount,1);assert.equal(m.metrics().deferredCount,1);assert.equal(m.metrics().completedRequests,3);assert.equal(m.logs.filter(x=>x[0]==="error").length,1);
   const c=fixture(source,e=>batch(e,[row(0,503,{deferred:true,retry_after:"120"})],120000));const ctl=new AbortController();const stopped=outcome(c.chess(url("cancel"),ctl.signal));await c.advance(28);ctl.abort();await c.advance();assert.equal(stopped.error.name,"AbortError");await c.advance(120100);assert.equal(c.posts.length,1);
   const r=fixture(source,(e,n)=>{assert.equal(e.url,"api/?action=roster");return n===1?response({ok:false,deferred:true,retry_after:"120"},503,{"Retry-After":"120"}):response({ok:true,source:"live",members:["one"]})});const roster=outcome(r.fetchRoster());await r.advance();await r.advance(119999);assert.equal(r.posts.length,1);await r.advance(1);assert(roster.value.roster.ok);assert.equal(r.metrics().deferredCount,1);
 }
 console.log("PASS TF/TGP identical schedulers: repeated 120s deferrals, delta/date deadline, mixed real 429, cancellation, roster deferral");
 const calls=[],gate=Date.now()+120000;
 const tasks=[{url:"success",kind:"player_profile"},{url:"gab",kind:"gab_opponent_profile",gab:true},{url:"heatmap",kind:"heatmap_board_detail",heatmap:true},{url:"failed",kind:"gab_opponent_profile",gab:true}];
 const window={addEventListener(){},dispatchEvent(){},P2K_TEAM_POINTS_CLIENT:{endpointRequest:async(endpoint,opt)=>{calls.push({endpoint,opt});if(opt.action==="feed-plan")return{plan:{tasks}};return{accepted:1,changed:1}}},P2K_API_CLIENT:{json:async()=>({}),diagnostics:()=>({oauthGatewayNotBeforeMs:calls.length?gate:0}),processPriority:async(items,worker,opts)=>({succeeded:[{value:await worker(items[0])}],pending:[{item:items[1]},{item:items[2]}],failures:[{item:items[3],error:Object.assign(Error("Real failure"),{status:500})}]})}};
 vm.runInNewContext(fs.readFileSync(path.join(root,"assets/js/shared/green-accelerator.js"),"utf8"),{window,Date,performance,AbortController,CustomEvent:class{},setTimeout,clearTimeout,console});
 await window.P2K_GREEN_ACCELERATOR.runOnce();const metrics=window.P2K_GREEN_ACCELERATOR.status().metrics;assert.equal(metrics.fetched,1);assert.equal(metrics.failed,1);assert.equal(metrics.deferred,2);assert.equal(calls.filter(x=>x.opt.action==="report-gab-failure").length,1);assert.equal(calls.filter(x=>x.opt.action==="report-heatmap-failure").length,0);assert.equal(calls.find(x=>x.opt.action==="report-gab-failure").opt.body.url,"failed");const count=calls.length;await window.P2K_GREEN_ACCELERATOR.runOnce();assert.equal(calls.length,count);console.log("PASS Green pending accounting and cooldown-aware replanning");
})().catch(e=>{console.error(e);process.exitCode=1});
