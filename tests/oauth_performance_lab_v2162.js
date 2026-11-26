"use strict";
const assert=require("node:assert/strict");
const {Bench,PLAN,parseNames,connectionCap,MAX_MS,POST_TIMEOUT}=require("../assets/js/pages/oauth-performance-lab.js");
const names=Array.from({length:3000},(_,i)=>"fixture-"+i);
const plan=(name,settings={})=>({name,batch:8,posts:2,workers:24,headroom:1.35,admissionMs:90,drainMs:100,...settings});
const wait=ms=>new Promise(r=>setTimeout(r,ms));
function fixture({status=200,omit=false,hang=false,delay=5}={}){
  const urls=new Set();let active=0,peak=0,calls=0;
  return {urls,get peak(){return peak},get calls(){return calls},get active(){return active},post:async(body,signal)=>{
    calls++;active++;peak=Math.max(peak,active);assert(body.rate_cps===120);assert(body.traffic_class==="foreground");assert(body.concurrency<=body.requests.length);assert(!body.requests.some(r=>urls.has(r.url)));body.requests.forEach(r=>urls.add(r.url));
    try{
      if(hang)await new Promise((resolve,reject)=>signal.addEventListener("abort",()=>reject(Error("abort")),{once:true}));
      await wait(delay);
      return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:40,safe_rate_cps:39,unsafe_rate_cps:45},median_latency_ms:30,p95_latency_ms:80,peak_in_flight:3,elapsed_ms:5,results:(omit?body.requests.slice(1):body.requests).map(r=>({id:r.id,status,elapsed_ms:30,json:{status:"basic"},headers:{"retry-after":"1"}}))};
    }finally{active--;}
  }};
}
(async()=>{
  assert.equal(PLAN.reduce((s,p)=>s+p.minAdmissionMs+p.drainMs,0),292000);assert(PLAN.every(p=>p.drainMs>POST_TIMEOUT));assert.equal(POST_TIMEOUT,20000);
  assert(MAX_MS===300000);assert(PLAN[1].batch===32&&PLAN[1].posts===6&&PLAN[1].workers===256);
  assert.deepEqual(parseNames("ABC\nabc\nhttps://www.chess.com/member/DEF\nbad name"),{names:["abc","def"],invalid:1,duplicates:1});
  assert.equal(connectionCap({batch:32,headroom:1.35},256,40,100,100),7);
  const f=fixture(),b=new Bench({names,seed:123,post:f.post,plan:[plan("A"),plan("B",{posts:3})],maxMs:1000,postTimeout:200});
  const result=await b.run();assert.equal(result.stop_reason,null);assert(result.scenarios.every(s=>s.status==="completed"&&s.successful>0&&s.submitted===s.received));
  assert.equal(result.scenarios[0].controller_end.target,40);assert.equal(result.scenarios[1].controller_start.target,40);assert(f.peak<=3);
  assert(!JSON.stringify(result).includes("fixture-"));assert(result.scenarios[0].successful_cps>0);assert.equal(result.benchmark_revision,2);
  assert(result.scenarios.every(s=>s.admission_received+s.drain_received===s.received&&s.admission_successful+s.drain_successful===s.successful&&s.server_p95_ms===5&&s.outside_server_p95_ms>=0));
  // Reproduce the old premature drain abort: a valid POST ends well after the former drain deadline.
  const slow=fixture({delay:140}),fast=fixture({delay:5});let late;
  late=new Bench({names,seed:42,post:(body,signal)=>{if(late.phase.m.config.name==="Slow drain")return slow.post(body,signal);assert.equal(slow.active,0,"next phase overlapped the preceding POST");return fast.post(body,signal);},plan:[plan("Slow drain",{admissionMs:30,drainMs:15,posts:1,workers:8}),plan("Next")],maxMs:1500,postTimeout:400});
  const lateRun=late.run();await wait(60);const draining=late.snapshot();assert.equal(draining.phase_stage,"draining");assert.equal(draining.active_posts,1);assert.equal(draining.active_requests,8);assert(draining.oldest_active_post_ms>=30);assert(draining.scenarios[0].drain_elapsed_ms>15);
  const lateResult=await lateRun;assert.equal(lateResult.stop_reason,null);assert(lateResult.scenarios.every(s=>s.status==="completed"&&s.submitted===s.received));
  const a=lateResult.scenarios[0];assert(a.drain_elapsed_ms>15);assert.equal(a.active_at_admission_close,1);assert.equal(a.requests_at_admission_close,8);assert.equal(a.admission_received,0);assert.equal(a.drain_received,8);assert.equal(a.pending_posts_at_stop,0);assert(fast.calls>0);
  // A constrained five-minute-equivalent budget shortens admission rather than a POST's timeout.
  const budget=fixture(),limited=await new Bench({names,seed:2,post:budget.post,plan:[plan("A",{admissionMs:120,minAdmissionMs:40,drainMs:120}),plan("B",{admissionMs:120,minAdmissionMs:40,drainMs:120})],maxMs:360,postTimeout:100}).run();
  assert.equal(limited.stop_reason,null);assert(limited.scenarios.every(s=>s.status==="completed"&&s.config.admissionMs>=40&&s.config.admissionMs<=120));assert(limited.scenarios[0].config.admissionMs<120);assert.equal(limited.scenarios[0].config.plannedAdmissionMs,120);assert(limited.actual_elapsed_ms<360);
  const rate=fixture({status:429}),r=await new Bench({names,seed:1,post:rate.post,plan:[plan("A"),plan("B")],maxMs:1000,postTimeout:200}).run();assert.match(r.stop_reason,/429/);assert.equal(r.scenarios[1].status,"not run");assert(r.scenarios[0].rate429>=3);
  const omitted=fixture({omit:true}),o=await new Bench({names,seed:1,post:omitted.post,plan:[plan("A"),plan("B")],maxMs:1000,postTimeout:200}).run();assert.match(o.stop_reason,/omitted/);assert.equal(o.scenarios[1].status,"not run");
  const hanging=fixture({hang:true}),h=await new Bench({names,seed:1,post:hanging.post,plan:[plan("A",{admissionMs:15,drainMs:20}),plan("B")],maxMs:1000,postTimeout:200}).run();assert.match(h.stop_reason,/Gateway POST exceeded its .* timeout/);assert.equal(h.scenarios[1].status,"not run");assert(h.actual_elapsed_ms>=190&&h.actual_elapsed_ms<600);assert.equal(h.scenarios[0].pending_posts_at_stop,2);assert.equal(h.scenarios[0].pending_requests_at_stop,16);assert(h.scenarios[0].oldest_pending_at_stop_ms>=190);assert.equal(hanging.active,0);
  const expired=fixture(),e=await new Bench({names,seed:1,post:expired.post,plan:[plan("A")],maxMs:10,postTimeout:200}).run();assert.match(e.stop_reason,/insufficient/);assert.equal(expired.calls,0);
  const manual=fixture({hang:true}),m=new Bench({names,seed:1,post:manual.post,plan:[plan("A"),plan("B")],maxMs:1000,postTimeout:200});const promise=m.run();setTimeout(()=>m.stop("manual stop"),10);const stopped=await promise;assert.equal(stopped.stop_reason,"manual stop");assert.equal(stopped.scenarios[1].status,"not run");assert(stopped.scenarios[0].pending_posts_at_stop>0);assert.equal(manual.active,0);
  console.log("PASS plan deadline, parsing, concurrency, aggregate metrics, no repeated URLs, controller carry-over, 429 stop, omitted result stop, late valid POST drain without overlap, adaptive admission budget, actual POST timeout, pending diagnostics, manual stop, no credentials or usernames in export");
})().catch(e=>{console.error(e);process.exit(1)});
