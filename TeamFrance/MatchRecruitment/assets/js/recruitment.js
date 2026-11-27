(()=>{"use strict";
const CLUB="team-france",C=window.P2K_RECRUITMENT_V2,$=id=>document.getElementById(id);
const esc=x=>String(x??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;");
const u={form:$("p2kMatchForm"),ref:$("p2kMatchReference"),load:$("p2kLoadButton"),settings:$("p2kSettingsPanel"),summary:$("p2kMatchSummary"),recruit:$("p2kRecruitmentForm"),scan:$("p2kScanButton"),online:$("p2kOnlineDays"),timeout:$("p2kTimeoutRate"),status:$("p2kStatus"),statusText:$("p2kStatusText"),actions:$("p2kProcessingActions"),cancel:$("p2kCancelButton"),track:$("p2kProgressTrack"),bar:$("p2kProgressBar"),details:$("p2kProgressDetails"),funnel:$("p2kFunnel"),notice:$("tgpRosterNotice"),results:$("p2kResults")};
const state={match:null,id:null,ours:null,opp:null,controller:null,result:null,sort:"rating",direction:-1};
if(!C){u.results.textContent="Recruitment core failed to load.";return;}
function status(msg,type="working",pct=null){u.status.style.display="block";u.status.classList.toggle("p2k-error",type==="error");u.status.classList.toggle("p2k-success",type==="success");u.statusText.textContent=msg;u.track.style.display=pct===null?"none":"block";if(pct!==null)u.bar.style.width=Math.max(0,Math.min(100,pct))+"%"}
function busy(on){u.load.disabled=on;u.scan.disabled=on;u.online.disabled=on;u.timeout.disabled=on;u.actions.style.display=on?"flex":"none"}
const key=x=>String(x||"").trim().toLowerCase();
function clubSlug(t){for(const field of ["@id","url"]){try{const url=new URL(String(t?.[field]||""));if(!["api.chess.com","www.chess.com","chess.com"].includes(url.hostname.toLowerCase()))continue;const m=url.pathname.match(/\/(?:pub\/)?club\/([^/]+)\/?$/i);if(m)return decodeURIComponent(m[1]).toLowerCase()}catch{}}return""}
function clubs(data){if(!Array.isArray(data?.clubs))return null;const result=new Set();for(const item of data.clubs){const slug=clubSlug(typeof item==="string"?{"@id":item}:item);if(slug)result.add(slug)}return result}
function sides(match){const teams=match?.teams;if(!teams||typeof teams!=="object")throw Error("The match has no team data.");const entries=Object.entries(teams).filter(([,v])=>v&&typeof v==="object");if(entries.length!==2)throw Error("The match does not have exactly two teams.");const ours=entries.find(([,v])=>clubSlug(v)===CLUB),opp=entries.find(x=>x!==ours);if(!ours||!opp||clubSlug(opp[1])==="")throw Error("Only matches involving Team France are supported.");return{ours:ours[1],opp:opp[1]}}
const csrf=document.querySelector('meta[name="team-france-recruitment-csrf"]')?.content||"";
const queue=[];const events=[];let errorCount=0,requestCount=0;
const responseCache=new Map();const CACHE_TTL_MS=90000;
const BATCH_SIZE=24,MAX_GATEWAY_POSTS=4, BATCH_WINDOW_MS=28;
let activePosts=0,pumpTimer=null,logTimer=null;
let progressFrame=null,latestProgress=null,completedRequests=0,lastMeasuredRate=0,rateWindowStart=performance.now();
let adaptiveWorkers=96,rateLimitCount=0,scanCompletedRequests=0,scanSuccessfulRequests=0;
function renderLog(){logTimer=null;const head=document.getElementById("tgpLogSummary"),body=document.getElementById("tgpLogContent");if(head)head.textContent="Request / event log · "+requestCount+" requests · "+errorCount+" errors · "+deferredCount+" deferred · "+events.length+" recent events";if(body&&document.getElementById("tgpRequestLog")?.open)body.textContent=events.map(e=>"["+e.utc+"] "+e.level.toUpperCase()+" "+e.phase+" "+e.message+(e.status!==undefined?" (HTTP "+e.status+")":"")+(e.ms!==undefined?" "+e.ms+"ms":"")).join("\n")}
function scheduleLog(){if(logTimer===null)logTimer=setTimeout(renderLog,250)}
function logEvent(level,phase,message,meta={}){events.push({utc:new Date().toISOString(),level,phase,message,...meta});if(events.length>500)events.shift();if(level==="error")errorCount++;if(level==="request")requestCount++;scheduleLog()}
document.getElementById("tgpRequestLog")?.addEventListener("toggle",()=>{if(document.getElementById("tgpRequestLog")?.open)renderLog()});
function pause(ms,signal){return new Promise((resolve,reject)=>{if(signal?.aborted)return reject(new DOMException("Cancelled","AbortError"));let done=false;const abort=()=>{if(done)return;done=true;clearTimeout(t);reject(new DOMException("Cancelled","AbortError"))};const t=setTimeout(()=>{done=true;signal?.removeEventListener("abort",abort);resolve()},ms);signal?.addEventListener("abort",abort,{once:true})})}
let notBefore=0,deferredCount=0;
function retryDeadline(value,now=Date.now()){const raw=String(value??"").trim();if(!raw)return 0;if(/^\d+(?:\.\d+)?$/.test(raw)){const d=now+Number(raw)*1000;return Number.isFinite(d)?Math.ceil(d):0}const d=Date.parse(raw);return Number.isFinite(d)?Math.max(now,d):0}
async function waitUntil(deadline,signal){while(deadline>Date.now())await pause(Math.min(60000,Math.ceil(deadline-Date.now())),signal);if(signal?.aborted)throw new DOMException("Cancelled","AbortError")}
function extendCooldown(deadline){if(!Number.isFinite(deadline)||deadline<=notBefore||deadline<=Date.now())return;notBefore=deadline;if(pumpTimer!==null){clearTimeout(pumpTimer);pumpTimer=null}schedulePump()}
// The PHP coordinator alone owns rate admission. These are bounded feeder hints.
const MAX_CANDIDATE_WORKERS=120,MAX_SLOW_POSTS=3;
let activeSlowPosts=0,sharedRate=24,controllerStamp=0;
function endpointClass(url){const path=new URL(url).pathname;if(path.endsWith("/stats"))return"stats";if(path.endsWith("/clubs"))return"clubs";if(path.startsWith("/pub/player/"))return"profile";return"other"}
function batchLimit(){return Math.max(1,Math.min(BATCH_SIZE,Math.ceil(sharedRate*.8)))}
function observeController(controller,throttled=false){
 const rate=Number(controller?.rate_target_cps),ceiling=Number(controller?.effective_ceiling_cps),stamp=Number(controller?.observed_at_ms)||0;
 if(Number.isFinite(rate)&&rate>0&&stamp>=controllerStamp){
  controllerStamp=stamp;sharedRate=Math.max(1,Math.min(30,Number.isFinite(ceiling)&&ceiling>0?ceiling:30,rate));
 }else if(throttled&&!(Number.isFinite(rate)&&rate>0)){sharedRate=Math.max(1,sharedRate*.7)}
 adaptiveWorkers=Math.min(MAX_CANDIDATE_WORKERS,Math.max(8,Math.ceil(sharedRate*4)));
}
function nextClass(){const next=queue.find(x=>!x.settled&&(endpointClass(x.url)!=="stats"||activeSlowPosts<MAX_SLOW_POSTS));return next?endpointClass(next.url):null}
function takeBatch(kind,limit){const chunk=[];for(let i=0;i<queue.length&&chunk.length<limit;){const x=queue[i];if(x.settled){queue.splice(i,1);continue}if(endpointClass(x.url)===kind){chunk.push(x);queue.splice(i,1)}else i++}return chunk}
function schedulePump(){if(pumpTimer!==null||activePosts>=MAX_GATEWAY_POSTS)return;const kind=nextClass();if(!kind)return;const size=queue.reduce((n,x)=>n+(!x.settled&&endpointClass(x.url)===kind?1:0),0);const delay=Math.max(Math.min(60000,Math.max(0,notBefore-Date.now())),size>=batchLimit()?0:BATCH_WINDOW_MS);pumpTimer=setTimeout(()=>{pumpTimer=null;void pump()},delay)}
function sendQueued(url,signal){if(signal?.aborted)return Promise.reject(new DOMException("Cancelled","AbortError"));return new Promise((resolve,reject)=>{const req={url,signal,settled:false};const finish=(fn,value)=>{if(req.settled)return;req.settled=true;req.dispose();fn(value)};req.resolve=value=>finish(resolve,value);req.reject=error=>finish(reject,error);const abort=()=>{const i=queue.indexOf(req);if(i>=0)queue.splice(i,1);req.reject(new DOMException("Cancelled","AbortError"));if(!queue.length&&pumpTimer!==null){clearTimeout(pumpTimer);pumpTimer=null}};req.dispose=()=>signal?.removeEventListener("abort",abort);signal?.addEventListener("abort",abort,{once:true});queue.push(req);schedulePump()})}
async function pump(){if(notBefore>Date.now()){schedulePump();return}while(activePosts<MAX_GATEWAY_POSTS){const kind=nextClass();if(!kind)return;const chunk=takeBatch(kind,batchLimit());if(!chunk.length)continue;activePosts++;if(kind==="stats")activeSlowPosts++;void executeBatch(chunk).finally(()=>{activePosts--;if(kind==="stats")activeSlowPosts--;schedulePump()})}}
function gatewayError(message,status,deadline=0,deferred=false){return Object.assign(Error(message),{status,notBeforeMs:deadline,deferred:deferred===true})}
async function executeBatch(chunk){const started=performance.now();try{
const r=await fetch("api/?action=batch",{method:"POST",credentials:"same-origin",cache:"no-store",headers:{"Content-Type":"application/json","Accept":"application/json","X-P2K-OAuth-CSRF":csrf},body:JSON.stringify({requests:chunk.map(x=>({url:x.url}))})});
const p=await r.json();if(!r.ok||p?.ok!==true||p?.mode!=="oauth-bearer"){const deadline=Math.max(retryDeadline(r.headers?.get?.("retry-after")),retryDeadline(p?.retry_after),p?.deferred===true||r.status===429?Date.now()+2000:0);if(p?.deferred===true||r.status===429||r.status===503)extendCooldown(deadline);if(r.status===429)observeController(p?.controller,true);throw gatewayError(p?.error?.message||"Bearer gateway HTTP "+r.status,r.status,deadline,p?.deferred===true)}
const remaining=Number(p.cooldown_remaining_ms||0);if(remaining>0)extendCooldown(Date.now()+remaining);
const entries=new Map((p.results||[]).map(x=>[String(x.id),x]));for(const row of entries.values()){if(row.deferred===true||Number(row.status)===429){const deadline=retryDeadline(row.retry_after);extendCooldown(Math.max(deadline,Date.now()+2000))}}
observeController(p.controller,[...entries.values()].some(row=>Number(row.status)===429&&row.deferred!==true));
for(let i=0;i<chunk.length;i++){
const x=chunk[i],response=entries.get(String(i));if(!response){const err=gatewayError("Bearer response missing for "+new URL(x.url).pathname,502);logEvent("error","gateway",err.message);x.reject(err);continue}
const code=Number(response.status)||0,info={status:code,ms:Number(response.elapsed_ms)||0};
if(response.deferred===true){deferredCount++;logEvent("deferred","scheduler","Not launched: "+new URL(x.url).pathname+" · cooldown until "+new Date(notBefore).toISOString());x.reject(gatewayError("OAuth request deferred before launch.",503,notBefore,true));continue}
logEvent("request","Chess.com",new URL(x.url).pathname);completedRequests++;scanCompletedRequests++;if(code>=200&&code<300)scanSuccessfulRequests++;logEvent(code>=200&&code<300?"success":"error","Chess.com",new URL(x.url).pathname,info);
if(code===429){rateLimitCount++;logEvent("info","scheduler","HTTP 429: shared feeder target "+adaptiveWorkers)}
if(code>=200&&code<300&&response.json&&typeof response.json==="object")x.resolve(response.json);
else{x.reject(gatewayError("Chess.com returned HTTP "+code+" for "+new URL(x.url).pathname,code,Math.max(retryDeadline(response.retry_after),code===429?notBefore:0)))}
}}
catch(e){if(e.deferred===true){deferredCount+=chunk.length;logEvent("deferred","gateway","Batch not launched; waiting for shared cooldown.")}else logEvent("error","gateway",String(e.message||e),{status:e.status||0,ms:Math.round(performance.now()-started)});chunk.forEach(x=>x.reject(e))}
finally{chunk.forEach(x=>x.dispose?.())}}
async function chess(url,signal){const reusable=!url.endsWith("/clubs")&&!url.includes("/pub/match/")&&!url.includes("/pub/club/");const cached=responseCache.get(url);if(reusable&&cached&&cached.until>Date.now()){logEvent("info","cache","reused "+new URL(url).pathname);return cached.body}
for(let attempt=1;attempt<=3;attempt++){try{const body=await sendQueued(url,signal);if(signal?.aborted)throw new DOMException("Cancelled","AbortError");if(reusable){responseCache.set(url,{body,until:Date.now()+CACHE_TTL_MS});if(responseCache.size>5000){for(const [k,v] of responseCache){if(v.until<Date.now())responseCache.delete(k);if(responseCache.size<=4000)break}}}return body}catch(e){if(signal?.aborted)throw e;if(e.deferred===true){await waitUntil(Math.max(notBefore,Number(e.notBeforeMs)||Date.now()+2000),signal);attempt--;continue}const status=Number(e.status)||0;const transient=[0,408,429,500,502,503,504].includes(status);if(!transient||attempt===3)throw e;const deadline=Math.max(Number(e.notBeforeMs)||0,notBefore,Date.now()+500*Math.pow(2,attempt-1)+Math.round(Math.random()*300));logEvent("retry","Chess.com","attempt "+(attempt+1)+" for "+new URL(url).pathname+" after "+Math.max(0,deadline-Date.now())+"ms");await waitUntil(deadline,signal)}}throw Error("Retries exhausted")}
async function fetchRoster(signal){for(let attempt=1;attempt<=3;attempt++){while(notBefore>Date.now())await waitUntil(notBefore,signal);const r=await fetch("api/?action=roster",{signal,cache:"no-store",credentials:"same-origin",headers:{Accept:"application/json"}});const roster=await r.json();if(roster?.deferred===true||r.status===429){extendCooldown(Math.max(retryDeadline(r.headers?.get?.("retry-after")),retryDeadline(roster.retry_after),Date.now()+2000));if(roster.deferred===true){deferredCount++;logEvent("deferred","roster","Roster not launched; waiting for shared cooldown.");attempt--;}else{logEvent("error","roster","Roster throttled",{status:429});if(attempt===3)return{r,roster}}status("Waiting for the shared OAuth cooldown before loading the roster…","working");continue}return{r,roster}}throw Error("Roster retries exhausted")}

function inactiveProfile(profile,criteria,now=Date.now()/1000){const value=Number(profile?.last_online);return Number.isFinite(value)&&value>0&&value<=now&&now-value>criteria.onlineDays*86400}
function criteria(){const settings=state.match?.settings||{},category=C.ratingCategory(settings.rules),max=Number(settings.max_rating),min=Number(settings.min_rating);const s={ratingCategory:category,apiRules:category==="daily_chess960"?"chess960":"chess",min:Number.isFinite(min)&&min>0?min:0,max:Number.isFinite(max)&&max>0?max:Infinity,onlineDays:Number(u.online.value),maxTimeout:Number(u.timeout.value)};if(!Number.isFinite(s.onlineDays)||s.onlineDays<0.04||s.onlineDays>365)throw Error("Online within must be between 0.04 and 365 days.");if(!Number.isFinite(s.maxTimeout)||s.maxTimeout<0||s.maxTimeout>100)throw Error("Maximum timeout rate must be between 0 and 100%.");return s}
function registered(){return new Set([...(state.ours?.players||[]),...(state.opp?.players||[])].map(x=>key(x.username)).filter(Boolean))}
function maxRating(stats,cat){const entry=cat==="daily_chess960"?(stats?.chess960_daily||stats?.chess_daily960):stats?.chess_daily;const best=Number(entry?.best?.rating);return Number.isFinite(best)&&best>0?best:null}
function rating(stats,cat){const entry=cat==="daily_chess960"?(stats?.chess960_daily||stats?.chess_daily960):(stats?.chess_daily);const value=Number(entry?.last?.rating);return Number.isFinite(value)&&value>0?value:null}
function liveDetails(stats,profile){const pct=Number(stats?.chess_daily?.record?.timeout_percent);return{last_online:Number(profile?.last_online)||null,timeout_percent:Number.isFinite(pct)?pct:null,current_match_load:null,current_match_load_error:"Not requested"}}
function note(text){u.notice.hidden=!text;u.notice.textContent=text||""}
async function loadClubAvatar(){const avatar=document.getElementById("p2kSelectedTeamLogo");if(!avatar)return;try{const data=await chess("https://api.chess.com/pub/club/"+CLUB);const url=String(data?.icon||data?.image||"");const parsed=new URL(url);if(parsed.protocol!=="https:"||!(["images.chesscomfiles.com","www.chess.com","chess.com"].includes(parsed.hostname)))throw Error("Club avatar URL unavailable");avatar.src=url;avatar.hidden=false;avatar.onerror=()=>{avatar.hidden=true;logEvent("error","avatar","Could not load club image")}}catch(error){logEvent("error","avatar",String(error.message||error))}}

async function load(){if(state.controller)return;const ctl=new AbortController();state.controller=ctl;busy(true);note("");status("Resolving match…","working",5);
try{const id=C.parseMatchReference(u.ref.value,location.href);if(!/^\d{1,18}$/.test(id))throw Error("Invalid match ID.");const match=await chess("https://api.chess.com/pub/match/"+encodeURIComponent(id),ctl.signal);const {ours,opp}=sides(match);
state.match=match;state.id=String(match.id||id);state.ours=ours;state.opp=opp;u.ref.value=state.id;u.settings.hidden=false;
const s=criteria();const title=match.name||"Match "+id;const matchLink=document.createElement("a");matchLink.textContent=title;matchLink.href="https://www.chess.com/club/matches/team-france/"+encodeURIComponent(state.id);matchLink.target="_blank";matchLink.rel="noopener noreferrer";u.summary.replaceChildren(matchLink,document.createTextNode(" · Team France vs "+(opp.name||clubSlug(opp))+" · "+(s.ratingCategory==="daily_chess960"?"Daily Chess960":"Standard Daily")));u.results.textContent="Match resolved. Set the eligibility filters and start recruitment.";status("Match resolved.","success");
}catch(e){if(!ctl.signal.aborted){status(e.message||String(e),"error");u.settings.hidden=true;u.results.textContent="Unable to load this Team France match."}}finally{state.controller=null;busy(false)}}
async function mapPool(items,task,signal,onComplete){let cursor=0,done=0,active=0;const results=new Array(items.length);const workers=Array.from({length:MAX_CANDIDATE_WORKERS},async(_,worker)=>{while(cursor<items.length&&!signal.aborted){if(worker>=adaptiveWorkers){await pause(100,signal);continue}const idx=cursor++;active++;try{results[idx]=await task(items[idx],idx)}catch(e){if(signal.aborted)throw e;results[idx]={kind:"unverified",username:items[idx]?.username||items[idx],reason:e?.message||String(e)};logEvent("error","candidate",results[idx].username+": "+results[idx].reason)}finally{active--;done++;onComplete?.(done,items.length,results[idx])}}});await Promise.all(workers);if(signal.aborted)throw new DOMException("Cancelled","AbortError");return results}
function display(result){state.result=result;const eligible=result.rows.filter(x=>x.decision==="eligible"),unverified=result.rows.filter(x=>x.decision==="unverified");
u.results.innerHTML='<div class="p2k-result-cards"><div class="p2k-result-card"><div class="p2k-result-card-value">'+result.rosterCount+'</div><div class="p2k-result-card-label">Team France members</div></div><div class="p2k-result-card"><div class="p2k-result-card-value">'+result.ratingChecked+'</div><div class="p2k-result-card-label">Rating checked</div></div><div class="p2k-result-card p2k-good"><div class="p2k-result-card-value">'+eligible.length+'</div><div class="p2k-result-card-label">Eligible</div></div><div class="p2k-result-card"><div class="p2k-result-card-value">'+unverified.length+'</div><div class="p2k-result-card-label">Unverified</div></div></div>'+
'<div class="p2k-results-tools"><label>Search <input id="tgpSearch" type="search" placeholder="Username or name"></label><button class="p2k-main-button" id="tgpCsv" type="button">Download eligible CSV</button></div><div class="p2k-partial-note">Only currently verified Team France members who are not opponent-club members are included. '+unverified.length+' candidate(s) could not be verified.</div>'+
'<div class="p2k-recruit-table-wrap"><table class="p2k-recruit-table"><thead><tr><th data-sort="username">Username</th><th data-sort="name">Name</th><th data-sort="rating">Rating</th><th data-sort="max_rating">Max rating</th><th data-sort="timeout">Timeout</th><th data-sort="last_online_age_hours">Last online</th><th data-sort="joined_tgp_epoch">Joined Team France (UTC)</th></tr></thead><tbody id="tgpRows"></tbody></table></div><nav class="tgp-pagination" aria-label="Eligible players pagination"><button class="p2k-cancel-button" id="tgpPrevPage" type="button">Previous</button><span id="tgpPageInfo" aria-live="polite"></span><button class="p2k-cancel-button" id="tgpNextPage" type="button">Next</button></nav>';
const utc=epoch=>Number(epoch)>0?new Date(Number(epoch)*1000).toISOString().replace("T"," ").replace(".000Z"," UTC").replace("Z"," UTC"):"";
let page=1;const PER_PAGE=50;const draw=()=>{const q=key($("tgpSearch").value);const rows=eligible.filter(x=>key(x.username).includes(q)||key(x.name).includes(q)).sort((a,b)=>{const val=r=>state.sort==="timeout"?r.live.timeout_percent:r[state.sort];const aa=val(a),bb=val(b);let n;if(aa==null||bb==null)n=aa==null?(bb==null?0:1):-1;else n=typeof aa==="string"?String(aa).localeCompare(String(bb)):Number(aa)-Number(bb);return(n||a.username.localeCompare(b.username))*state.direction});const pages=Math.max(1,Math.ceil(rows.length/PER_PAGE));page=Math.min(page,pages);$("tgpPageInfo").textContent="Page "+page+" of "+pages+" · "+rows.length+" players (50 per page)";$("tgpPrevPage").disabled=page<=1;$("tgpNextPage").disabled=page>=pages;$("tgpRows").innerHTML=rows.slice((page-1)*PER_PAGE,page*PER_PAGE).map(r=>'<tr><td><a target="_blank" rel="noopener" href="https://www.chess.com/member/'+encodeURIComponent(r.username)+'">'+esc(r.username)+'</a></td><td>'+esc(r.name||"")+'</td><td>'+r.rating+'</td><td>'+(r.max_rating??"")+'</td><td>'+Number(r.live.timeout_percent).toFixed(1)+'%</td><td>'+utc(r.live.last_online)+'</td><td>'+utc(r.joined_tgp_epoch)+'</td></tr>').join("")||'<tr><td colspan="7">No eligible players found.</td></tr>'};
$("tgpSearch").addEventListener("input",()=>{page=1;draw()});$("tgpPrevPage").onclick=()=>{if(page>1){page--;draw()}};$("tgpNextPage").onclick=()=>{page++;draw()};u.results.querySelectorAll("th[data-sort]").forEach(th=>th.addEventListener("click",()=>{state.direction=state.sort===th.dataset.sort?-state.direction:-1;state.sort=th.dataset.sort;page=1;draw()}));
$("tgpCsv").addEventListener("click",()=>{const csv=C.csv(eligible,result.match),url=URL.createObjectURL(new Blob([csv],{type:"text/csv;charset=utf-8"})),a=document.createElement("a");a.href=url;a.download="team-france-match-"+state.id+"-eligible.csv";a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)});draw()}

async function scan(){if(state.controller||!state.match)return;const ctl=new AbortController();state.controller=ctl;busy(true);u.details.hidden=false;u.funnel.hidden=true;
try{
const s=criteria();responseCache.clear();completedRequests=0;lastMeasuredRate=0;rateWindowStart=performance.now();state.result=null;u.results.textContent="Recruitment scan in progress…";note("");
status("Loading current Team France membership roster…","working",5);
const {r,roster}=await fetchRoster(ctl.signal);logEvent(r.ok?"success":"error","Team France roster",r.ok?"Membership "+(roster.source||"unknown")+" ("+Number(roster.count||0)+" members)":"Roster request failed",{status:r.status});if(!r.ok||!roster.ok)throw Error(roster?.error?.message||"Membership list unavailable.");
note(roster.source==="fallback"?roster.warning:"");
const users=roster.members;if(!Array.isArray(users)||users.length===0)throw Error("No valid Team France member list is available.");
// Normalize BOTH the original string roster and the v2 structured roster.
// Reject malformed responses before any candidate requests can be queued.
const joinMap=roster.joinedByUsername&&typeof roster.joinedByUsername==="object"?roster.joinedByUsername:{};
const normalizedUsers=users.map(item=>{
 const username=typeof item==="string"?item:(item&&typeof item.username==="string"?item.username:null);
 if(!username||!/^[a-z0-9_-]{1,64}$/i.test(username))throw Error("Invalid username in Team France member list; scan stopped before sending player requests. Reload the application.");
 const joined=typeof item==="object"&&item!==null?item.joined:joinMap[key(username)];
 return{username,joined_tgp_epoch:Number(joined)>0?Number(joined):null};
});
const reg=registered(),pool=normalizedUsers.filter(item=>!reg.has(key(item.username))).map(item=>({...item,username_key:key(item.username)}));
u.funnel.hidden=false;u.funnel.textContent=users.length+" saved/live Team France members → "+pool.length+" not registered for this match";
let ratingChecked=0,eligibleCount=0,qualifiedCount=0,unverifiedCount=0,activityExcluded=0;const started=performance.now(),completion=[];scanCompletedRequests=0;scanSuccessfulRequests=0;rateWindowStart=started;
const progress=(done,total)=>{latestProgress={done,total,qualifiedCount,eligibleCount,unverifiedCount};if(progressFrame!==null)return;progressFrame=setTimeout(()=>{progressFrame=null;const p=latestProgress;if(!p)return;const elapsed=performance.now()-started;const rate=p.done/Math.max(.001,elapsed/1000);const eta=rate>0?Math.ceil((p.total-p.done)/rate):null;const now=performance.now();if(now-rateWindowStart>=1000){lastMeasuredRate=completedRequests/((now-rateWindowStart)/1000);completedRequests=0;rateWindowStart=now}u.details.textContent=p.done+" / "+p.total+" checked · "+p.qualifiedCount+" within rating (checked) · "+activityExcluded+" inactive before stats · "+p.eligibleCount+" eligible · "+p.unverifiedCount+" unverified · " +lastMeasuredRate.toFixed(1)+" requests/s · avg "+(scanSuccessfulRequests/Math.max(.001,elapsed/1000)).toFixed(1)+" successful/s · "+activePosts+" gateway POSTs ("+activeSlowPosts+" stats) · "+queue.length+" queued · shared target "+sharedRate.toFixed(1)+"/s"+(notBefore>Date.now()?" · cooldown "+Math.ceil((notBefore-Date.now())/1000)+"s":"")+" · ETA "+(eta==null?"calculating…":eta+"s");status("Verifying ratings, eligibility and membership…","working",10+(p.total?p.done/p.total*89:89))},120)};
const checked=await mapPool(pool,async row=>{
 const base="https://api.chess.com/pub/player/"+encodeURIComponent(row.username);
 // A strict activity window is cheaply selective; loose windows retain rating-first.
 let profile,profileError=null;
 if(s.onlineDays<=7){
  try{profile=await chess(base,ctl.signal)}catch(error){if(ctl.signal.aborted)throw error;profileError=error}
  if(inactiveProfile(profile,s)){activityExcluded++;return{...row,kind:"excluded",reason:"Last online is outside the configured window."}}
 }
 const stats=await chess(base+"/stats",ctl.signal);ratingChecked++;
 const value=rating(stats,s.ratingCategory);
 if(value===null)return{...row,kind:"excluded",reason:"No rating in the requested Daily category."};
 if(value<s.min||value>s.max)return{...row,kind:"excluded",reason:"Outside match rating limits."};
 qualifiedCount++;
 const candidate={...row,rating:value,max_rating:maxRating(stats,s.ratingCategory)};
 if(profileError)throw profileError;
 if(!profile)profile=await chess(base,ctl.signal);
 const v=C.verify({...candidate,name:typeof profile?.name==="string"?profile.name.trim():""},liveDetails(stats,profile),s);
 // A definitive hard-filter exclusion needs no additional membership request.
 // Every eligible (and otherwise unverified) candidate still goes through live membership.
 if(v.decision==="excluded")return{...v,kind:"checked"};
 const clubList=await chess(base+"/clubs",ctl.signal);
 const memberClubs=clubs(clubList);
 if(memberClubs===null)throw Error("Club membership data is incomplete.");
 if(!memberClubs.has(CLUB))return{...candidate,kind:"excluded",reason:"No longer a current Team France member."};
 if(memberClubs.has(clubSlug(state.opp)))return{...candidate,kind:"excluded",reason:"Currently belongs to the opponent club."};
 if(v.decision==="eligible")eligibleCount++;
 return{...v,kind:"checked"};
},ctl.signal,(done,total,item)=>{if(item?.kind==="unverified")unverifiedCount++;progress(done,total)});
if(progressFrame!==null){clearTimeout(progressFrame);progressFrame=null}latestProgress={done:pool.length,total:pool.length,qualifiedCount,eligibleCount,unverifiedCount};
u.funnel.textContent=users.length+" Team France members → "+pool.length+" unregistered → "+qualifiedCount+" within rating (of "+ratingChecked+" statistics checks) → "+eligibleCount+" eligible";
const unverified=checked.filter(x=>x?.kind==="unverified").map(x=>({username:x.username||"",decision:"unverified",reason:x.reason}));
const rows=[...checked.filter(x=>x?.kind==="checked"),...unverified];
const matchInfo={id:state.id,name:state.match.name||"Match "+state.id, ratingCategory:s.ratingCategory,min:s.min,max:s.max};
display({rows,rosterCount:users.length,ratingChecked,match:matchInfo});logEvent("info","scan",eligibleCount+" eligible · "+unverified.length+" unverified · "+activityExcluded+" inactive before stats · "+ratingChecked+" statistics checks · "+scanSuccessfulRequests+"/"+scanCompletedRequests+" successful candidate requests · "+((performance.now()-started)/1000).toFixed(1)+"s · "+(scanSuccessfulRequests/Math.max(.001,(performance.now()-started)/1000)).toFixed(1)+" successful/s");
if(pool.length>0&&unverified.length===pool.length&&ratingChecked===0){
 logEvent("error","scan","All player-statistics requests failed; results are invalid.");
 status("Recruitment failed: every player-statistics request failed. Check the event log; the result is not a valid eligibility analysis.","error",null);
}else status("Recruitment analysis completed. "+eligibleCount+" eligible; "+unverified.length+" unverified.","success",100);
}catch(e){logEvent("error","scan",e.message||String(e));status(ctl.signal.aborted?"Recruitment cancelled.":e.message||String(e),ctl.signal.aborted?"working":"error")}finally{if(progressFrame!==null){clearTimeout(progressFrame);progressFrame=null}state.controller=null;busy(false)}}
document.getElementById("tgpLogCopy")?.addEventListener("click",()=>navigator.clipboard?.writeText(events.map(e=>JSON.stringify(e)).join("\n")).catch(()=>{}));
document.getElementById("tgpLogClear")?.addEventListener("click",()=>{events.length=0;requestCount=0;errorCount=0;logEvent("info","log","Log cleared")});
logEvent("info","session","Authenticated Team France recruitment · bearer requests enabled · increment 5A");void loadClubAvatar();
u.form.addEventListener("submit",e=>{e.preventDefault();void load()});
u.recruit.addEventListener("submit",e=>{e.preventDefault();void scan()});
u.cancel.addEventListener("click",()=>state.controller?.abort());
const initial=new URLSearchParams(location.search).get("match");if(initial){u.ref.value=initial;void load()}
})();
