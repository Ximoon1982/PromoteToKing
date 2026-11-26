"use strict";
const assert=require("node:assert/strict");
const {Bench,PLAN,parseNames,connectionCap,MAX_MS,POST_TIMEOUT,MAX_OUTSTANDING,MAX_POSTS,curlPeak,throttleDiagnostics}=require("../assets/js/pages/oauth-performance-lab.js");
const names=Array.from({length:12000},(_,i)=>"fixture-"+i);
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
  assert.equal(PLAN.reduce((s,p)=>s+p.minAdmissionMs+p.drainMs,0),280000);assert(PLAN.every(p=>p.drainMs>POST_TIMEOUT&&p.maxOutstanding<=MAX_OUTSTANDING));assert.equal(POST_TIMEOUT,45000);
  assert(MAX_MS===300000);assert.equal(PLAN.length,3);assert.equal(PLAN[0].maxOutstanding,16);assert.equal(MAX_OUTSTANDING,96);
  assert.deepEqual(parseNames("ABC\nabc\nhttps://www.chess.com/member/DEF\nbad name"),{names:["abc","def"],invalid:1,duplicates:1});
  assert.equal(connectionCap({batch:32,headroom:1.35},256,40,100,100),7);
  const f=fixture(),b=new Bench({names,seed:123,post:f.post,plan:[plan("A"),plan("B",{posts:3})],maxMs:1000,postTimeout:200});
  const result=await b.run();assert.equal(result.stop_reason,null);assert(result.scenarios.every(s=>s.status==="completed"&&s.successful>0&&s.submitted===s.received+s.deferred));
  assert.equal(result.scenarios[0].controller_end.target,40);assert.equal(result.scenarios[1].controller_start.target,40);assert(f.peak<=3);
  assert(!JSON.stringify(result).includes("fixture-"));assert(result.scenarios[0].successful_cps>0);assert.equal(result.benchmark_revision,7);
  assert(result.scenarios.every(s=>s.admission_received+s.drain_received===s.received&&s.admission_successful+s.drain_successful===s.successful&&s.server_p95_ms===5&&s.outside_server_p95_ms>=0));
  // Reproduce the old premature drain abort: a valid POST ends well after the former drain deadline.
  const slow=fixture({delay:140}),fast=fixture({delay:5});let late;
  late=new Bench({names,seed:42,post:(body,signal)=>{if(late.phase.m.config.name==="Slow drain")return slow.post(body,signal);assert.equal(slow.active,0,"next phase overlapped the preceding POST");return fast.post(body,signal);},plan:[plan("Slow drain",{admissionMs:30,drainMs:15,posts:1,workers:8}),plan("Next")],maxMs:1500,postTimeout:400});
  const lateRun=late.run();await wait(60);const draining=late.snapshot();assert.equal(draining.phase_stage,"draining");assert.equal(draining.active_posts,1);assert.equal(draining.active_requests,8);assert(draining.oldest_active_post_ms>=30);assert(draining.scenarios[0].drain_elapsed_ms>15);
  const lateResult=await lateRun;assert.equal(lateResult.stop_reason,null);assert(lateResult.scenarios.every(s=>s.status==="completed"&&s.submitted===s.received+s.deferred));
  const a=lateResult.scenarios[0];assert(a.drain_elapsed_ms>15);assert.equal(a.active_at_admission_close,1);assert.equal(a.requests_at_admission_close,8);assert.equal(a.admission_received,0);assert.equal(a.drain_received,8);assert.equal(a.pending_posts_at_stop,0);assert(fast.calls>0);
  // A constrained five-minute-equivalent budget shortens admission rather than a POST's timeout.
  const budget=fixture(),limited=await new Bench({names,seed:2,post:budget.post,plan:[plan("A",{admissionMs:120,minAdmissionMs:40,drainMs:120}),plan("B",{admissionMs:120,minAdmissionMs:40,drainMs:120})],maxMs:360,postTimeout:100}).run();
  assert.equal(limited.stop_reason,null);assert(limited.scenarios.every(s=>s.status==="completed"&&s.config.admissionMs>=40&&s.config.admissionMs<=120));assert(limited.scenarios[0].config.admissionMs<120);assert.equal(limited.scenarios[0].config.plannedAdmissionMs,120);assert(limited.actual_elapsed_ms<360);
  const rate=fixture({status:429}),r=await new Bench({names,seed:1,post:rate.post,plan:[plan("A"),plan("B")],maxMs:1000,postTimeout:200}).run();assert.match(r.stop_reason,/429/);assert.equal(r.scenarios[1].status,"not run");assert(r.scenarios[0].rate429>=3);
  const cooldownUrls=new Set();let cooldownCalls=0,firstBackoffAt=0,recoveryAt=0,lastDrainedAt=0;
  const cooldownPost=async(body,signal)=>{
    const n=++cooldownCalls;assert(body.requests.every(x=>!cooldownUrls.has(x.url)));body.requests.forEach(x=>cooldownUrls.add(x.url));
    if(n===1){await wait(5);firstBackoffAt=Date.now();return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:18,backlashes:1,cooldown_remaining_ms:1000},results:body.requests.map((x,i)=>({id:x.id,status:i<3?429:503,deferred:i>=3,headers:{"retry-after":"1"},elapsed_ms:i<3?100:0,json:null}))};}
    if(n===2){await wait(80);lastDrainedAt=Date.now();return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:18,backlashes:1},results:body.requests.map(x=>({id:x.id,status:200,elapsed_ms:100,json:{status:"basic"}}))};}
    if(!recoveryAt)recoveryAt=Date.now();await wait(5);return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:18,backlashes:1},results:body.requests.map(x=>({id:x.id,status:200,elapsed_ms:100,json:{status:"basic"}}))};
  };
  const recovered=await new Bench({names,seed:1,post:cooldownPost,plan:[plan("Recover",{admissionMs:2300,drainMs:200}),plan("After")],maxMs:4000,postTimeout:200}).run();
  assert.equal(recovered.stop_reason,null);assert.equal(recovered.throttle_episodes,1);assert.equal(recovered.scenarios[0].rate429,3);assert.equal(recovered.scenarios[0].deferred,5);assert.equal(recovered.scenarios[0].missing,0);assert(recoveryAt>=firstBackoffAt+1950&&recoveryAt>=lastDrainedAt);assert(recovered.scenarios[1].successful>0);
  let episodes=0,episodeAt=0;const repeatPost=async body=>{await wait(5);if(episodes===0||Date.now()-episodeAt>=2000){episodes++;episodeAt=Date.now();return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:18,cooldown_remaining_ms:1000,backlashes:episodes},results:body.requests.map((x,i)=>({id:x.id,status:i===0?429:200,elapsed_ms:100,json:{status:"basic"}}))};}return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:18,backlashes:episodes},results:body.requests.map(x=>({id:x.id,status:200,elapsed_ms:100,json:{status:"basic"}}))};};
  const persistent=await new Bench({names,seed:1,post:repeatPost,plan:[plan("Repeated episodes",{admissionMs:3500,posts:1}),plan("Never")],maxMs:5000,postTimeout:200}).run();assert.match(persistent.stop_reason,/Persistent HTTP 429/);assert.equal(persistent.throttle_episodes,2);assert.equal(persistent.scenarios[1].status,"not run");assert.equal(persistent.scenarios[0].missing,0);
  const omitted=fixture({omit:true}),o=await new Bench({names,seed:1,post:omitted.post,plan:[plan("A"),plan("B")],maxMs:1000,postTimeout:200}).run();assert.match(o.stop_reason,/omitted/);assert.equal(o.scenarios[1].status,"not run");
  const hanging=fixture({hang:true}),h=await new Bench({names,seed:1,post:hanging.post,plan:[plan("A",{admissionMs:15,drainMs:20}),plan("B")],maxMs:1000,postTimeout:200}).run();assert.match(h.stop_reason,/Gateway POST exceeded its .* timeout/);assert.equal(h.scenarios[1].status,"not run");assert(h.actual_elapsed_ms>=190&&h.actual_elapsed_ms<600);assert.equal(h.scenarios[0].pending_posts_at_stop,2);assert.equal(h.scenarios[0].pending_requests_at_stop,16);assert(h.scenarios[0].oldest_pending_at_stop_ms>=190);assert.equal(hanging.active,0);
  const expired=fixture(),e=await new Bench({names,seed:1,post:expired.post,plan:[plan("A")],maxMs:10,postTimeout:200}).run();assert.match(e.stop_reason,/insufficient/);assert.equal(expired.calls,0);
  const manual=fixture({hang:true}),m=new Bench({names,seed:1,post:manual.post,plan:[plan("A"),plan("B")],maxMs:1000,postTimeout:200});const promise=m.run();setTimeout(()=>m.stop("manual stop"),10);const stopped=await promise;assert.equal(stopped.stop_reason,"manual stop");assert.equal(stopped.scenarios[1].status,"not run");assert(stopped.scenarios[0].pending_posts_at_stop>0);assert.equal(manual.active,0);
  // Force timer callbacks to arrive a little before their requested deadline.
  // The actual elapsed clock, not the callback alone, determines cutoff/timeout.
  const realSetTimeout=global.setTimeout;
  global.setTimeout=(fn,ms,...args)=>realSetTimeout(fn,ms>=10?ms-5:ms,...args);
  try{
    const early=fixture({delay:3}),earlyResult=await new Bench({names,seed:9,post:early.post,plan:[plan("Early clock",{admissionMs:40,drainMs:100})],maxMs:400,postTimeout:100}).run();
    assert.equal(earlyResult.stop_reason,null);assert.equal(earlyResult.scenarios[0].status,"completed");assert(earlyResult.scenarios[0].admission_elapsed_ms>=40);
    const earlyHang=fixture({hang:true}),earlyTimeout=await new Bench({names,seed:9,post:earlyHang.post,plan:[plan("Early timeout",{admissionMs:20,drainMs:120})],maxMs:400,postTimeout:100}).run();
    assert.match(earlyTimeout.stop_reason,/Gateway POST exceeded/);assert(earlyTimeout.scenarios[0].oldest_pending_at_stop_ms>=100);assert(earlyTimeout.actual_elapsed_ms>=100);
  }finally{global.setTimeout=realSetTimeout;}
  // Exercise the real revision-3 scenario plan with a shared paced gateway,
  // heterogeneous cache overhead, and one legitimate POST beyond the old timeout.
  // Wall clock is scaled 100x (20 s -> 200 ms, 45 s -> 450 ms).
  const scaledPlan=PLAN.map(c=>({...c,admissionMs:c.admissionMs/100,minAdmissionMs:c.minAdmissionMs/100,drainMs:c.drainMs/100,sampleMs:c.sampleMs/100,minValidationMs:c.minValidationMs/100,recoveryMs:c.recoveryMs/100}));
  let pacedBench,sharedNext=0,pacedCalls=0,outstanding=0,peakOutstanding=0,activePhase=null,oldTimeoutExceeded=false,fairFollowups=false;
  const pacedUrls=new Set();
  async function pacedPost(body,signal){
    const cfg=pacedBench.phase.m.config,kind=body.requests[0].url.endsWith("/stats")?"stats":body.requests[0].url.endsWith("/games")?"games":"profile";
    if(outstanding)assert.equal(activePhase,cfg.name,"scenarios overlapped");
    activePhase=cfg.name;outstanding+=body.requests.length;peakOutstanding=Math.max(peakOutstanding,outstanding);
    assert(outstanding<=cfg.maxOutstanding&&outstanding<=MAX_OUTSTANDING);assert(body.requests.length<=cfg.batch);
    assert(body.requests.every(r=>!pacedUrls.has(r.url)));body.requests.forEach(r=>pacedUrls.add(r.url));
    if(kind!=="profile"&&pacedBench.phase.queues.profile.length>0)fairFollowups=true;
    const call=++pacedCalls,now=Date.now(),first=Math.max(now,sharedNext);sharedNext=first+2*body.requests.length;
    const acquisition=Math.max(0,sharedNext-now)+3+(call%4)*2,cache=call===1?220:8+(call%5)*4,delay=acquisition+cache;
    if(delay>200)oldTimeoutExceeded=true;
    try{
      await new Promise((resolve,reject)=>{const t=setTimeout(resolve,delay);signal.addEventListener("abort",()=>{clearTimeout(t);reject(Error("aborted"));},{once:true});});
      return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:21.7,safe_rate_cps:21,unsafe_rate_cps:24,observed_launch_cps:20.9},median_latency_ms:180,p95_latency_ms:267,peak_in_flight:3,elapsed_ms:delay,gateway_timing:{acquisition_ms:acquisition,cache_ingest_ms:cache,reservation_call_ms:3,reservation_attempts:body.requests.length*3,reservation_misses:body.requests.length*2},results:body.requests.map(r=>({id:r.id,status:200,elapsed_ms:180,json:{status:"basic"}}))};
    }finally{outstanding-=body.requests.length;}
  }
  pacedBench=new Bench({names,seed:42,post:pacedPost,plan:scaledPlan,maxMs:3000,postTimeout:450});
  const paced=await pacedBench.run();assert.equal(paced.stop_reason,null,JSON.stringify(paced.scenarios.map(s=>({name:s.config.name,status:s.status,elapsed:s.elapsed_ms}))));
  assert.equal(paced.scenarios.length,3);assert(paced.scenarios.every(s=>s.status==="completed"&&s.received===s.submitted&&s.successful>0));assert(paced.scenarios[0].peak_outstanding_requests<=16);assert(peakOutstanding<=96);assert(oldTimeoutExceeded&&fairFollowups);assert.equal(outstanding,0);assert(paced.scenarios.slice(1).every(s=>["profile","stats","games"].every(k=>s.by_endpoint[k].received>0)));assert(paced.scenarios.every(s=>s.acquisition_p95_ms>0&&s.cache_ingest_p95_ms>0&&s.reservation_attempts>0));assert(paced.actual_elapsed_ms<3000);
  // A low learned rate further bounds subsequent admission without changing the gateway controller.
  let lowBench,lowOutstanding=0,lowLearned=false,lowCalls=0;
  const lowPost=async body=>{const before=lowOutstanding;lowOutstanding+=body.requests.length;lowCalls++;if(lowLearned)assert(lowOutstanding<=Math.max(6,before));try{await wait(5);lowLearned=true;return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:2},median_latency_ms:100,p95_latency_ms:200,results:body.requests.map(r=>({id:r.id,status:200,elapsed_ms:100,json:{status:"basic"}}))};}finally{lowOutstanding-=body.requests.length;}};
  lowBench=new Bench({names,seed:99,post:lowPost,plan:[plan("Low rate",{batch:8,posts:4,workers:64,maxOutstanding:32,admissionMs:90})],maxMs:1000,postTimeout:200});const low=await lowBench.run();assert.equal(low.stop_reason,null);assert(lowCalls>4);assert(low.scenarios[0].peak_outstanding_requests<=32);
  // Throttle windows include prior returned batches, separate endpoint counts and flag partial coverage.
  const diag=throttleDiagnostics([{batches:[{endpoint:"profile",curl_intervals_ms:[[1000,1200],[5300,5500]]}]},{batches:[{endpoint:"games",curl_intervals_ms:[[5600,5800],[5900,6100]],throttle_events:[{endpoint:"games",launch_ms:5600,response_ms:5800,retry_after_seconds:2}]}]}]);
  assert.equal(diag.length,1);assert.deepEqual(diag[0].windows[0],{seconds:1,admissions:2,by_endpoint:{profile:1,stats:0,games:1},complete_benchmark_window:true});assert.equal(diag[0].windows[1].admissions,3);assert.equal(diag[0].windows[1].complete_benchmark_window,false);assert.equal(diag[0].active_handles_at_response,0);
  // Test adaptive saturation with realistic endpoint heterogeneity and fixed validation ceiling.
  assert.deepEqual(curlPeak([{curl_intervals_ms:[[100,140],[120,170]]},{curl_intervals_ms:[[130,180],[170,190]]}]),{peak:3,measured:4});
  let adaptive,activePosts=0,activeReqs=0,peakReqs=0,peakPosts=0,statCap=0,fastCap=0,validationRate=null;const sent=new Set();
  const adaptivePlan=[plan("Probe",{role:"probe",batch:16,posts:6,workers:192,maxOutstanding:96,admissionMs:850,sampleMs:100,drainMs:200}),plan("Validate",{role:"validate",batch:16,posts:6,workers:192,maxOutstanding:96,admissionMs:500,sampleMs:100,minValidationMs:300,drainMs:200})];
  const heterogeneous=async(body,signal)=>{
    const kind=body.requests[0].url.endsWith("/stats")?"stats":body.requests[0].url.endsWith("/games")?"games":"profile",phase=adaptive.phase.m.config.role;
    if(phase==="validate"){if(validationRate===null)validationRate=body.rate_cps;assert.equal(body.rate_cps,validationRate);assert(body.rate_cps<120);}else assert.equal(body.rate_cps,120);
    activePosts++;activeReqs+=body.requests.length;peakPosts=Math.max(peakPosts,activePosts);peakReqs=Math.max(peakReqs,activeReqs);assert(activePosts<=MAX_POSTS&&activeReqs<=MAX_OUTSTANDING);
    assert(body.requests.every(r=>!sent.has(r.url)));body.requests.forEach(r=>sent.add(r.url));
    if(kind==="stats")statCap=Math.max(statCap,body.concurrency);else fastCap=Math.max(fastCap,body.concurrency);
    const slow=kind==="stats",delay=slow?60:10,t=Date.now();
    try{await wait(delay);return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:40,observed_launch_cps:adaptive.loadLevel===4?36:20,observed_clean_cps:adaptive.loadLevel===4?35:20},median_latency_ms:slow?1300:200,p95_latency_ms:slow?1900:350,elapsed_ms:delay,peak_in_flight:body.concurrency,gateway_timing:{curl_intervals_ms:body.requests.map((r,i)=>[t+i/100,t+delay+i/100])},results:body.requests.map(r=>({id:r.id,status:200,elapsed_ms:slow?1300:200,json:{status:"basic"}}))};}finally{activePosts--;activeReqs-=body.requests.length;}
  };
  adaptive=new Bench({names,seed:123,post:heterogeneous,plan:adaptivePlan,maxMs:2200,postTimeout:200});
  const saturation=await adaptive.run();assert.equal(saturation.stop_reason,null);assert.equal(saturation.boundary.kind,"not-observed");assert.equal(saturation.validation.status,"clean-window");assert.equal(adaptive.validationSummary([{...saturation.scenarios[1],admission_completed_cps:1}]).status,"clean-underfed");assert.equal(adaptive.validationSummary([{...saturation.scenarios[1],first_post_at_ms:450}]).status,"not-qualified");assert.equal(saturation.scenarios[0].workload_steps.at(-1).level,4);assert(peakPosts===6&&peakReqs>64&&peakReqs<=96);assert(statCap===16&&fastCap<statCap);assert.equal(validationRate,35);assert(saturation.scenarios.every(s=>s.curl_intervals_measured===s.received&&s.peak_curl_across_posts>0));assert.equal(activePosts,0);
  // First actual 429 ends the adaptive probe. No forced re-probing, validation waits for drain and cooldown.
  let limitedBench,limitedCalls=0,limitAt=0,validationAt=0;const limitUrls=new Set();
  const boundaryPost=async body=>{const n=++limitedCalls,role=limitedBench.phase.m.config.role;assert(body.requests.every(r=>!limitUrls.has(r.url)));body.requests.forEach(r=>limitUrls.add(r.url));await wait(5);if(n===1){limitAt=Date.now();return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:18,cooldown_remaining_ms:1000,backlashes:1},results:body.requests.map((r,i)=>({id:r.id,status:i===0?429:200,json:{status:"basic"},headers:{"retry-after":"1"},elapsed_ms:100}))};}if(role==="validate"){validationAt||=Date.now();assert.equal(body.rate_cps,18);}return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:18,observed_launch_cps:17,observed_clean_cps:17},results:body.requests.map(r=>({id:r.id,status:200,json:{status:"basic"},elapsed_ms:100}))};};
  limitedBench=new Bench({names,seed:321,post:boundaryPost,plan:[plan("Probe",{role:"probe",admissionMs:3000,sampleMs:100,posts:2}),plan("Validate",{role:"validate",admissionMs:2450,minValidationMs:300,sampleMs:100,posts:2})],maxMs:6500,postTimeout:200});
  const limitedBoundary=await limitedBench.run();assert.equal(limitedBoundary.stop_reason,null);assert.equal(limitedBoundary.boundary.kind,"upstream-throttling");assert.equal(limitedBoundary.scenarios[0].status,"limited");assert(validationAt>=limitAt+1950);assert.equal(limitedBoundary.validation.status,"clean-window");assert.equal(limitedBoundary.validation.ceiling_cps,18);
  // An early throttle must use remaining budget to recover under shared control,
  // then choose the fixed validation ceiling from the recovered target.
  let recoverBench,recoverCalls=0,recoverFirst=0,validateFirst=0,throttledAt=0;const recoverUrls=new Set();
  const recoverPost=async body=>{
    const role=recoverBench.phase.m.config.role;
    assert(body.requests.every(r=>!recoverUrls.has(r.url)));body.requests.forEach(r=>recoverUrls.add(r.url));
    await wait(5);
    if(role==="probe"&&!throttledAt){throttledAt=Date.now();return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:18,unsafe_rate_cps:28,cooldown_remaining_ms:1000,backlashes:1},results:body.requests.map((r,i)=>({id:r.id,status:i===0?429:200,json:{status:"basic"},headers:{"retry-after":"1"},elapsed_ms:100}))};}
    if(role==="recover"){recoverFirst||=Date.now();assert.equal(body.rate_cps,120);recoverCalls++;}
    if(role==="validate"){validateFirst||=Date.now();assert.equal(body.rate_cps,25.2);}
    const target=recoverCalls>4?25.2:18;
    return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:target,unsafe_rate_cps:28,observed_launch_cps:target,observed_clean_cps:target},results:body.requests.map(r=>({id:r.id,status:200,json:{status:"basic"},elapsed_ms:100}))};
  };
  recoverBench=new Bench({names,seed:901,post:recoverPost,plan:[plan("Probe",{role:"probe",admissionMs:3000,recoveryMs:2800,sampleMs:100,posts:2}),plan("Validate",{role:"validate",admissionMs:500,minValidationMs:300,sampleMs:100,posts:2})],maxMs:8000,postTimeout:200});
  const recoveryResult=await recoverBench.run();assert.equal(recoveryResult.stop_reason,null);assert.deepEqual(recoveryResult.scenarios.map(s=>s.config.role),["probe","recover","validate"]);assert(recoverFirst>=throttledAt+1950&&validateFirst>recoverFirst);assert(recoverCalls>4);assert.equal(recoveryResult.validation.ceiling_cps,25.2);assert.equal(recoveryResult.validation.status,"clean-window");
  console.log("PASS early-throttle controlled recovery and recovered validation ceiling");
  // Sustained latency pressure is a limiting signal even with zero 429s.
  const pressurePost=async body=>{await wait(6);return {ok:true,mode:"oauth-bearer",controller:{rate_target_cps:30,observed_launch_cps:12,observed_clean_cps:12},elapsed_ms:1,median_latency_ms:200,p95_latency_ms:300,results:body.requests.map(r=>({id:r.id,status:200,elapsed_ms:200,json:{status:"basic"}}))};};
  const pressured=await new Bench({names,seed:55,post:pressurePost,plan:[plan("Pressure",{role:"probe",batch:8,posts:3,maxOutstanding:32,admissionMs:500,sampleMs:70,maxOutsideMs:1})],maxMs:1000,postTimeout:200}).run();
  assert.equal(pressured.boundary.kind,"gateway-pressure");assert.equal(pressured.scenarios[0].status,"limited");assert.equal(pressured.scenarios[0].rate429,0);assert(pressured.scenarios[0].admission_elapsed_ms<500);
  console.log("PASS adaptive saturation, distinct endpoint concurrency, fixed-rate validation, no inferred API boundary, measured cross-POST cURL peak, first-429 probe termination and cooldown recovery");
  console.log("PASS plan deadline, parsing, concurrency, aggregate metrics, no repeated URLs, controller carry-over, 429 coalescing, isolated recovery, repeated episode stop, deferred response accounting, omitted result stop, late valid POST drain without overlap, adaptive admission budget, actual POST timeout, pending diagnostics, manual stop, early timer callbacks, shared paced gateway with slow POST, global request caps, fair endpoint service, low-rate admission, timing metrics, no credentials or usernames in export");
})().catch(e=>{console.error(e);process.exit(1)});
