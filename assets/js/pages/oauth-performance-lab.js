/* Isolated client-only OAuth benchmark. Does not modify production acquisition. */
(() => {
  "use strict";
  const GATEWAY="/server/team-points/public/oauth.php";
  const ACCESS="/server/team-points/public/detailed-match-analysis.php?access=1";
  const RATE_CEILING=120, MAX_MS=300000, POST_TIMEOUT=20000;
  const PLAN=Object.freeze([
    ["Warm-up",32,6,256,1.35,12000,8000],
    ["Baseline",32,6,256,1.35,25000,10000],
    ["Smaller batches",16,6,256,1.35,25000,10000],
    ["Larger batches",64,6,256,1.35,25000,10000],
    ["Fewer POSTs",64,3,256,1.35,25000,10000],
    ["Deeper feeder",32,6,512,1.35,25000,10000],
    ["More headroom",32,6,256,2,25000,10000],
    ["Baseline repeat",32,6,256,1.35,25000,10000]
  ].map(([name,batch,posts,workers,headroom,admissionMs,drainMs])=>Object.freeze({name,batch,posts,workers,headroom,admissionMs,drainMs})));
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
  function newMetrics(config){
    return {config:{...config},status:"not run",elapsed_ms:0,admission_elapsed_ms:0,submitted:0,received:0,successful:0,rate429:0,transient:0,permanent:0,missing:0,
      candidates_started:0,candidates_completed:0,partial_candidates:0,peak_posts:0,peak_curl_per_post:0,peak_queued:0,controller_start:null,controller_end:null,
      latencies:[],post_latencies:[],by_endpoint:{profile:{received:0,successful:0,statuses:{},latencies:[]},stats:{received:0,successful:0,statuses:{},latencies:[]},games:{received:0,successful:0,statuses:{},latencies:[]}},statuses:{},completion_counts:{},batches:[]};
  }
  const increment=(obj,key,n=1)=>{obj[key]=(obj[key]||0)+n;};
  function summary(m){
    const sec=m.elapsed_ms/1000;
    const endpoint={};
    for(const [name,e] of Object.entries(m.by_endpoint))endpoint[name]={received:e.received,successful:e.successful,statuses:e.statuses,median_ms:q(e.latencies,.5),p95_ms:q(e.latencies,.95)};
    const {latencies,post_latencies,by_endpoint,...rest}=m;
    return {...rest,successful_cps:sec?m.successful/sec:0,completed_cps:sec?m.received/sec:0,candidates_cps:sec?m.candidates_completed/sec:0,
      median_ms:q(latencies,.5),p95_ms:q(latencies,.95),post_median_ms:q(post_latencies,.5),post_p95_ms:q(post_latencies,.95),by_endpoint:endpoint};
  }
  function publicController(c){
    return {target:finite(c?.rate_target_cps,30),safe:finite(c?.safe_rate_cps),unsafe:finite(c?.unsafe_rate_cps),observed_launch_cps:finite(c?.observed_launch_cps),reason:String(c?.reason||""),backlashes:finite(c?.backlashes)};
  }
  class Bench {
    constructor({names,seed,post,transport=256,plan=PLAN,maxMs=MAX_MS,postTimeout=POST_TIMEOUT,onUpdate=()=>{}}){
      this.names=shuffle(names,seed);this.seed=seed;this.post=post;this.transport=Math.max(1,Math.min(256,transport));this.plan=plan;this.maxMs=maxMs;this.postTimeout=postTimeout;this.onUpdate=onUpdate;
      this.cursor=0;this.metrics=plan.map(newMetrics);this.controller=null;this.cooldownUntil=0;this.total429=0;this.reason="";this.running=false;this.phase=null;this.sequence=0;this.started=0;
    }
    now(){return performance.now();}
    elapsed(){return this.started?Math.max(this.now()-this.started,Date.now()-this.wallStarted):0;}
    stop(reason="Stopped by administrator"){
      if(!this.running||this.reason)return;
      this.reason=reason;
      const p=this.phase;
      if(p){p.admitting=false;for(const c of p.active.values())c.abort();p.finish();}
    }
    snapshot(){
      const ms=this.running?this.elapsed():this.finishedMs||0;
      return {format:"p2k-oauth-lab-v1",started_at:this.wallStarted?new Date(this.wallStarted).toISOString():null,elapsed_ms:Math.min(ms,this.maxMs),actual_elapsed_ms:ms,limit_ms:this.maxMs,sample_count:this.names.length,sample_seed:this.seed,unique_candidates_used:this.cursor,stop_reason:this.reason||null,controller:this.controller,active_posts:this.phase?.active.size||0,current_phase:this.phase?.m.config.name||null,running:this.running,scenarios:this.metrics.map(summary),notes:["Direct gateway comparison; no browser cache, Green checks, automatic retries or recruitment persistence.","Controller state is shared and not reset between scenarios.","Success/completion rates are aggregate delivered responses divided by scenario wall time including drain.","Unreturned results are not counted as completed; upstream requests may continue after client abort.","Only request metadata and aggregate counts exported; no tokens, CSRF, candidate usernames or response bodies."]};
    }
    async run(){
      if(this.running)throw Error("Benchmark already running.");
      this.running=true;this.started=this.now();this.wallStarted=Date.now();
      const deadline=setTimeout(()=>this.stop("Five-minute deadline reached"),this.maxMs);
      try{
        for(let i=0;i<this.plan.length&&!this.reason;i++){
          if(this.elapsed()+this.plan[i].admissionMs+this.plan[i].drainMs>this.maxMs){this.reason="Remaining time insufficient for another scenario";break;}
          await this.runPhase(this.metrics[i]);
          this.onUpdate();
        }
      }finally{clearTimeout(deadline);this.finishedMs=this.elapsed();this.running=false;this.phase=null;this.onUpdate();}
      return this.snapshot();
    }
    runPhase(m){
      return new Promise(resolve=>{
        const cfg=m.config,t0=this.now(),wall0=Date.now(),phaseElapsed=()=>Math.max(this.now()-t0,Date.now()-wall0);
        const p={m,active:new Map(),queues:{profile:[],stats:[],games:[]},candidates:new Map(),admitting:true,finished:false,cap:Math.min(8,cfg.batch,this.transport),wake:null,pumpTimer:null,admissionTimer:null,drainTimer:null,finish:null};
        this.phase=p;m.status="running";m.controller_start=this.controller?{...this.controller}:null;
        const queued=()=>Object.values(p.queues).reduce((s,a)=>s+a.length,0);
        const open=()=>p.admitting&&!p.finished&&!this.reason&&phaseElapsed()<cfg.admissionMs&&this.elapsed()<this.maxMs;
        const finish=()=>{
          if(p.finished)return;p.finished=true;p.admitting=false;clearTimeout(p.wake);clearTimeout(p.pumpTimer);clearTimeout(p.admissionTimer);clearTimeout(p.drainTimer);
          m.elapsed_ms=phaseElapsed();m.admission_elapsed_ms=Math.min(cfg.admissionMs,m.elapsed_ms);m.partial_candidates=p.candidates.size;
          m.missing=Math.max(0,m.submitted-m.received);m.controller_end=this.controller?{...this.controller}:null;
          m.status=this.reason?"incomplete":m.elapsed_ms>=cfg.admissionMs?"completed":"incomplete";
          this.phase=null;resolve();
        };
        p.finish=finish;
        function enqueue(candidate,kind){p.queues[kind].push({candidate,kind,id:"lab"+(++this.sequence)});}
        const put=enqueue.bind(this);
        const feed=()=>{
          while(open()&&p.candidates.size<cfg.workers&&this.cursor<this.names.length){
            const candidate={name:this.names[this.cursor++],remaining:0};p.candidates.set(candidate.name,candidate);m.candidates_started++;put(candidate,"profile");
          }
          m.peak_queued=Math.max(m.peak_queued,queued());
        };
        const complete=c=>{if(p.candidates.delete(c.name))m.candidates_completed++;};
        const closeAdmission=()=>{
          p.admitting=false;for(const key of Object.keys(p.queues))p.queues[key]=[];
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
          this.controller=c;p.cap=connectionCap(cfg,this.transport,c.target,payload.median_latency_ms,payload.p95_latency_ms);
          const batch={at_ms:Math.round(this.elapsed()),endpoint:entries[0].kind,submitted:entries.length,received:payload.results.length,wall_ms:Math.round(this.now()-start),server_ms:finite(payload.elapsed_ms),
            launch_cps:finite(payload.launch_cps),cps:finite(payload.cps),peak_curl:finite(payload.peak_in_flight),requested_concurrency:finite(payload.requested_concurrency),median_ms:finite(payload.median_latency_ms),p95_ms:finite(payload.p95_latency_ms),http2:payload.http2_seen===true,controller:c,statuses:{}};
          m.batches.push(batch);m.post_latencies.push(batch.wall_ms);m.peak_curl_per_post=Math.max(m.peak_curl_per_post,batch.peak_curl);
          for(const [sec,n] of Object.entries(payload.completion_counts||{}))if(/^\d+$/.test(sec))increment(m.completion_counts,sec,finite(n));
          let unauthorized=false;
          for(const row of payload.results){
            const e=expected.get(String(row.id));if(!e||seen.has(e.id))throw Error("Unexpected or duplicate gateway result ID.");seen.add(e.id);
            const status=finite(row.status),latency=finite(row.elapsed_ms),ep=m.by_endpoint[e.kind];
            m.received++;ep.received++;increment(m.statuses,status);increment(ep.statuses,status);increment(batch.statuses,status);
            if(latency>0){m.latencies.push(latency);ep.latencies.push(latency);}
            const ok=status>=200&&status<300;
            if(ok){m.successful++;ep.successful++;}
            else if(status===429){m.rate429++;this.total429++;this.cooldownUntil=Math.max(this.cooldownUntil,this.now()+retryMs(row.headers?.["retry-after"]));}
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
          else if(m.rate429>=3||this.total429>=5)this.stop("Repeated HTTP 429; stopped to protect the API");
          else if(m.received>=16&&m.transient>=4&&m.transient/m.received>.15)this.stop("Transient failure rate exceeded 15%; stopped to protect the API");
        };
        const pump=()=>{
          if(p.finished)return;
          if(this.reason){finish();return;}
          if(!open()){closeAdmission();return;}
          if(this.cooldownUntil>this.now()){
            clearTimeout(p.wake);p.wake=setTimeout(schedule,Math.min(this.cooldownUntil-this.now(),Math.max(1,cfg.admissionMs-phaseElapsed())));return;
          }
          feed();
          if(this.cursor>=this.names.length&&!p.candidates.size&&!p.active.size&&!queued()){
            this.stop("Candidate sample exhausted before all scenarios completed");return;
          }
          while(open()&&p.active.size<cfg.posts&&queued()){
            // Profile priority matches production; stats and games alternate by oldest queued ID.
            let kind=p.queues.profile.length?"profile":!p.queues.stats.length?"games":!p.queues.games.length?"stats":Number(p.queues.stats[0].id.slice(3))<=Number(p.queues.games[0].id.slice(3))?"stats":"games";
            const entries=p.queues[kind].splice(0,cfg.batch);
            const controller=new AbortController(),key=++this.sequence,start=this.now();
            p.active.set(key,controller);m.submitted+=entries.length;m.peak_posts=Math.max(m.peak_posts,p.active.size);
            const requests=entries.map(e=>({id:e.id,url:"https://api.chess.com/pub/player/"+encodeURIComponent(e.candidate.name)+(e.kind==="profile"?"":"/"+e.kind),headers:{accept:"application/json"}}));
            const timer=setTimeout(()=>{controller.abort();this.stop("Gateway POST exceeded the timeout; no new scenario started");},Math.min(this.postTimeout,Math.max(1,this.maxMs-this.elapsed())));
            Promise.resolve().then(()=>this.post({requests,concurrency:Math.min(entries.length,p.cap),rate_cps:RATE_CEILING,traffic_class:"foreground"},controller.signal))
              .then(payload=>{if(!p.finished&&!this.reason)consume(payload,entries,start);})
              .catch(error=>{if(!p.finished&&!this.reason)this.stop(error?.message||"Gateway error");})
              .finally(()=>{clearTimeout(timer);p.active.delete(key);if(!p.finished){if(!p.admitting&&!p.active.size)finish();else schedule();}});
          }
          this.onUpdate();
        };
        p.admissionTimer=setTimeout(closeAdmission,cfg.admissionMs);
        p.drainTimer=setTimeout(()=>{if(p.active.size)this.stop("Scenario drain deadline exceeded; no overlapping scenario started");else finish();},cfg.admissionMs+cfg.drainMs);
        pump();
      });
    }
  }
  // Export only deterministic engine pieces for local regression tests.
  const engine=Object.freeze({Bench,PLAN,parseNames,connectionCap,summary,MAX_MS});
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
      const tr=document.createElement("tr"),c=m.config,values=[c.name,c.batch,c.posts,c.workers,c.headroom, m.elapsed_ms?fmt(m.successful_cps):"—",m.elapsed_ms?fmt(m.completed_cps):"—",m.rate429,fmt(m.median_ms,0),fmt(m.p95_ms,0),fmt(m.controller_end?.target),m.status];
      for(const value of values){const td=document.createElement("td");td.textContent=value;tr.appendChild(td);}$("rows").appendChild(tr);
    }
    $("bars").replaceChildren();const ranked=rows.filter(m=>m.status==="completed"&&m.config.name!=="Warm-up"),max=Math.max(1,...ranked.map(m=>m.successful_cps));
    for(const m of ranked){const row=document.createElement("div");row.className="barrow";const name=document.createElement("span");name.textContent=m.config.name;const bar=document.createElement("div");bar.className="bar";bar.style.width=(100*m.successful_cps/max)+"%";const rate=document.createElement("span");rate.textContent=fmt(m.successful_cps)+" req/s";row.append(name,bar,rate);$("bars").append(row);}
    if(snap){
      const sec=Math.min(300,Math.floor(snap.elapsed_ms/1000));$("elapsed").textContent=sec+" / 300 s";$("progress").value=sec;
      $("phase").textContent=snap.current_phase||"—";$("success").textContent=rows.reduce((n,m)=>n+m.successful,0).toLocaleString();$("limits").textContent=rows.reduce((n,m)=>n+m.rate429,0);$("rate").textContent=snap.controller?fmt(snap.controller.target)+" /s":"—";$("posts").textContent=snap.active_posts;
      if(snap.running)$("status").textContent="Running · "+(snap.current_phase||"transition")+" · keep this tab visible. "+snap.unique_candidates_used.toLocaleString()+" candidate names allocated.";
      else $("status").textContent=snap.stop_reason||"Completed. Export the results for comparison.";
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
    const fields=["name","batch","posts","workers","headroom","status","elapsed_ms","submitted","received","successful","successful_cps","completed_cps","candidates_completed","candidates_cps","rate429","transient","permanent","missing","median_ms","p95_ms","post_median_ms","post_p95_ms","target_start","target_end","safe_end","unsafe_end","peak_posts","peak_curl_per_post"];
    const csvCell=v=>'"'+String(v??"").replaceAll('"','""')+'"';
    const body=kind==="json"?JSON.stringify(snap,null,2):[fields.join(","),...snap.scenarios.map(m=>fields.map(k=>csvCell(({target_start:m.controller_start?.target,target_end:m.controller_end?.target,safe_end:m.controller_end?.safe,unsafe_end:m.controller_end?.unsafe})[k]??m.config[k]??m[k])).join(","))].join("\r\n");
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
