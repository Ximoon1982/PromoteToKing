"use strict";
// Virtual clock, deterministic endpoint service times and one paced shared launch
// queue. Execute the real browser gateway module; no live traffic or credentials.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path'),cp=require('node:child_process');
const file='assets/js/shared/api-oauth-context.js',root=path.join(__dirname,'..');
const current=fs.readFileSync(path.join(root,file),'utf8');
const baseline=process.env.P2K_SCHEDULER_BASELINE?fs.readFileSync(process.env.P2K_SCHEDULER_BASELINE,'utf8'):cp.execFileSync('git',['show','52a1d863b934b250b841e6974c33e439dc289bf9:'+file],{cwd:root,encoding:'utf8'});
function fixture(source,rate=24){
  let now=Date.UTC(2026,10,27,9),epoch=now,seq=0,nextLaunch=now,rotate=0,activeRows=0,peakRows=0,peakPosts=0;
  const timers=new Map(),posts=[],open=[],launches=[],settled=[],events=[];
  class Clock extends Date{constructor(...a){super(...(a.length?a:[now]))}static now(){return now;}}
  const setTimeout=(fn,ms=0)=>{timers.set(++seq,{at:now+Math.max(0,Math.ceil(ms)),fn});return seq;},clearTimeout=id=>timers.delete(id);
  const delay=(fn,ms)=>setTimeout(fn,ms);
  let gateTimer=0;
  function pump(){gateTimer=0;const eligible=open.filter(p=>p.next<p.body.requests.length&&p.active<p.body.concurrency);if(!eligible.length)return;
    if(nextLaunch>now){gateTimer=setTimeout(pump,nextLaunch-now);return;}
    const fg=eligible.filter(p=>p.body.traffic_class==='foreground');const list=fg.length?fg:eligible;
    const p=list[rotate++%list.length],row=p.body.requests[p.next++],lat=row.url.endsWith('/stats')?1000:row.url.includes('/match/')?1600:180;
    launches.push({at:now-epoch,url:row.url});nextLaunch=now+1000/rate;p.active++;
    delay(()=>{p.active--;p.rows.push({id:row.id,url:row.url,status:200,body:'{"ok":true}',elapsed_ms:lat,headers:{}});if(p.rows.length===p.body.requests.length){const i=open.indexOf(p);open.splice(i,1);delay(()=>{activeRows-=p.body.requests.length;p.done=now-epoch;p.resolve(new Response(JSON.stringify({ok:true,mode:'oauth-bearer',results:p.rows,endpoint_class:p.body.requests[0].url.endsWith('/stats')?'player-stats':p.body.requests[0].url.includes('/match/')?'match-detail':'player-profile',launched:p.rows.length,successes:p.rows.length,elapsed_ms:now-epoch-p.at,median_latency_ms:lat,p95_latency_ms:lat,controller:{rate_target_cps:rate,observed_at_ms:now,observed_launch_cps:rate,observed_clean_cps:rate}}),{status:200}));},35);}
      if(!gateTimer)pump();},lat);
    if(!gateTimer)gateTimer=setTimeout(pump,1000/rate);
  }
  const window={location:{href:'https://p2k.test/FindMatch.htm',search:''},P2K_AUTH:{realOAuth:true,getSession:()=>({oauthVerified:true}),getCsrf:()=> 'fixture'},setTimeout,clearTimeout,dispatchEvent:e=>events.push(e)};
  vm.runInNewContext(source,{window,URL,URLSearchParams,Response,Headers,AbortController,CustomEvent:class{},Date:Clock,sessionStorage:{getItem:()=>null,setItem(){}}});
  const ctx=window.P2K_API_MODULES.oauthContext.create({nativeFetch:async(u,opt)=>new Promise(resolve=>{const body=JSON.parse(opt.body);assert.equal(body.rate_cps,30);assert(body.concurrency<=body.requests.length);const p={body,resolve,at:now-epoch,active:0,next:0,rows:[]};posts.push(p);activeRows+=body.requests.length;peakRows=Math.max(peakRows,activeRows);peakPosts=Math.max(peakPosts,posts.filter(p=>p.done===undefined).length);delay(()=>{open.push(p);if(!gateTimer)pump();},20);}),maxConcurrency:12,integer:(v,f,min,max)=>Math.min(max,Math.max(min,Number.isFinite(+v)?Math.trunc(+v):f)),apiError:(message,fields)=>Object.assign(Error(message),fields),abortError:()=>Object.assign(Error('abort'),{name:'AbortError'}),normalizeError:e=>e,emitConcurrencyChange(){},configuredConcurrency:()=>1,adaptiveConcurrency:()=>1,retryAfterDeadline:()=>0});
  ctx.setEnabled(true);ctx.observeBatch({controller:{rate_target_cps:rate}});
  function request(url,opts={}){return ctx.executeGateway('https://api.chess.com/pub/'+url,{headers:new Headers(),timeoutMs:20000,...opts}).then(()=>settled.push({url,at:now-epoch}),error=>{throw error;});}
  async function micro(){for(let i=0;i<30;i++)await Promise.resolve();}
  async function advance(ms){const end=now+ms;await micro();for(let i=0;i<200000;i++){const t=[...timers].filter(([,v])=>v.at<=end).sort((a,b)=>a[1].at-b[1].at||a[0]-b[0])[0];if(!t){now=end;await micro();if(![...timers.values()].some(t=>t.at<=end))return;continue;}now=Math.max(now,t[1].at);timers.delete(t[0]);t[1].fn();await micro();}throw Error('timer loop');}
  function metrics(){const end=Math.max(0,...settled.map(s=>s.at));return {rows:settled.length,elapsed_ms:end,rate:Math.round(settled.length/(end/1000)*100)/100,posts:posts.length,peakRows,peakPosts,p95_post_ms:posts.map(p=>p.done-p.at).sort((a,b)=>a-b)[Math.floor((posts.length-1)*.95)],first_result_ms:Math.min(...settled.map(s=>s.at))};}
  return {request,advance,ctx,metrics,posts,settled,launches,window};
}
async function workloads(source){const results={};for(const kind of ['profiles','stats','mixed','match-details']){const f=fixture(source),promises=[];let next=0;const target=240;
  const feed=async()=>{while(next<target){const i=next++;const url=kind==='match-details'?'match/'+(i+1):'player/u'+i+((kind==='stats'||kind==='mixed'&&i%3===1)?'/stats':kind==='mixed'&&i%3===2?'/games':'');await f.request(url);}};
  for(let i=0;i<96;i++)promises.push(feed());await f.advance(30000);await Promise.all(promises);results[kind]=f.metrics();}
  const f=fixture(source),p=[];for(let i=0;i<80;i++)p.push(f.request('player/low'+i,{priority:0}));p.push(f.request('player/high',{priority:100}));await f.advance(30000);await Promise.all(p);results.priority={first_batch_has_high:f.posts[0].body.requests.some(r=>r.url.endsWith('/high')),high_result_ms:f.settled.find(r=>r.url==='player/high').at};return results;}
(async()=>{const old=await workloads(baseline),updated=await workloads(current);console.log(JSON.stringify({baseline:old,updated},null,2));if(process.env.P2K_BASELINE_ONLY)return;assert(!old.priority.first_batch_has_high);assert(updated.priority.first_batch_has_high);assert(updated.priority.high_result_ms<old.priority.high_result_ms);for(const k of ['profiles','stats','mixed','match-details']){assert.equal(updated[k].rows,240);assert(updated[k].peakRows<=72);assert(updated[k].peakPosts<=6);assert(updated[k].rate>=old[k].rate*.90,`Throughput regression: ${k}`);}console.log('PASS baseline comparison, priority, outstanding bounds and throughput');
  const f=fixture(current),jobs=[];
  for(let i=0;i<32;i++)jobs.push(f.request('player/bg'+i+'/stats',{trafficClass:'background'}));
  await f.advance(30);assert.equal(f.posts.length,2);assert(f.posts.every(p=>p.body.requests.length<=8));assert(f.ctx.diagnostics().oauthScheduler.backgroundOutstanding<=16);
  jobs.push(f.request('player/urgent',{priority:100,timeoutMs:1000}));await f.advance(1);
  assert(f.posts.some(p=>p.body.traffic_class==='foreground'&&p.body.requests.length===1&&p.body.requests[0].url.endsWith('/urgent')));
  await f.advance(30000);await Promise.all(jobs);assert.equal(f.ctx.diagnostics().oauthScheduler.outstanding,0);
  const d=fixture(current);const short=d.request('player/short',{timeoutMs:1000}),long=d.request('player/long',{timeoutMs:20000});await d.advance(2000);await Promise.all([short,long]);assert.equal(d.posts.length,2,'Different deadline budgets were combined');
  const b=fixture(current);const sample=(kind,ms)=>({endpoint_class:kind,controller:{rate_target_cps:24},results:Array.from({length:8},()=>({status:200,elapsed_ms:ms}))});
  for(let i=0;i<3;i++){b.ctx.observeBatch(sample('player-stats',1100));b.ctx.observeBatch(sample('player-profile',170));}
  const models=b.ctx.diagnostics().oauthScheduler.endpointModels;assert.equal(models['player-stats'].median,1100);assert.equal(models['player-profile'].median,170);
  b.ctx.observeBatch({endpoint_class:'player-stats',controller:{rate_target_cps:12},results:[{status:200,elapsed_ms:100}]});assert.equal(b.ctx.diagnostics().oauthScheduler.endpointModels['player-stats'].median,1100);assert.equal(b.ctx.effectiveBatchConcurrency(),48);assert.equal(b.ctx.diagnostics().oauthScheduler.budget,36);
  assert.equal(f.ctx.diagnostics().oauthScheduler.returned,33);assert(!JSON.stringify(f.ctx.diagnostics().oauthScheduler).includes('player/urgent'));
  console.log('PASS foreground headroom, deadline isolation, per-endpoint learning, adaptive feeder and bounded aggregate telemetry');})().catch(e=>{console.error(e);process.exitCode=1;});
