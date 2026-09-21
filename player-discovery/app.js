(() => {
  "use strict";
  const API = "api.php";
  const CHESS = "https://api.chess.com/pub";
  const state = { auth:null, jobs:[], job:null, running:false, scheduler:null, resultOffset:0, resultLimit:100, clientId:getClientId(), samples:[] };
  const $ = id => document.getElementById(id);
  const fmt = new Intl.NumberFormat("en-GB");
  const dateFmt = new Intl.DateTimeFormat("en-GB",{dateStyle:"medium",timeStyle:"short"});

  class AdaptiveScheduler {
    constructor(max=24){ this.max=Math.max(1,Math.min(64,Number(max)||24)); this.current=Math.min(this.max,8); this.active=0; this.queue=[]; this.backoffUntil=0; this.successes=0; this.completed=[]; this.stats={requests:0,retries:0,throttles:0,errors:0}; }
    request(url){ return new Promise((resolve,reject)=>{this.queue.push({url,resolve,reject});this.pump();}); }
    pump(){
      if(Date.now()<this.backoffUntil){setTimeout(()=>this.pump(),Math.max(50,this.backoffUntil-Date.now()));return;}
      while(this.active<this.current&&this.queue.length){const task=this.queue.shift();this.active++;this.execute(task).finally(()=>{this.active--;this.pump();});}
    }
    async execute(task){
      try{ task.resolve(await this.fetchRetry(task.url)); }
      catch(e){this.stats.errors++;task.reject(e);}
    }
    async fetchRetry(url){
      let last=null;
      for(let attempt=0;attempt<4;attempt++){
        const wait=this.backoffUntil-Date.now(); if(wait>0) await sleep(wait);
        this.stats.requests++;
        try{
          const response=await fetch(url,{headers:{Accept:"application/json"},cache:"no-store",mode:"cors"});
          this.noteComplete();
          if(response.status===429){
            this.stats.throttles++;this.stats.retries++;this.current=Math.max(1,Math.floor(this.current/2));this.successes=0;
            const retryHeader=response.headers.get("retry-after"); const retrySeconds=Number(retryHeader);
            const delay=Number.isFinite(retrySeconds)&&retrySeconds>0?retrySeconds*1000:Math.min(30000,1200*Math.pow(2,attempt));
            this.backoffUntil=Math.max(this.backoffUntil,Date.now()+delay+Math.random()*400); last=new Error("Chess.com rate limited the request (429)."); continue;
          }
          if(response.status===404||response.status===410) return {status:response.status,data:null};
          if(!response.ok){
            if(response.status>=500&&attempt<3){this.stats.retries++;last=new Error(`Chess.com HTTP ${response.status}`);await sleep(600*Math.pow(2,attempt)+Math.random()*250);continue;}
            throw new Error(`Chess.com HTTP ${response.status}`);
          }
          const data=await response.json(); this.noteSuccess(); return {status:response.status,data};
        }catch(error){
          last=error;
          if(attempt>=3) break;
          this.stats.retries++; await sleep(700*Math.pow(2,attempt)+Math.random()*300);
        }
      }
      throw last||new Error("Chess.com request failed.");
    }
    noteSuccess(){this.successes++;const threshold=Math.max(24,this.current*5);if(this.successes>=threshold&&this.current<this.max){this.current++;this.successes=0;}}
    noteComplete(){const now=Date.now();this.completed.push(now);while(this.completed.length&&this.completed[0]<now-30000)this.completed.shift();}
    rate(){const now=Date.now();while(this.completed.length&&this.completed[0]<now-30000)this.completed.shift();return this.completed.length/30;}
  }

  async function api(action,{method="GET",body=null,params=null}={}){
    const url=new URL(API,location.href);url.searchParams.set("action",action);if(params)Object.entries(params).forEach(([k,v])=>v!==undefined&&v!==null&&url.searchParams.set(k,String(v)));
    const headers={Accept:"application/json"};if(body!==null)headers["Content-Type"]="application/json";if(method!=="GET"&&state.auth?.csrf)headers["X-P2K-OAuth-CSRF"]=state.auth.csrf;
    const response=await fetch(url,{method,credentials:"same-origin",cache:"no-store",headers,body:body===null?null:JSON.stringify(body)});
    const payload=await response.json().catch(()=>({ok:false,error:{message:`HTTP ${response.status}`}}));
    if(!response.ok||payload.ok===false)throw new Error(payload?.error?.message||`HTTP ${response.status}`);return payload;
  }

  async function init(){bind();await loadSession();setInterval(renderLiveMetrics,1000);}
  function bind(){
    $("loginButton").addEventListener("click",login);$("createButton").addEventListener("click",createJob);$("refreshJobs").addEventListener("click",loadJobs);
    $("resumeButton").addEventListener("click",runJob);$("pauseButton").addEventListener("click",pauseJob);$("exportButton").addEventListener("click",exportCsv);$("deleteButton").addEventListener("click",deleteJob);
    $("applyFilters").addEventListener("click",()=>{state.resultOffset=0;loadResults();});$("resultSearch").addEventListener("keydown",e=>{if(e.key==="Enter"){state.resultOffset=0;loadResults();}});
    $("prevPage").addEventListener("click",()=>{state.resultOffset=Math.max(0,state.resultOffset-state.resultLimit);loadResults();});$("nextPage").addEventListener("click",()=>{state.resultOffset+=state.resultLimit;loadResults();});
  }
  async function loadSession(){
    try{const r=await api("session");state.auth={authenticated:r.authenticated,profile:r.profile,csrf:r.csrf,enabled:r.enabled};renderAuth();if(r.authenticated){await loadJobs();}}
    catch(e){showStatus("importStatus",e.message,true);}
  }
  function renderAuth(){
    const auth=$("authBox");auth.replaceChildren();const ok=Boolean(state.auth?.authenticated);$("loginPanel").hidden=ok;$("app").hidden=!ok;
    if(!ok)return;
    const p=state.auth.profile||{};if(p.avatar){const img=document.createElement("img");img.src=p.avatar;img.alt="";auth.appendChild(img);}const s=document.createElement("strong");s.textContent=p.username||"Chess.com user";auth.appendChild(s);
  }
  function login(){const u=new URL("../server/team-points/public/oauth.php",location.href);u.searchParams.set("action","login");u.searchParams.set("return",`${location.pathname}${location.search}`);location.assign(u.href);}

  function parseSeeds(text){
    const out=new Map();String(text||"").split(/[\s,;]+/).forEach(raw=>{const v=raw.trim().replace(/^@/,"").toLowerCase();if(/^[a-z0-9_-]{1,80}$/.test(v))out.set(v,v);});return [...out.values()];
  }
  async function createJob(){
    const names=parseSeeds($("seedInput").value);if(!names.length){showStatus("importStatus","Paste at least one valid username.",true);return;}if(names.length>100000){showStatus("importStatus",`The de-duplicated list contains ${fmt.format(names.length)} names; the current limit is 100,000.`,true);return;}
    setCreating(true);try{
      showStatus("importStatus",`Creating job for ${fmt.format(names.length)} unique seeds…`);
      let r=await api("create",{method:"POST",body:{months:Number($("monthsInput").value)||2,daily_mode:$("modeInput").value,include_chess960:$("variantInput").value==="with960",max_concurrency:Number($("concurrencyInput").value)||24}});
      const jobId=r.job.job_id;const chunk=1000;
      for(let i=0;i<names.length;i+=chunk){await api("add-seeds",{method:"POST",body:{job_id:jobId,usernames:names.slice(i,i+chunk)}});showStatus("importStatus",`Uploaded ${fmt.format(Math.min(i+chunk,names.length))} / ${fmt.format(names.length)} seeds…`);}
      r=await api("finalize-seeds",{method:"POST",body:{job_id:jobId}});state.job=r.job;$("seedInput").value="";showStatus("importStatus",`Analysis created with ${fmt.format(r.job.seed_total)} unique seeds.`,false,true);await loadJobs();selectJob(jobId);await runJob();
    }catch(e){showStatus("importStatus",e.message,true);}finally{setCreating(false);}
  }
  function setCreating(on){$("createButton").disabled=on;$("createButton").textContent=on?"Importing…":"Create analysis";}

  async function loadJobs(){
    try{const r=await api("jobs");state.jobs=r.jobs||[];renderJobs();if(state.job){const same=state.jobs.find(j=>j.job_id===state.job.job_id);if(same){state.job=same;renderJob();}}}
    catch(e){showStatus("workerStatus",e.message,true);}
  }
  function renderJobs(){
    const body=$("jobsBody");body.replaceChildren();if(!state.jobs.length){const tr=document.createElement("tr"),td=document.createElement("td");td.colSpan=7;td.textContent="No saved analyses yet.";tr.appendChild(td);body.appendChild(tr);return;}
    for(const job of state.jobs){const tr=document.createElement("tr");appendCell(tr,formatSqlDate(job.created_at));appendCell(tr,`${job.state} · ${job.phase}`);appendCell(tr,`${job.months} mo (${job.month_keys.length} archives/seed)`);appendCell(tr,fmt.format(job.seed_total));appendCell(tr,fmt.format(job.player_total));appendCell(tr,job.phase==="discovery"?pct(job.discovery_done,job.seed_total):job.phase==="complete"?"100%":pct(job.enrichment_done,job.player_total));const td=document.createElement("td"),b=document.createElement("button");b.className="button";b.textContent="Open";b.addEventListener("click",()=>selectJob(job.job_id));td.appendChild(b);tr.appendChild(td);body.appendChild(tr);}
  }
  function selectJob(id){const job=state.jobs.find(j=>j.job_id===id)||state.job;if(!job||job.job_id!==id)return;state.job=job;state.resultOffset=0;renderJob();loadResults();}
  function renderJob(){
    const j=state.job;if(!j)return;$("jobPanel").hidden=false;$("resultsPanel").hidden=false;$("jobTitle").textContent=`Analysis ${j.job_id.slice(0,8)}`;$("jobSubtitle").textContent=`Rolling ${j.months}-month window · ${j.daily_mode==="team"?"Daily team matches":"all Daily games"} · ${j.include_chess960?"standard + Chess960":"standard only"} · frozen ${formatEpoch(j.cutoff_epoch)} → ${formatEpoch(j.scan_end_epoch)}`;
    $("mPhase").textContent=`${j.state} / ${j.phase}`;$("mSeeds").textContent=fmt.format(j.seed_total);$("mPlayers").textContent=fmt.format(j.player_total);$("mDiscovery").textContent=`${fmt.format(j.discovery_done)} / ${fmt.format(j.seed_total)}`;$("mEnrichment").textContent=`${fmt.format(j.enrichment_done)} / ${fmt.format(j.player_total)}`;$("mRequests").textContent=fmt.format((j.archive_requests_done||0)+(j.enrichment_requests_done||0));
    const progress=j.phase==="discovery"?ratio(j.discovery_done,j.seed_total):j.phase==="complete"?1:ratio(j.enrichment_done,j.player_total);$("progressBar").style.width=`${Math.round(progress*100)}%`;$("resumeButton").disabled=state.running||j.state==="complete";$("pauseButton").disabled=!state.running;$("exportButton").disabled=j.player_total===0;
  }

  async function runJob(){
    if(!state.job||state.running)return;
    if(navigator.locks?.request){
      await navigator.locks.request("p2k-player-discovery-worker",{ifAvailable:true},async lock=>{
        if(!lock){showStatus("workerStatus","Another Player Discovery tab is already running in this browser.",true);return;}
        await runJobLocked();
      });
      return;
    }
    await runJobLocked();
  }
  async function runJobLocked(){
    if(!state.job||state.running)return;state.running=true;state.samples=[];state.scheduler=new AdaptiveScheduler(state.job.max_concurrency||24);renderJob();showStatus("workerStatus","Starting browser worker…");
    try{
      let r=await api("resume",{method:"POST",body:{job_id:state.job.job_id,client_id:state.clientId}});state.job=r.job;renderJob();
      while(state.running&&state.job.state!=="complete"){
        r=await api("claim",{method:"POST",body:{job_id:state.job.job_id,client_id:state.clientId,limit:50}});state.job=r.job;renderJob();if(!state.running||state.job.state!=="running")break;
        const items=r.items||[];if(!items.length){await refreshSelectedJob();if(state.job.state==="complete")break;showStatus("workerStatus","No immediately claimable work; waiting for leases or phase transition…");await sleep(1200);continue;}
        const started=performance.now();const doneBefore=completedUnits(state.job);const phase=state.job.phase;showStatus("workerStatus",`${phase==="discovery"?"Scanning archives":"Enriching profiles"}: processing ${items.length} players…`);
        await Promise.all(items.map(item=>phase==="discovery"?processDiscovery(item,r.lease_token):processEnrichment(item,r.lease_token)));
        await refreshSelectedJob();const delta=Math.max(0,completedUnits(state.job)-doneBefore);const seconds=Math.max(.01,(performance.now()-started)/1000);if(delta>0){state.samples.push({units:delta,seconds});if(state.samples.length>12)state.samples.shift();}
      }
      if(state.job?.state==="complete"){showStatus("workerStatus","Analysis complete.",false,true);await loadResults();await loadJobs();}
    }catch(e){showStatus("workerStatus",e.message,true);}finally{state.running=false;renderJob();}
  }
  async function pauseJob(){if(!state.job)return;state.running=false;try{const r=await api("pause",{method:"POST",body:{job_id:state.job.job_id}});state.job=r.job;showStatus("workerStatus","Paused. In-flight requests may finish and checkpoint before stopping.");renderJob();await loadJobs();}catch(e){showStatus("workerStatus",e.message,true);}}

  async function processDiscovery(item,leaseToken){
    const user=item.username_key;let requests=0,dailyGames=0,edges=0;const opponents=new Set();
    try{
      const calls=state.job.month_keys.map(async key=>{requests++;const [year,month]=key.split("/");const url=`${CHESS}/player/${encodeURIComponent(user)}/games/${year}/${month}`;const r=await state.scheduler.request(url);if(!r.data)return;for(const game of Array.isArray(r.data.games)?r.data.games:[]){const end=Number(game.end_time||0);if(end<state.job.cutoff_epoch||end>state.job.scan_end_epoch)continue;if(String(game.time_class||"")!=="daily")continue;if(state.job.daily_mode==="team"&&!game.match)continue;const rules=String(game.rules||"chess");if(rules!=="chess"&&!(state.job.include_chess960&&rules==="chess960"))continue;dailyGames++;const white=normalize(game.white?.username),black=normalize(game.black?.username);let opp="";if(white===user)opp=black;else if(black===user)opp=white;if(opp&&opp!==user){opponents.add(opp);edges++;}}});
      await Promise.all(calls);
      const r=await api("complete-discovery",{method:"POST",body:{job_id:state.job.job_id,username:user,lease_token:leaseToken,opponents:[...opponents],metrics:{requests,daily_games:dailyGames,opponent_edges:edges}}});state.job=r.job;
    }catch(e){await failItem("discovery",user,leaseToken,e);}
  }

  async function processEnrichment(item,leaseToken){
    const user=item.username_key;let requests=0;
    try{
      requests++;const profileResult=await state.scheduler.request(`${CHESS}/player/${encodeURIComponent(user)}`);let profile=profileResult.data;
      if(!profile){profile={username:user,status:profileResult.status===410?"gone":"not_found"};const r=await api("complete-enrichment",{method:"POST",body:{job_id:state.job.job_id,username:user,lease_token:leaseToken,payload:{profile,stats:{},clubs:{}},metrics:{requests}}});state.job=r.job;return;}
      const [statsResult,clubsResult]=await Promise.all([
        (async()=>{requests++;return state.scheduler.request(`${CHESS}/player/${encodeURIComponent(user)}/stats`);})(),
        (async()=>{requests++;return state.scheduler.request(`${CHESS}/player/${encodeURIComponent(user)}/clubs`);})()
      ]);
      const r=await api("complete-enrichment",{method:"POST",body:{job_id:state.job.job_id,username:user,lease_token:leaseToken,payload:{profile,stats:statsResult.data||{},clubs:clubsResult.data||{}},metrics:{requests}}});state.job=r.job;
    }catch(e){await failItem("enrichment",user,leaseToken,e);}
  }
  async function failItem(phase,user,leaseToken,error){try{const r=await api("fail",{method:"POST",body:{job_id:state.job.job_id,phase,username:user,lease_token:leaseToken,message:String(error?.message||error)}});state.job=r.job;}catch(e){console.warn("Unable to checkpoint failed work",e);}}
  async function refreshSelectedJob(){if(!state.job)return;const r=await api("job",{params:{job_id:state.job.job_id}});state.job=r.job;renderJob();}

  function renderLiveMetrics(){
    if(!state.job)return;const s=state.scheduler;$("mRate").textContent=s?`${s.rate().toFixed(1)} req/s`:"—";$("mConcurrency").textContent=s?`${s.current} / ${s.max}`:"—";$("mThrottle").textContent=s?`${fmt.format(s.stats.throttles)} / ${fmt.format(s.stats.retries)}`:"—";$("mEta").textContent=estimateEta();
  }
  function estimateEta(){if(!state.running||!state.job||!state.samples.length)return state.job?.state==="complete"?"Done":"—";const units=state.samples.reduce((a,b)=>a+b.units,0),seconds=state.samples.reduce((a,b)=>a+b.seconds,0);if(units<=0||seconds<=0)return"—";const rate=units/seconds;const remaining=state.job.phase==="discovery"?Math.max(0,state.job.seed_total-state.job.discovery_done):Math.max(0,state.job.player_total-state.job.enrichment_done);return humanDuration(remaining/rate);}

  async function loadResults(){
    if(!state.job)return;try{const r=await api("results",{params:{job_id:state.job.job_id,offset:state.resultOffset,limit:state.resultLimit,query:$("resultSearch").value,source:$("sourceFilter").value,sort:$("sortFilter").value}});renderResults(r);}catch(e){showStatus("workerStatus",e.message,true);}
  }
  function renderResults(r){
    const body=$("resultsBody");body.replaceChildren();for(const row of r.rows||[]){const tr=document.createElement("tr"),player=document.createElement("td"),wrap=document.createElement("div");wrap.className="player-cell";if(row.avatar_url){const img=document.createElement("img");img.src=row.avatar_url;img.alt="";wrap.appendChild(img);}const a=document.createElement("a");const username=row.canonical_username||row.username_display;a.textContent=(row.title?`${row.title} `:"")+username;a.href=`https://www.chess.com/member/${encodeURIComponent(username)}`;a.target="_blank";a.rel="noopener noreferrer";wrap.appendChild(a);if(row.display_name){const small=document.createElement("span");small.textContent=` · ${row.display_name}`;wrap.appendChild(small);}player.appendChild(wrap);tr.appendChild(player);
      const source=document.createElement("td");if(Number(row.is_seed))source.appendChild(tag("seed"));if(Number(row.is_discovered))source.appendChild(tag("opponent"));if(Number(row.discovery_hits)>1)source.appendChild(tag(`${row.discovery_hits} hits`));tr.appendChild(source);
      appendCell(tr,row.daily_rating??"—");appendCell(tr,row.last_online_epoch?formatEpoch(row.last_online_epoch):"—");appendCell(tr,row.club_count??"—");appendCell(tr,[row.daily_wins,row.daily_draws,row.daily_losses].map(v=>v??"—").join(" / "));appendCell(tr,row.daily_timeout_percent==null?"—":`${row.daily_timeout_percent}%`);appendCell(tr,row.profile_status||row.enrichment_state||"—");body.appendChild(tr);}
    if(!(r.rows||[]).length){const tr=document.createElement("tr"),td=document.createElement("td");td.colSpan=8;td.textContent="No matching players yet.";tr.appendChild(td);body.appendChild(tr);}const start=r.total?state.resultOffset+1:0,end=Math.min(r.total,state.resultOffset+state.resultLimit);$("pageStatus").textContent=`${fmt.format(start)}–${fmt.format(end)} of ${fmt.format(r.total)}`;$("prevPage").disabled=state.resultOffset<=0;$("nextPage").disabled=end>=r.total;
  }
  function exportCsv(){if(!state.job)return;const u=new URL(API,location.href);u.searchParams.set("action","export");u.searchParams.set("job_id",state.job.job_id);location.assign(u.href);}
  async function deleteJob(){if(!state.job||!confirm("Delete this analysis and all persisted results?"))return;state.running=false;try{await api("delete",{method:"POST",body:{job_id:state.job.job_id}});state.job=null;$("jobPanel").hidden=true;$("resultsPanel").hidden=true;await loadJobs();}catch(e){showStatus("workerStatus",e.message,true);}}

  function getClientId(){let id=localStorage.getItem("p2k-player-discovery-client");if(!/^[a-f0-9]{32}$/.test(id||"")){const a=new Uint8Array(16);crypto.getRandomValues(a);id=[...a].map(x=>x.toString(16).padStart(2,"0")).join("");localStorage.setItem("p2k-player-discovery-client",id);}return id;}
  function normalize(v){v=String(v||"").trim().toLowerCase();return /^[a-z0-9_-]{1,80}$/.test(v)?v:"";}
  function completedUnits(j){return j.phase==="discovery"?j.discovery_done:j.enrichment_done;}
  function ratio(a,b){return b>0?Math.max(0,Math.min(1,a/b)):0;}
  function pct(a,b){return `${Math.round(ratio(a,b)*100)}%`;}
  function appendCell(tr,value){const td=document.createElement("td");td.textContent=String(value??"");tr.appendChild(td);return td;}
  function tag(text){const s=document.createElement("span");s.className="tag";s.textContent=text;return s;}
  function formatEpoch(v){const n=Number(v);return Number.isFinite(n)&&n>0?dateFmt.format(new Date(n*1000)):"—";}
  function formatSqlDate(v){if(!v)return"—";const d=new Date(String(v).replace(" ","T")+"Z");return Number.isNaN(d.getTime())?v:dateFmt.format(d);}
  function showStatus(id,text,error=false,good=false){const el=$(id);if(!el)return;el.textContent=text;el.className=`status${error?" error":good?" good":""}`;}
  function humanDuration(seconds){if(!Number.isFinite(seconds)||seconds<0)return"—";if(seconds<60)return`${Math.ceil(seconds)}s`;if(seconds<3600)return`${Math.ceil(seconds/60)}m`;if(seconds<86400)return`${(seconds/3600).toFixed(seconds<18000?1:0)}h`;return`${(seconds/86400).toFixed(1)}d`;}
  function sleep(ms){return new Promise(r=>setTimeout(r,Math.max(0,ms)));}
  init();
})();
