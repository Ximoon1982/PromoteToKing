/* Isolated client-only OAuth benchmark. Does not modify production acquisition. */
(() => {
  "use strict";
  const GATEWAY="/server/team-points/public/oauth.php";
  const ACCESS="/server/team-points/public/detailed-match-analysis.php?access=1";
  const RATE_CEILING=120, MAX_MS=300000, POST_TIMEOUT=45000, DRAIN_RESERVE_MS=POST_TIMEOUT+1000, MAX_OUTSTANDING=96, MAX_POSTS=6;
  // Raise queued work, never bypass the shared launch-rate controller.
  const LEVELS=Object.freeze([[3,32],[4,48],[5,64],[6,80],[6,96]]);
  const PLAN=Object.freeze([
    ["Warm-up","warmup",8,2,64,1.35,15000,12000,16],
    ["Adaptive saturation probe","probe",16,6,192,1.35,140000,85000,96],
    ["Fixed-rate validation","validate",16,6,192,1.35,90000,45000,96]
  ].map(([name,role,batch,posts,workers,headroom,admissionMs,minAdmissionMs,maxOutstanding])=>Object.freeze({name,role,batch,posts,workers,headroom,admissionMs,minAdmissionMs,maxOutstanding,drainMs:DRAIN_RESERVE_MS,sampleMs:8000,minValidationMs:30000,recoveryMs:role==="probe"?60000:0})));
  const $=id=>document.getElementById(id);
  const finite=(v,f=0)=>Number.isFinite(Number(v))?Number(v):f;
  const q=(values,p)=>{const a=values.slice().sort((x,y)=>x-y);return a.length?a[Math.floor((a.length-1)*p)]:null;};
  const retryMs=value=>{if(value==null||value==="")return 1000;const n=Number(value);return Math.max(1000,Number.isFinite(n)?n*1000:finite(Date.parse(value)-Date.now(),1000));};
  function parseNames(raw) {
    const seen=new Set(),names=[];let invalid=0,duplicates=0;
    for(let line of String(raw||"").split(/[\r\n]+/)){
      line=line.trim();if(!line||/^username$/i.test(line))continue;
      if(/^https?:\/\//i.test(line)){try{const u=new URL(line);const m=u.pathname.match(/^\/member\/([^/]+)\/?$/i);line=/^(www\.)?chess\.com$/i.test(u.hostname)&&m?decodeURIComponent(m[1]):"";}catch(_){line="";}}
      if(!/^[a-zA-Z0-9_-]{1,50}$/.test(line)){invalid++;continue;}
      const key=line.toLowerCase();if(seen.has(key)){duplicates++;continue;}seen.add(key);names.push(key);
      if(names.length>100000)throw Error("Maximum 100,000 unique usernames.");
    }
    return {names,invalid,duplicates};
  }
  function shuffle(input,seed){
    const a=input.slice();let x=seed>>>0||1;
    for(let i=a.length-1;i>0;i--){x^=x<<13;x^=x>>>17;x^=x<<5;const j=(x>>>0)%(i+1);[a[i],a[j]]=[a[j],a[i]];}
    return a;
  }
  function connectionCap(config,transport,target,median,p95){
    const service=Math.max(100,finite(median),finite(p95)*.55);
    return Math.max(1,Math.min(config.batch,transport,Math.max(Math.min(3,transport),Math.ceil(target*service/1000*config.headroom+1))));
  }
  function curlPeak(batches){
    const events=[];let measured=0;
    for(const b of batches)for(const pair of b.curl_intervals_ms||[]){
      if(Array.isArray(pair)&&pair.length===2&&Number.isFinite(pair[0])&&Number.isFinite(pair[1])&&pair[1]>pair[0]){events.push([pair[0],1],[pair[1],-1]);measured++;}
    }
    events.sort((a,b)=>a[0]-b[0]||a[1]-b[1]);let active=0,peak=0;for(const e of events){active+=e[1];peak=Math.max(peak,active);}
    return {peak:measured?peak:null,measured};
  }
  function throttleDiagnostics(rows){
    const batches=rows.flatMap(m=>m.batches),intervals=batches.flatMap(b=>b.curl_intervals_ms.map(x=>({start:x[0],end:x[1],endpoint:b.endpoint})));
    const first=intervals.reduce((n,x)=>Math.min(n,x.start),Infinity);
    return batches.flatMap(b=>b.throttle_events||[]).map(e=>({...e,observed_history_ms:Math.max(0,e.response_ms-first),windows:[1,5,10,30,60].map(seconds=>{
      const a=intervals.filter(x=>x.start>e.response_ms-seconds*1000&&x.start<=e.response_ms);
      return {seconds,admissions:a.length,by_endpoint:Object.fromEntries(["profile","stats","games"].map(k=>[k,a.filter(x=>x.endpoint===k).length])),complete_benchmark_window:first<=e.response_ms-seconds*1000};
    }),active_handles_at_response:intervals.filter(x=>x.start<=e.response_ms&&x.end>e.response_ms).length}));
  }
  function newMetrics(config){
    return {config:{...config},status:"not run",elapsed_ms:0,admission_elapsed_ms:0,drain_elapsed_ms:0,submitted:0,received:0,successful:0,admission_received:0,admission_successful:0,rate429:0,throttle_episodes:0,deferred:0,transient:0,permanent:0,missing:0,
      active_at_admission_close:0,requests_at_admission_close:0,oldest_at_admission_close_ms:null,pending_at_stop:[],
      candidates_started:0,candidates_completed:0,partial_candidates:0,peak_posts:0,peak_outstanding_requests:0,peak_curl_per_post:0,peak_queued:0,controller_start:null,controller_end:null,windows:[],workload_steps:[],offered_rate_cps:null,limit_reason:null,first_post_at_ms:null,
      latencies:[],post_latencies:[],by_endpoint:{profile:{received:0,successful:0,deferred:0,statuses:{},latencies:[]},stats:{received:0,successful:0,deferred:0,statuses:{},latencies:[]},games:{received:0,successful:0,deferred:0,statuses:{},latencies:[]}},statuses:{},completion_counts:{},batches:[]};
  }
  const increment=(obj,key,n=1)=>{obj[key]=(obj[key]||0)+n;};
  function summary(m){
    const sec=m.elapsed_ms/1000,admissionSec=m.admission_elapsed_ms/1000;
    const values=key=>m.batches.map(b=>b[key]).filter(Number.isFinite);
    const serverTimes=values("server_ms"),outsideTimes=values("outside_server_ms"),acquisitionTimes=values("acquisition_ms"),cacheTimes=values("cache_ingest_ms"),reservationTimes=values("reservation_call_ms");
    const endpoint={};
    for(const [name,e] of Object.entries(m.by_endpoint))endpoint[name]={received:e.received,successful:e.successful,deferred:e.deferred,statuses:e.statuses,median_ms:q(e.latencies,.5),p95_ms:q(e.latencies,.95)};
    const {latencies,post_latencies,by_endpoint,...rest}=m;const curl=curlPeak(m.batches);
    return {...rest,peak_curl_across_posts:curl.peak,curl_intervals_measured:curl.measured,successful_cps:sec?m.successful/sec:0,completed_cps:sec?m.received/sec:0,candidates_cps:sec?m.candidates_completed/sec:0,
      admission_successful_cps:admissionSec?m.admission_successful/admissionSec:0,admission_completed_cps:admissionSec?m.admission_received/admissionSec:0,
      drain_received:m.received-m.admission_received,drain_successful:m.successful-m.admission_successful,
      median_ms:q(latencies,.5),p95_ms:q(latencies,.95),post_median_ms:q(post_latencies,.5),post_p95_ms:q(post_latencies,.95),
      server_median_ms:q(serverTimes,.5),server_p95_ms:q(serverTimes,.95),outside_server_median_ms:q(outsideTimes,.5),outside_server_p95_ms:q(outsideTimes,.95),
      acquisition_p95_ms:q(acquisitionTimes,.95),cache_ingest_p95_ms:q(cacheTimes,.95),reservation_call_p95_ms:q(reservationTimes,.95),coordinator_lock_wait_p95_ms:q(values("coordinator_lock_wait_ms"),.95),coordinator_write_p95_ms:q(values("coordinator_write_ms"),.95),coordinator_calls:values("coordinator_calls").reduce((a,b)=>a+b,0),coordinator_state_writes:values("coordinator_state_writes").reduce((a,b)=>a+b,0),coordinator_no_write:values("coordinator_no_write").reduce((a,b)=>a+b,0),
      reservation_attempts:m.batches.reduce((n,b)=>n+(b.reservation_attempts??0),0),reservation_misses:m.batches.reduce((n,b)=>n+(b.reservation_misses??0),0),
      pending_posts_at_stop:m.pending_at_stop.length,pending_requests_at_stop:m.pending_at_stop.reduce((n,r)=>n+r.requests,0),oldest_pending_at_stop_ms:m.pending_at_stop[0]?.age_ms??null,by_endpoint:endpoint};
  }
  function publicController(c){
    return {target:finite(c?.rate_target_cps,30),safe:finite(c?.safe_rate_cps),unsafe:finite(c?.unsafe_rate_cps),observed_launch_cps:finite(c?.observed_launch_cps),observed_clean_cps:finite(c?.observed_clean_cps),cooldown_remaining_ms:finite(c?.cooldown_remaining_ms),recovery_hold_remaining_ms:finite(c?.recovery_hold_remaining_ms),learning_revision:finite(c?.rate_learning_revision),observed_at_ms:finite(c?.observed_at_ms),unsafe_evidence:String(c?.unsafe_evidence||""),reason:String(c?.reason||""),backlashes:finite(c?.backlashes)};
  }
  class Bench {
    constructor({names,seed,post,transport=256,plan=PLAN,maxMs=MAX_MS,postTimeout=POST_TIMEOUT,onUpdate=()=>{}}){
      this.names=shuffle(names,seed);this.seed=seed;this.post=post;this.transport=Math.max(1,Math.min(256,transport));this.plan=plan.slice();this.maxMs=maxMs;this.postTimeout=postTimeout;this.onUpdate=onUpdate;
      this.loadLevel=0;this.bestClean=0;this.boundary=null;this.endpointLatency={profile:{median:200,p95:350},stats:{median:1300,p95:1900},games:{median:200,p95:350}};this.cursor=0;this.metrics=plan.map(newMetrics);this.controller=null;this.cooldownUntil=0;this.throttleActive=false;this.totalEpisodes=0;this.total429=0;this.reason="";this.running=false;this.phase=null;this.sequence=0;this.started=0;
    }
    now(){return performance.now();}
    elapsed(){return this.started?Math.max(this.now()-this.started,Date.now()-this.wallStarted):0;}
    pending(p=this.phase){
      return p?[...p.active.values()].map(r=>({endpoint:r.kind,requests:r.count,age_ms:Math.round(Math.max(0,this.now()-r.start,Date.now()-r.wallStart)),timeout_ms:r.timeoutMs})).sort((a,b)=>b.age_ms-a.age_ms):[];
    }
    stop(reason="Stopped by administrator"){
      if(!this.running||this.reason)return;
      this.reason=reason;
      const p=this.phase;
      if(p){
        p.m.pending_at_stop=this.pending(p);p.admissionClosedAt??=Math.min(p.elapsed(),p.m.config.admissionMs);p.admitting=false;
        for(const r of p.active.values()){clearTimeout(r.timer);r.controller.abort();}
        p.finish();
      }
    }
    snapshot(){
      const ms=this.running?this.elapsed():this.finishedMs||0,pending=this.pending(),phase=this.phase;
      const scenarios=this.metrics.map(m=>{
        if(phase?.m!==m)return summary(m);
        const elapsedMs=phase.elapsed(),admissionMs=Math.min(phase.admissionClosedAt??m.config.admissionMs,elapsedMs);
        return summary({...m,elapsed_ms:elapsedMs,admission_elapsed_ms:admissionMs,drain_elapsed_ms:Math.max(0,elapsedMs-admissionMs),controller_end:this.controller});
      });
      return {format:"p2k-oauth-lab-v1",benchmark_revision:7,boundary:this.boundary??{kind:"not-observed",detail:"No limiting signal established yet"},validation:this.validationSummary(scenarios),throttle_events:this.running?[]:throttleDiagnostics(scenarios),throttle_episodes:this.totalEpisodes,cooldown_remaining_ms:Math.max(0,Math.ceil(this.cooldownUntil-this.now())),post_timeout_ms:this.postTimeout,max_outstanding_requests:MAX_OUTSTANDING,started_at:this.wallStarted?new Date(this.wallStarted).toISOString():null,elapsed_ms:Math.min(ms,this.maxMs),actual_elapsed_ms:ms,limit_ms:this.maxMs,sample_count:this.names.length,sample_seed:this.seed,unique_candidates_used:this.cursor,stop_reason:this.reason||null,controller:this.controller,active_posts:pending.length,active_requests:pending.reduce((n,r)=>n+r.requests,0),oldest_active_post_ms:pending[0]?.age_ms??null,active_post_details:pending,current_phase:this.phase?.m.config.name||null,phase_stage:this.phase?(this.phase.admitting?(this.throttleActive?"cooldown":"admitting"):"draining"):null,running:this.running,scenarios,notes:["Direct gateway comparison with normal server canonical cache ingestion; no browser cache lookups, Green checks, automatic retries or recruitment persistence.","Probe adapts queued workload and endpoint-specific concurrency; it does not override the shared launch clock. After an isolated 429, optional controlled recovery lets the shared controller converge below its unsafe boundary; rate increases are held for 60 seconds after upstream cooldown, and final validation fixes its caller ceiling after recovery. A clean validation does not prove a maximum.","Server cURL intervals measure admitted handles across returned batches, not TCP sockets or on-wire concurrency. A stopped batch may be missing from the peak.","Controller state is shared and not reset between scenarios; observed rate counts confirmed cURL admissions over a sustained fixed window, not reserved slots.","Isolated rate-limit episodes drain already-admitted POSTs and honor cooldown; a second distinct episode or eight real 429s ends admission and drains safely. Deferred (not launched) results are separate from API completions and are never retried by this lab.","Overall success/completion rates use total scenario wall time including drain; admission rates count only results delivered before admission closes.","Gateway timings separate cURL acquisition (including rate-slot coordination) from canonical cache ingestion. Reservation call duration includes all work in the coordinator call, not just lock waiting. Browser POST wall time minus reported multiGet time is unaccounted overhead, not a direct queue measurement.","At most six PHP POSTs and 96 outstanding requests; lower learned rates further shrink workload. Independent endpoint latency models and lane balancing prevent slow statistics batches from starving fast work. Phases use distinct candidate cohorts.","Drain reserve is a planning budget, not an independent timeout. Every admitted POST retains its own timeout, subject to the overall deadline; phases never overlap.","Unreturned results are not counted as completed; upstream requests may continue after client abort.","Throttle diagnostics count only returned benchmark cURL intervals, not other token users or upstream sliding windows; partial windows are flagged. Only request metadata and aggregate counts exported; no tokens, CSRF, candidate usernames or response bodies."]};
    }
    recordBoundary(event){
      if(!this.boundary||["not-observed","workload-or-transport-limited","interrupted"].includes(this.boundary.kind))this.boundary=event;
    }
    validationSummary(rows){
      const v=rows.find(m=>m.config.role==="validate");if(!v)return null;
      const cleanMs=v.first_post_at_ms===null?0:Math.max(0,v.admission_elapsed_ms-v.first_post_at_ms);
      const qualified=v.status==="completed"&&cleanMs>=(v.config.minValidationMs??30000)&&v.received>0&&!v.rate429&&!v.deferred&&!v.throttle_episodes&&!v.transient&&!v.missing&&!v.limit_reason;
      const utilization=v.offered_rate_cps>0?v.admission_completed_cps/v.offered_rate_cps:null;
      return {status:v.status==="not run"?"not-run":v.status==="running"?"running":qualified?(utilization>=.8?"clean-window":"clean-underfed"):"not-qualified",ceiling_utilization:utilization,ceiling_cps:v.offered_rate_cps,admission_seconds:v.admission_elapsed_ms/1000,clean_admission_seconds:cleanMs/1000,successful_cps:v.successful_cps,completed_cps:v.completed_cps,admission_successful_cps:v.admission_successful_cps};
    }
    async run(){
      if(this.running)throw Error("Benchmark already running.");
      this.running=true;this.started=this.now();this.wallStarted=Date.now();
      let deadline;
      const checkDeadline=()=>{
        if(!this.running||this.reason)return;
        const remaining=this.maxMs-this.elapsed();
        if(remaining>0)deadline=setTimeout(checkDeadline,Math.max(1,Math.ceil(remaining)));
        else this.stop("Five-minute deadline reached");
      };
      deadline=setTimeout(checkDeadline,this.maxMs);
      try{
        const minimum=c=>Math.min(c.admissionMs,c.minAdmissionMs??c.admissionMs);
        const reserve=c=>Math.max(c.drainMs??0,this.postTimeout);
        for(let i=0;i<this.plan.length&&!this.reason;i++){
          const cfg=this.metrics[i].config;
          if(cfg.role==="probe"&&this.boundary){this.metrics[i].status="skipped";this.metrics[i].limit_reason="Limit signal during warm-up";continue;}
          const remaining=Math.floor(this.maxMs-this.elapsed()-reserve(cfg));
          if(remaining<minimum(cfg)){this.reason="Remaining time insufficient for another scenario and its POST timeout reserve";break;}
          const futureMinimum=this.plan.slice(i+1).reduce((n,c)=>n+minimum(c)+reserve(c),0);
          cfg.plannedAdmissionMs=cfg.admissionMs;cfg.admissionMs=Math.min(cfg.admissionMs,Math.max(minimum(cfg),remaining-futureMinimum));cfg.drainMs=reserve(cfg);
          await this.runPhase(this.metrics[i]);
          if(cfg.role==="probe"&&!this.boundary){const last=this.metrics[i].windows.at(-1);this.boundary={kind:this.reason?"interrupted":last&&last.observed_cps<last.target_cps*.8?"workload-or-transport-limited":"not-observed",detail:this.reason?"Probe interrupted before establishing a limiting signal: "+this.reason:"Probe budget ended without an upstream limit signal; the maximum sustainable rate is not established."};}
          // Early throttling leaves unused time. Recover safely before freezing
          // the validation ceiling. Never bypass the shared unsafe boundary.
          if(cfg.role==="probe"&&cfg.recoveryMs>0&&!this.reason&&this.boundary?.kind==="upstream-throttling"&&this.plan[i+1]?.role==="validate"){
            const v=this.plan[i+1],budget=Math.floor(this.maxMs-this.elapsed()-reserve(cfg)-reserve(v)-minimum(v));
            const duration=Math.min(cfg.recoveryMs,budget);
            if(duration>=Math.min(24000,cfg.recoveryMs*.4)){
              const recovery={...cfg,name:"Controlled recovery",role:"recover",admissionMs:duration,minAdmissionMs:duration,recoveryMs:0};
              this.plan.splice(i+1,0,recovery);this.metrics.splice(i+1,0,newMetrics(recovery));
            }
          }
          this.onUpdate();
        }
      }finally{clearTimeout(deadline);this.finishedMs=this.elapsed();this.running=false;this.phase=null;this.onUpdate();}
      return this.snapshot();
    }
    runPhase(m){
      return new Promise(resolve=>{
        const cfg=m.config,t0=this.now(),wall0=Date.now(),phaseElapsed=()=>Math.max(this.now()-t0,Date.now()-wall0);
        const p={m,elapsed:phaseElapsed,active:new Map(),queues:{profile:[],stats:[],games:[]},candidates:new Map(),admitting:true,admissionClosedAt:null,stopAfterDrain:null,finished:false,nextKind:0,wake:null,pumpTimer:null,admissionTimer:null,sampleTimer:null,finish:null,level:this.loadLevel,pressureWindows:0};
        const kinds=["profile","stats","games"],phaseBaseline=Object.fromEntries(kinds.map(k=>[k,{...this.endpointLatency[k]}]));
        const outstanding=()=>[...p.active.values()].reduce((n,r)=>n+r.count,0);
        const adaptive=["probe","recover","validate"].includes(cfg.role);
        const postLimit=()=>Math.min(MAX_POSTS,cfg.posts,adaptive?LEVELS[p.level][0]:cfg.posts);
        const hardCap=()=>Math.min(MAX_OUTSTANDING,cfg.maxOutstanding??cfg.batch*cfg.posts,adaptive?LEVELS[p.level][1]:MAX_OUTSTANDING);
        if(cfg.role==="validate")m.offered_rate_cps=Math.max(1,Math.min(this.controller?.target??30,this.boundary?.kind==="upstream-throttling"?(this.controller?.target??30):(this.bestClean||this.controller?.target||30)));
        const ceiling=()=>m.offered_rate_cps??RATE_CEILING;
        const target=()=>Math.min(ceiling(),this.controller?.target??30);
        // Bound queued launch work to three seconds even during saturation or backoff.
        const requestCap=()=>Math.min(hardCap(),Math.max(1,Math.floor(target()*3)));
        const recordLevel=()=>{const last=m.workload_steps.at(-1),posts=postLimit(),cap=requestCap();if(!last||last.posts!==posts||last.outstanding_cap!==cap||last.level!==p.level)m.workload_steps.push({at_ms:Math.round(phaseElapsed()),posts,outstanding_cap:cap,level:p.level});};
        recordLevel();
        this.phase=p;m.status="running";m.controller_start=this.controller?{...this.controller}:null;
        const queued=()=>Object.values(p.queues).reduce((s,a)=>s+a.length,0);
        const open=()=>p.admitting&&!p.finished&&!this.reason&&phaseElapsed()<cfg.admissionMs&&this.elapsed()<this.maxMs;
        const finish=()=>{
          if(p.finished)return;p.finished=true;p.admitting=false;clearTimeout(p.wake);clearTimeout(p.pumpTimer);clearTimeout(p.admissionTimer);clearTimeout(p.sampleTimer);
          m.elapsed_ms=phaseElapsed();m.admission_elapsed_ms=Math.min(p.admissionClosedAt??cfg.admissionMs,m.elapsed_ms);m.drain_elapsed_ms=Math.max(0,m.elapsed_ms-m.admission_elapsed_ms);m.partial_candidates=p.candidates.size;
          m.missing=Math.max(0,m.submitted-m.received-m.deferred);if(p.stopAfterDrain&&!this.reason)this.reason=p.stopAfterDrain;m.controller_end=this.controller?{...this.controller}:null;
          m.status=this.reason?"incomplete":m.limit_reason?"limited":m.elapsed_ms>=cfg.admissionMs?"completed":"incomplete";
          this.phase=null;resolve();
        };
        p.finish=finish;
        function enqueue(candidate,kind){p.queues[kind].push({candidate,kind,id:"lab"+(++this.sequence),readyAt:this.now()});}
        const put=enqueue.bind(this);
        const feed=()=>{
          while(open()&&p.candidates.size<cfg.workers&&this.cursor<this.names.length){
            const candidate={name:this.names[this.cursor++],remaining:0};p.candidates.set(candidate.name,candidate);m.candidates_started++;put(candidate,"profile");
          }
          m.peak_queued=Math.max(m.peak_queued,queued());
        };
        const complete=c=>{if(p.candidates.delete(c.name))m.candidates_completed++;};
        const closeAdmission=(force=false)=>{
          if(p.finished||!p.admitting)return;
          // Timers may fire fractionally early. Never close admission before the measured cutoff.
          if(this.elapsed()>=this.maxMs){this.stop("Five-minute deadline reached");return;}
          const remaining=cfg.admissionMs-phaseElapsed();
          if(!force&&remaining>0){clearTimeout(p.admissionTimer);p.admissionTimer=setTimeout(closeAdmission,Math.max(1,Math.ceil(remaining)));return;}
          const pending=this.pending(p);m.active_at_admission_close=pending.length;m.requests_at_admission_close=pending.reduce((n,r)=>n+r.requests,0);m.oldest_at_admission_close_ms=pending[0]?.age_ms??null;
          p.admissionClosedAt=Math.min(cfg.admissionMs,phaseElapsed());p.admitting=false;clearTimeout(p.wake);for(const key of Object.keys(p.queues))p.queues[key]=[];
          if(!p.active.size)finish();
        };
        const schedule=()=>{
          if(p.finished||p.pumpTimer!==null)return;
          p.pumpTimer=setTimeout(()=>{p.pumpTimer=null;pump();},0);
        };
        const consume=(payload,entries,start)=>{
          if(payload?.ok!==true||payload.mode!=="oauth-bearer"||!Array.isArray(payload.results))throw Error("Gateway did not return authenticated Bearer results.");
          const expected=new Map(entries.map(e=>[e.id,e])),seen=new Set();
          const c=publicController(payload.controller||{rate_target_cps:payload.rate_cps});
          if(!this.controller||!c.observed_at_ms||c.observed_at_ms>=this.controller.observed_at_ms)this.controller=c;
          const kind=entries[0].kind;
          if(finite(payload.median_latency_ms)>0){const prev=this.endpointLatency[kind];this.endpointLatency[kind]={median:prev.median*.65+finite(payload.median_latency_ms)*.35,p95:prev.p95*.65+finite(payload.p95_latency_ms,prev.p95)*.35};}
          const deliveredDuringAdmission=open(),wallMs=Math.round(this.now()-start),serverMs=payload.elapsed_ms!=null&&Number.isFinite(Number(payload.elapsed_ms))?Math.max(0,Number(payload.elapsed_ms)):null;
          const timing=payload.gateway_timing??{},numberOrNull=v=>v!=null&&Number.isFinite(Number(v))?Math.max(0,Number(v)):null;
          const batch={at_ms:Math.round(this.elapsed()),phase_elapsed_ms:Math.round(phaseElapsed()),delivered_during_admission:deliveredDuringAdmission,endpoint:entries[0].kind,submitted:entries.length,received:0,deferred:0,wall_ms:wallMs,server_ms:serverMs,outside_server_ms:serverMs===null?null:Math.max(0,wallMs-serverMs),
            acquisition_ms:numberOrNull(timing.acquisition_ms),cache_ingest_ms:numberOrNull(timing.cache_ingest_ms),reservation_call_ms:numberOrNull(timing.reservation_call_ms),reservation_attempts:numberOrNull(timing.reservation_attempts),reservation_misses:numberOrNull(timing.reservation_misses),first_launch_delay_ms:numberOrNull(timing.first_launch_delay_ms),launch_span_ms:numberOrNull(timing.launch_span_ms),
            coordinator_calls:numberOrNull(timing.coordinator_io?.calls),coordinator_state_writes:numberOrNull(timing.coordinator_io?.writes),coordinator_no_write:numberOrNull(timing.coordinator_io?.no_write),coordinator_lock_wait_ms:numberOrNull(timing.coordinator_io?.lock_wait_ms),coordinator_write_ms:numberOrNull(timing.coordinator_io?.write_ms),
            throttle_events:Array.isArray(timing.throttle_events)?timing.throttle_events.filter(e=>Number.isFinite(e.launch_ms)&&Number.isFinite(e.response_ms)&&e.response_ms>=e.launch_ms).map(e=>({endpoint:kind,launch_ms:e.launch_ms,response_ms:e.response_ms,retry_after_seconds:finite(e.retry_after_seconds),launch_target_cps:finite(e.launch_target_cps),observed_at_launch_cps:finite(e.observed_at_launch_cps)})):[],
            curl_intervals_ms:Array.isArray(timing.curl_intervals_ms)?timing.curl_intervals_ms.filter(x=>Array.isArray(x)&&x.length===2&&x.every(Number.isFinite)&&x[1]>x[0]).map(x=>[x[0],x[1]]):[],launch_cps:finite(payload.launch_cps),cps:finite(payload.cps),peak_curl:finite(payload.peak_in_flight),requested_concurrency:finite(payload.requested_concurrency),median_ms:finite(payload.median_latency_ms),p95_ms:finite(payload.p95_latency_ms),http2:payload.http2_seen===true,controller:c,statuses:{}};
          m.batches.push(batch);m.post_latencies.push(batch.wall_ms);m.peak_curl_per_post=Math.max(m.peak_curl_per_post,batch.peak_curl);
          for(const [sec,n] of Object.entries(payload.completion_counts||{}))if(/^\d+$/.test(sec))increment(m.completion_counts,sec,finite(n));
          let unauthorized=false,batch429=0,retryDelay=0;
          for(const row of payload.results){
            const e=expected.get(String(row.id));if(!e||seen.has(e.id))throw Error("Unexpected or duplicate gateway result ID.");seen.add(e.id);
            const status=finite(row.status),latency=finite(row.elapsed_ms),ep=m.by_endpoint[e.kind];
            if(row.deferred===true){
              m.deferred++;ep.deferred++;batch.deferred++;retryDelay=Math.max(retryDelay,retryMs(row.headers?.["retry-after"]));
              if(e.kind==="profile")complete(e.candidate);else if(--e.candidate.remaining===0)complete(e.candidate);
              continue;
            }
            m.received++;batch.received++;if(deliveredDuringAdmission)m.admission_received++;ep.received++;increment(m.statuses,status);increment(ep.statuses,status);increment(batch.statuses,status);
            if(latency>0){m.latencies.push(latency);ep.latencies.push(latency);}
            const ok=status>=200&&status<300;
            if(ok){m.successful++;if(deliveredDuringAdmission)m.admission_successful++;ep.successful++;}
            else if(status===429){m.rate429++;this.total429++;batch429++;retryDelay=Math.max(retryDelay,retryMs(row.headers?.["retry-after"]));}
            else if(status===0||status>=500||status===408)m.transient++;
            else m.permanent++;
            if(status===401||status===403)unauthorized=true;
            const candidate=e.candidate;
            if(e.kind==="profile"){
              if(ok){
                let profile=row.json;
                if(!profile&&typeof row.body==="string"){try{profile=JSON.parse(row.body);}catch(_){}}
                if(!profile||typeof profile!=="object")throw Error("Invalid profile response.");
                if(/closed/i.test(String(profile.status||"")))complete(candidate);
                else if(open()){candidate.remaining=2;put(candidate,"stats");put(candidate,"games");}
              }else complete(candidate);
            }else if(--candidate.remaining===0)complete(candidate);
          }
          if(seen.size!==entries.length)throw Error("Gateway omitted one or more request results.");
          if(unauthorized)this.stop("Chess.com rejected the authenticated request (401/403)");
          else if(batch429||batch.deferred||c.cooldown_remaining_ms>0){
            // Coalesce all responses from the same drain/cooldown episode. Never resend a URL.
            if(!this.throttleActive){this.throttleActive=true;this.totalEpisodes++;m.throttle_episodes++;}
            this.cooldownUntil=Math.max(this.cooldownUntil,this.now()+Math.max(2000,retryDelay,c.cooldown_remaining_ms,finite(payload.retry_after_seconds)*1000));
            if(batch429&&cfg.role)this.recordBoundary({kind:"upstream-throttling",detail:"Actual HTTP 429 observed; shared controller backed off. No exact API limit inferred.",phase:cfg.role,at_ms:Math.round(this.elapsed()),controller:c});
            if(this.totalEpisodes>=2||this.total429>=8){
              p.stopAfterDrain="Persistent HTTP 429 / shared rate cooldown; admission stopped to protect the API";
              closeAdmission(true);
            }else if(batch429&&cfg.role&&cfg.role!=="validate"){
              m.limit_reason="Upstream throttling; drain and honor cooldown before recovery or validation";closeAdmission(true);
            }else if(batch429&&cfg.role==="validate"){
              m.limit_reason="Validation throttled";closeAdmission(true);
            }
          }
          else if(m.received>=16&&m.transient>=4&&m.transient/m.received>.15)this.stop("Transient failure rate exceeded 15%; stopped to protect the API");
        };
        // Observe full windows of returned batches, not the instantaneous rate of one POST.
        let lastSample=0;
        const sample=()=>{
          if(!open())return;
          const now=phaseElapsed(),span=now-lastSample;if(span<(cfg.sampleMs??8000)*.95){p.sampleTimer=setTimeout(sample,Math.max(1,(cfg.sampleMs??8000)-span));return;}
          const recent=m.batches.filter(b=>b.phase_elapsed_ms>lastSample&&b.phase_elapsed_ms<=now);
          const completed=recent.reduce((n,b)=>n+b.received,0),success=recent.reduce((n,b)=>n+Object.entries(b.statuses).reduce((a,[k,v])=>a+(+k>=200&&+k<300?v:0),0),0),bad=recent.some(b=>b.deferred||Object.keys(b.statuses).some(k=>+k===429||+k===0||+k===408||+k>=500));
          const observed=recent.length?finite(this.controller?.observed_launch_cps):0,clean=recent.length?finite(this.controller?.observed_clean_cps):0;
          const outside=q(recent.map(b=>b.outside_server_ms).filter(Number.isFinite),.95),post95=q(recent.map(b=>b.wall_ms),.95);
          const slowEndpoint=kinds.find(k=>{const values=recent.filter(b=>b.endpoint===k&&b.median_ms>0).map(b=>b.median_ms);return values.length>=3&&q(values,.5)>Math.max(750,phaseBaseline[k].median*2.5);})??null;
          const pressure=!!slowEndpoint||(recent.length>=3&&((outside??0)>(cfg.maxOutsideMs??1500)||(post95??0)>(cfg.maxPostMs??12000)))||(this.pending(p)[0]?.age_ms??0)>(cfg.maxPostMs??12000);
          p.pressureWindows=pressure?p.pressureWindows+1:0;
          const proven=Math.min(observed,clean,completed/(span/1000));
          m.windows.push({start_ms:lastSample,end_ms:Math.round(now),completed,successful:success,completed_cps:completed/(span/1000),target_cps:target(),observed_cps:observed,clean_cps:clean,proven_cps:proven,posts:postLimit(),outstanding_cap:requestCap(),outside_p95_ms:outside,post_p95_ms:post95,slow_endpoint:slowEndpoint,pressure,throttled:bad||this.throttleActive});
          lastSample=now;
          if(!bad&&!pressure&&!this.throttleActive&&recent.length&&proven>0)this.bestClean=Math.max(this.bestClean,proven);
          if(adaptive&&p.pressureWindows>=2){
            this.recordBoundary({kind:"gateway-pressure",detail:"Repeated endpoint or gateway latency pressure; stopped expanding workload rather than forcing API throttling.",phase:cfg.role,at_ms:Math.round(this.elapsed())});
            this.loadLevel=Math.max(0,p.level-1);m.limit_reason="Persistent gateway latency pressure";closeAdmission(true);return;
          }
          if((cfg.role==="probe"||cfg.role==="recover")&&!this.throttleActive&&!bad&&!pressure&&recent.length&&queued()>0&&p.level<LEVELS.length-1&&observed<target()*.9){p.level++;this.loadLevel=p.level;recordLevel();schedule();}
          p.sampleTimer=setTimeout(sample,cfg.sampleMs??8000);
        };
        const pump=()=>{
          if(p.finished)return;
          if(this.reason){finish();return;}
          if(this.elapsed()>=this.maxMs){this.stop("Five-minute deadline reached");return;}
          if(!open()){closeAdmission();return;}
          if(this.throttleActive){
            // Existing POSTs may still be returning pre-backoff results. Drain them before recovery.
            if(p.active.size)return;
            if(this.cooldownUntil>this.now()){
              clearTimeout(p.wake);p.wake=setTimeout(schedule,Math.min(this.cooldownUntil-this.now(),Math.max(1,cfg.admissionMs-phaseElapsed())));return;
            }
            this.throttleActive=false;
          }
          recordLevel();feed();
          if(this.cursor>=this.names.length&&!p.candidates.size&&!p.active.size&&!queued()){
            this.stop("Candidate sample exhausted before all scenarios completed");return;
          }
          while(open()&&p.active.size<postLimit()&&queued()&&outstanding()<requestCap()){
            // Rotate among homogeneous endpoint queues; ready follow-ups cannot be
            // starved by the continuously replenished profile queue.
            let kind=null;
            const slots=requestCap()-outstanding(),minimumBatch=Math.min(8,cfg.batch,requestCap());
            // Do not fill an entire POST slot with a tiny cap remainder. Small
            // follow-up queues get a bounded coalescing delay, then remain eligible.
            if(adaptive&&p.active.size&&slots<minimumBatch)break;
            let coalesceWait=Infinity;
            for(let i=0;i<kinds.length;i++){
              const k=(p.nextKind+i)%kinds.length,lane=kinds[k],queue=p.queues[lane];if(!queue.length)continue;
              const same=[...p.active.values()].filter(r=>r.kind===lane).length,other=kinds.some(x=>x!==lane&&p.queues[x].length);
              if(adaptive&&other&&same>=Math.ceil(postLimit()/2))continue;
              const age=this.now()-queue[0].readyAt;
              if(adaptive&&p.active.size&&queue.length<minimumBatch&&age<250){coalesceWait=Math.min(coalesceWait,250-age);continue;}
              kind=lane;p.nextKind=(k+1)%kinds.length;break;
            }
            if(kind===null){if(Number.isFinite(coalesceWait)&&p.pumpTimer===null)p.pumpTimer=setTimeout(()=>{p.pumpTimer=null;pump();},Math.max(1,Math.ceil(coalesceWait)));break;}
            const entries=p.queues[kind].splice(0,Math.min(cfg.batch,slots));
            const controller=new AbortController(),key=++this.sequence,start=this.now(),wallStart=Date.now();
            const timeoutMs=Math.min(this.postTimeout,Math.max(1,this.maxMs-this.elapsed()));
            const pending={controller,kind,count:entries.length,start,wallStart,timeoutMs,timer:null};
            p.active.set(key,pending);m.first_post_at_ms??=phaseElapsed();m.submitted+=entries.length;m.peak_posts=Math.max(m.peak_posts,p.active.size);m.peak_outstanding_requests=Math.max(m.peak_outstanding_requests,outstanding());
            const requests=entries.map(e=>({id:e.id,url:"https://api.chess.com/pub/player/"+encodeURIComponent(e.candidate.name)+(e.kind==="profile"?"":"/"+e.kind),headers:{accept:"application/json"}}));
            const age=()=>Math.max(this.now()-start,Date.now()-wallStart);
            const timedOut=()=>{
              if(p.finished||this.reason||!p.active.has(key))return;
              const remaining=Math.min(timeoutMs-age(),this.maxMs-this.elapsed());
              if(remaining>0){pending.timer=setTimeout(timedOut,Math.max(1,Math.ceil(remaining)));return;}
              this.stop(this.elapsed()>=this.maxMs?"Five-minute deadline reached":"Gateway POST exceeded its "+(timeoutMs/1000).toFixed(1)+" s timeout ("+kind+", "+entries.length+" requests); no new scenario started");
            };
            pending.timer=setTimeout(timedOut,timeoutMs);
            Promise.resolve().then(()=>{if(!p.finished&&!this.reason)return this.post({requests,concurrency:Math.min(entries.length,connectionCap(cfg,this.transport,target(),this.endpointLatency[kind].median,this.endpointLatency[kind].p95)),rate_cps:ceiling(),traffic_class:"foreground"},controller.signal);})
              .then(payload=>{if(!p.finished&&!this.reason){if(age()>=timeoutMs||this.elapsed()>=this.maxMs)timedOut();else{clearTimeout(pending.timer);p.active.delete(key);consume(payload,entries,start);}}})
              .catch(error=>{if(!p.finished&&!this.reason)this.stop(error?.message||"Gateway error");})
              .finally(()=>{clearTimeout(pending.timer);p.active.delete(key);if(!p.finished){if(!p.admitting&&!p.active.size)finish();else schedule();}});
          }
          this.onUpdate();
        };
        p.admissionTimer=setTimeout(closeAdmission,cfg.admissionMs);
        if(cfg.role)p.sampleTimer=setTimeout(sample,cfg.sampleMs??8000);
        // No independent drain deadline: wait for all admitted POSTs, each with its own timeout.
        pump();
      });
    }
  }
  // Export only deterministic engine pieces for local regression tests.
  const engine=Object.freeze({Bench,PLAN,parseNames,connectionCap,summary,curlPeak,throttleDiagnostics,MAX_MS,POST_TIMEOUT,MAX_OUTSTANDING,MAX_POSTS});
  if(typeof module!=="undefined"&&module.exports){module.exports=engine;return;}
  if(typeof document==="undefined")return;
  let auth=null,bench=null,verifying=false,starting=false,displayTimer=null,report=null;
  const fmt=(n,d=1)=>n==null?"—":Number(n).toFixed(d);
  function refreshInput(){
    let valid=false;
    try{const p=parseNames($("names").value);$("count").textContent=p.names.length.toLocaleString()+" unique · "+p.duplicates+" duplicates · "+p.invalid+" invalid lines";valid=p.names.length>=256&&p.invalid===0;}catch(e){$("count").textContent=e.message;}
    $("start").disabled=!auth||verifying||starting||!!bench?.running||!valid||!$("confirm").checked;
  }
  async function checkAuth(){
    auth=null;verifying=true;refreshInput();$("verify").disabled=true;
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),8000);
    const get=async url=>{
      const r=await fetch(url,{credentials:"same-origin",cache:"no-store",signal:controller.signal,headers:{Accept:"application/json"}});
      if(r.headers.get("X-P2K-Candidate-Preview"))throw Error("Candidate preview is read-only. Run the live benchmark after promotion.");
      if(!r.ok)throw Error("Authorization unavailable (HTTP "+r.status+"). Sign in through P2K as an administrator.");
      return r.json();
    };
    try{
      const a=await get(ACCESS),s=await get(GATEWAY+"?action=session");
      if(a?.allowed!==true||s?.authenticated!==true||s?.real_oauth!==true||s?.oauth_verified!==true||!s.csrf||s.transport?.batch_available!==true||String(a.username).toLowerCase()!==String(s.profile?.username).toLowerCase())throw Error("Real OAuth and verified P2K administrator access are required.");
      auth={csrf:s.csrf,transport:finite(s.transport.max_concurrency,1)};
      $("auth").textContent="Verified P2K administrator · real Chess.com OAuth · cURL Multi available"+(s.transport.http2_capable?" · HTTP/2 capable":"");
    }catch(error){$("auth").textContent=error.message||"Authorization failed.";}
    finally{clearTimeout(timer);verifying=false;$("verify").disabled=!!bench?.running||starting;refreshInput();}
    return auth;
  }
  function render(){
    const snap=bench?bench.snapshot():report;const rows=snap?.scenarios||PLAN.map(c=>summary(newMetrics(c)));
    $("rows").replaceChildren();
    for(const m of rows){
      const tr=document.createElement("tr"),c=m.config,values=[c.name,c.batch,c.posts,c.maxOutstanding??MAX_OUTSTANDING,c.workers,c.headroom,m.elapsed_ms?fmt(m.admission_elapsed_ms/1000):"—",m.elapsed_ms?fmt(m.drain_elapsed_ms/1000):"—",m.elapsed_ms?fmt(m.admission_successful_cps):"—",m.elapsed_ms?fmt(m.successful_cps):"—",m.elapsed_ms?fmt(m.completed_cps):"—",m.rate429,m.deferred,m.throttle_episodes,fmt(m.median_ms,0),fmt(m.p95_ms,0),fmt(m.post_median_ms,0)+" / "+fmt(m.post_p95_ms,0),fmt(m.server_p95_ms,0),fmt(m.acquisition_p95_ms,0),fmt(m.cache_ingest_p95_ms,0),fmt(m.reservation_call_p95_ms,0),fmt(m.outside_server_p95_ms,0),fmt(m.controller_end?.target),fmt(m.controller_end?.observed_launch_cps),fmt(m.controller_end?.safe),m.peak_posts,m.peak_outstanding_requests,m.peak_curl_across_posts??"—",m.offered_rate_cps===null?"adaptive":fmt(m.offered_rate_cps),m.status];
      for(const value of values){const td=document.createElement("td");td.textContent=value;tr.appendChild(td);}$("rows").appendChild(tr);
    }
    $("bars").replaceChildren();const ranked=rows.filter(m=>m.status==="completed"&&m.config.name!=="Warm-up"&&!m.rate429&&!m.deferred&&!m.throttle_episodes),max=Math.max(1,...ranked.map(m=>m.successful_cps));
    for(const m of ranked){const row=document.createElement("div");row.className="barrow";const name=document.createElement("span");name.textContent=m.config.name;const bar=document.createElement("div");bar.className="bar";bar.style.width=(100*m.successful_cps/max)+"%";const rate=document.createElement("span");rate.textContent=fmt(m.successful_cps)+" req/s";row.append(name,bar,rate);$("bars").append(row);}
    if(snap){
      $("boundary").textContent=snap.boundary.detail;$("validation").textContent=["clean-window","clean-underfed"].includes(snap.validation?.status)?fmt(snap.validation.successful_cps)+" success/s over "+fmt(snap.validation.clean_admission_seconds,0)+" s clean admission ("+(snap.validation.status==="clean-underfed"?"underfed; ":"")+"not a proven maximum)":snap.validation?.status??"Not run";
      const phaseRow=rows.find(m=>m.config.name===snap.current_phase);const load=phaseRow?.workload_steps.at(-1);$("workload").textContent=load?load.posts+" POSTs / "+load.outstanding_cap+" requests":"—";$("curlpeak").textContent=Math.max(0,...rows.map(m=>m.peak_curl_across_posts??0))||"—";
      const sec=Math.min(300,Math.floor(snap.elapsed_ms/1000));$("elapsed").textContent=sec+" / 300 s";$("progress").value=sec;
      $("phase").textContent=snap.current_phase||"—";$("success").textContent=rows.reduce((n,m)=>n+m.successful,0).toLocaleString();$("limits").textContent=rows.reduce((n,m)=>n+m.rate429,0);$("rate").textContent=snap.controller?fmt(snap.controller.target)+" /s":"—";$("observed").textContent=snap.controller?fmt(snap.controller.observed_launch_cps)+" /s":"—";$("safe").textContent=snap.controller?fmt(snap.controller.safe)+" /s":"—";$("episodes").textContent=snap.throttle_episodes;$("posts").textContent=snap.active_posts;
      $("outstanding").textContent=snap.active_requests;$("oldest").textContent=snap.oldest_active_post_ms===null?"—":fmt(snap.oldest_active_post_ms/1000)+" s";
      if(snap.running)$("status").textContent="Running · "+(snap.current_phase||"transition")+" · "+(snap.phase_stage||"transition")+(snap.phase_stage==="cooldown"?" · cooldown "+Math.ceil(snap.cooldown_remaining_ms/1000)+" s; draining prior POSTs":"")+" · keep this tab visible. "+snap.unique_candidates_used.toLocaleString()+" candidate names allocated.";
      else {
        const interrupted=rows.find(m=>m.pending_posts_at_stop>0);
        $("status").textContent=(snap.stop_reason||"Completed. Export the results for comparison.")+(interrupted?"\nAt stop: "+interrupted.pending_posts_at_stop+" outstanding POSTs, "+interrupted.pending_requests_at_stop+" requests, oldest "+fmt(interrupted.oldest_pending_at_stop_ms/1000)+" s. Aborted server batches may still finish.":"");
      }
    }
    $("stop").disabled=!bench?.running;$("json").disabled=$("csv").disabled=!snap||!!bench?.running;
  }
  async function post(body,signal){
    const r=await fetch(GATEWAY+"?action=batch",{method:"POST",credentials:"same-origin",cache:"no-store",signal,headers:{"Content-Type":"application/json","Accept":"application/json","X-P2K-OAuth-CSRF":auth.csrf},body:JSON.stringify(body)});
    if(!r.ok)throw Error("OAuth gateway HTTP "+r.status+"; benchmark stopped (no automatic retry).");
    return r.json();
  }
  async function launch(){
    if(starting||bench?.running)return;starting=true;refreshInput();$("verify").disabled=true;
    try{
      if(!await checkAuth())return;
      if(document.hidden)throw Error("Keep the benchmark tab visible.");
      const parsed=parseNames($("names").value);if(parsed.names.length<256||parsed.invalid||!$("confirm").checked)return;
      const execute=async lock=>{
        if(lock===null)throw Error("Another benchmark is running in this browser.");
        const seed=crypto.getRandomValues(new Uint32Array(1))[0]||1;
        bench=new Bench({names:parsed.names,seed,post,transport:auth.transport,onUpdate:()=>{}});
        $("names").disabled=$("confirm").disabled=true;report=null;
        displayTimer=setInterval(render,500);
        const promise=bench.run();render();report=await promise;render();
      };
      if(navigator.locks?.request)await navigator.locks.request("p2k-oauth-performance-lab",{ifAvailable:true},execute);
      else await execute(undefined);
    }catch(error){$("status").textContent=error.message||"Benchmark failed.";}
    finally{clearInterval(displayTimer);starting=false;$("names").disabled=$("confirm").disabled=$("verify").disabled=false;refreshInput();render();}
  }
  function download(kind){
    const snap=report||bench?.snapshot();if(!snap||bench?.running)return;
    const fields=["name","batch","posts","maxOutstanding","workers","headroom","status","plannedAdmissionMs","admissionMs","drainMs","elapsed_ms","admission_elapsed_ms","drain_elapsed_ms","submitted","received","successful","admission_received","admission_successful","admission_successful_cps","admission_completed_cps","drain_received","drain_successful","successful_cps","completed_cps","candidates_completed","candidates_cps","rate429","deferred","throttle_episodes","transient","permanent","missing","median_ms","p95_ms","post_median_ms","post_p95_ms","server_median_ms","server_p95_ms","acquisition_p95_ms","cache_ingest_p95_ms","reservation_call_p95_ms","reservation_attempts","reservation_misses","coordinator_lock_wait_p95_ms","coordinator_write_p95_ms","coordinator_calls","coordinator_state_writes","coordinator_no_write","outside_server_median_ms","outside_server_p95_ms","active_at_admission_close","requests_at_admission_close","oldest_at_admission_close_ms","pending_posts_at_stop","pending_requests_at_stop","oldest_pending_at_stop_ms","target_start","target_end","safe_end","unsafe_end","observed_end","observed_clean_end","peak_posts","peak_outstanding_requests","peak_curl_per_post","peak_curl_across_posts","curl_intervals_measured","offered_rate_cps","limit_reason"];
    const csvCell=v=>'"'+String(v??"").replaceAll('"','""')+'"';
    const body=kind==="json"?JSON.stringify(snap,null,2):[fields.join(","),...snap.scenarios.map(m=>fields.map(k=>csvCell(({target_start:m.controller_start?.target,target_end:m.controller_end?.target,safe_end:m.controller_end?.safe,unsafe_end:m.controller_end?.unsafe,observed_end:m.controller_end?.observed_launch_cps,observed_clean_end:m.controller_end?.observed_clean_cps})[k]??m.config[k]??m[k])).join(","))].join("\r\n");
    const url=URL.createObjectURL(new Blob([body],{type:kind==="json"?"application/json":"text/csv;charset=utf-8"}));
    const a=document.createElement("a");a.href=url;a.download="p2k-oauth-lab-"+new Date().toISOString().replace(/[:.]/g,"-")+"."+kind;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
  }
  $("names").addEventListener("input",refreshInput);$("confirm").addEventListener("change",refreshInput);$("verify").addEventListener("click",()=>void checkAuth());
  $("start").addEventListener("click",()=>void launch());$("stop").addEventListener("click",()=>{bench?.stop();render();});
  $("json").addEventListener("click",()=>download("json"));$("csv").addEventListener("click",()=>download("csv"));
  document.addEventListener("visibilitychange",()=>{if(document.hidden)bench?.stop("Benchmark tab hidden; stopped to preserve measurement validity");});
  window.addEventListener("pagehide",()=>bench?.stop("Page closed"));
  render();void checkAuth();
})();
