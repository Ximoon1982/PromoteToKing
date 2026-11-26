"use strict";
const assert=require("node:assert/strict");
const {Bench,PLAN,parseNames,connectionCap,MAX_MS}=require("../assets/js/pages/oauth-performance-lab.js");
const names=Array.from({length:3000},(_,i)=>"fixture-"+i);
const plan=(name,settings={})=>({name,batch:8,posts:2,workers:24,headroom:1.35,admissionMs:90,drainMs:100,...settings});
const wait=ms=>new Promise(r=>setTimeout(r,ms));
function fixture({status=200,omit=false,hang=false}={}){
  const urls=new Set();let active=0,peak=0,calls=0;
  return {urls,get peak(){return peak},get calls(){return calls},post:async(body,signal)=>{
    calls++;active++;peak=Math.max(peak,active);assert(body.rate_cps===120);assert(body.traffic_class==="foreground");assert(body.concurrency<=body.requests.length);assert(!body.requests.some(r=>urls.has(r.url)));body.requests.forEach(r=>urls.add(r.url));
    try{
      if(hang)await new Promise((resolve,reject)=>signal.addEventListener("abort",()=>reject(Error("abort")),{once:true}));
      await wait(5);
      return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:40,safe_rate_cps:39,unsafe_rate_cps:45},median_latency_ms:30,p95_latency_ms:80,peak_in_flight:3,elapsed_ms:5,results:(omit?body.requests.slice(1):body.requests).map(r=>({id:r.id,status,elapsed_ms:30,json:{status:"basic"},headers:{"retry-after":"1"}}))};
    }finally{active--;}
  }};
}
(async()=>{
  assert(PLAN.reduce((s,p)=>s+p.admissionMs+p.drainMs,0)===265000);
  assert(MAX_MS===300000);assert(PLAN[1].batch===32&&PLAN[1].posts===6&&PLAN[1].workers===256);
  assert.deepEqual(parseNames("ABC\nabc\nhttps://www.chess.com/member/DEF\nbad name"),{names:["abc","def"],invalid:1,duplicates:1});
  assert.equal(connectionCap({batch:32,headroom:1.35},256,40,100,100),7);
  const f=fixture(),b=new Bench({names,seed:123,post:f.post,plan:[plan("A"),plan("B",{posts:3})],maxMs:1000,postTimeout:200});
  const result=await b.run();assert.equal(result.stop_reason,null);assert(result.scenarios.every(s=>s.status==="completed"&&s.successful>0&&s.submitted===s.received));
  assert.equal(result.scenarios[0].controller_end.target,40);assert.equal(result.scenarios[1].controller_start.target,40);assert(f.peak<=3);
  assert(!JSON.stringify(result).includes("fixture-"));assert(result.scenarios[0].successful_cps>0);
  const rate=fixture({status:429}),r=await new Bench({names,seed:1,post:rate.post,plan:[plan("A"),plan("B")],maxMs:1000}).run();assert.match(r.stop_reason,/429/);assert.equal(r.scenarios[1].status,"not run");assert(r.scenarios[0].rate429>=3);
  const omitted=fixture({omit:true}),o=await new Bench({names,seed:1,post:omitted.post,plan:[plan("A"),plan("B")],maxMs:1000}).run();assert.match(o.stop_reason,/omitted/);assert.equal(o.scenarios[1].status,"not run");
  const hanging=fixture({hang:true}),h=await new Bench({names,seed:1,post:hanging.post,plan:[plan("A",{admissionMs:15,drainMs:20}),plan("B")],maxMs:1000,postTimeout:200}).run();assert.match(h.stop_reason,/drain/);assert.equal(h.scenarios[1].status,"not run");assert(h.actual_elapsed_ms<200);
  const expired=fixture(),e=await new Bench({names,seed:1,post:expired.post,plan:[plan("A")],maxMs:10}).run();assert.match(e.stop_reason,/insufficient/);assert.equal(expired.calls,0);
  const manual=fixture({hang:true}),m=new Bench({names,seed:1,post:manual.post,plan:[plan("A"),plan("B")],maxMs:1000});const promise=m.run();setTimeout(()=>m.stop("manual stop"),10);const stopped=await promise;assert.equal(stopped.stop_reason,"manual stop");assert.equal(stopped.scenarios[1].status,"not run");
  console.log("PASS plan deadline, parsing, concurrency, aggregate metrics, no repeated URLs, controller carry-over, 429 stop, omitted result stop, drain, manual stop, no credentials or usernames in export");
})().catch(e=>{console.error(e);process.exit(1)});
