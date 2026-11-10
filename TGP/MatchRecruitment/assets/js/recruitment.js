(()=>{"use strict";
const CLUB="the-golden-phoenix",C=window.P2K_RECRUITMENT_V2,$=id=>document.getElementById(id);
const esc=x=>String(x??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;");
const u={form:$("p2kMatchForm"),ref:$("p2kMatchReference"),load:$("p2kLoadButton"),settings:$("p2kSettingsPanel"),summary:$("p2kMatchSummary"),recruit:$("p2kRecruitmentForm"),scan:$("p2kScanButton"),online:$("p2kOnlineDays"),timeout:$("p2kTimeoutRate"),status:$("p2kStatus"),statusText:$("p2kStatusText"),actions:$("p2kProcessingActions"),cancel:$("p2kCancelButton"),track:$("p2kProgressTrack"),bar:$("p2kProgressBar"),details:$("p2kProgressDetails"),funnel:$("p2kFunnel"),notice:$("tgpRosterNotice"),results:$("p2kResults")};
const state={match:null,id:null,ours:null,opp:null,controller:null,result:null,sort:"rating",direction:-1};
if(!C){u.results.textContent="Recruitment core failed to load.";return;}
function status(msg,type="working",pct=null){u.status.style.display="block";u.status.classList.toggle("p2k-error",type==="error");u.status.classList.toggle("p2k-success",type==="success");u.statusText.textContent=msg;u.track.style.display=pct===null?"none":"block";if(pct!==null)u.bar.style.width=Math.max(0,Math.min(100,pct))+"%"}
function busy(on){u.load.disabled=on;u.scan.disabled=on;u.online.disabled=on;u.timeout.disabled=on;u.actions.style.display=on?"flex":"none"}
const key=x=>String(x||"").trim().toLowerCase();
function clubSlug(t){for(const field of ["@id","url"]){try{const url=new URL(String(t?.[field]||""));if(!["api.chess.com","www.chess.com","chess.com"].includes(url.hostname.toLowerCase()))continue;const m=url.pathname.match(/\/(?:pub\/)?club\/([^/]+)\/?$/i);if(m)return decodeURIComponent(m[1]).toLowerCase()}catch{}}return""}
function clubs(data){if(!Array.isArray(data?.clubs))return null;const result=new Set();for(const item of data.clubs){const slug=clubSlug(typeof item==="string"?{"@id":item}:item);if(slug)result.add(slug)}return result}
function sides(match){const teams=match?.teams;if(!teams||typeof teams!=="object")throw Error("The match has no team data.");const entries=Object.entries(teams).filter(([,v])=>v&&typeof v==="object");if(entries.length!==2)throw Error("The match does not have exactly two teams.");const ours=entries.find(([,v])=>clubSlug(v)===CLUB),opp=entries.find(x=>x!==ours);if(!ours||!opp||clubSlug(opp[1])==="")throw Error("Only matches involving The Golden Phoenix are supported.");return{ours:ours[1],opp:opp[1]}}
const csrf=document.querySelector('meta[name="tgp-recruitment-csrf"]')?.content||"";
const queue=[];let scheduled=false;const events=[];let errorCount=0,requestCount=0;
function logEvent(level,phase,message,meta={}){const event={utc:new Date().toISOString(),level,phase,message,...meta};events.push(event);if(events.length>500)events.shift();if(level==="error")errorCount++;if(level==="request")requestCount++;const head=document.getElementById("tgpLogSummary"),body=document.getElementById("tgpLogContent");if(head)head.textContent="Request / event log · "+requestCount+" requests · "+errorCount+" errors · "+events.length+" events";if(body)body.textContent=events.map(e=>"["+e.utc+"] "+e.level.toUpperCase()+" "+e.phase+" "+e.message+(e.status!==undefined?" (HTTP "+e.status+")":"")+(e.ms!==undefined?" "+e.ms+"ms":"")).join("\n")}
function pause(ms,signal){return new Promise((resolve,reject)=>{if(signal?.aborted)return reject(new DOMException("Cancelled","AbortError"));const t=setTimeout(()=>{signal?.removeEventListener("abort",abort);resolve()},ms);function abort(){clearTimeout(t);reject(new DOMException("Cancelled","AbortError"))}signal?.addEventListener("abort",abort,{once:true})})}
function sendQueued(url,signal){if(signal?.aborted)return Promise.reject(new DOMException("Cancelled","AbortError"));return new Promise((resolve,reject)=>{const request={url,signal,resolve,reject};const abort=()=>{const i=queue.indexOf(request);if(i!==-1){queue.splice(i,1);reject(new DOMException("Cancelled","AbortError"))}};request.dispose=()=>signal?.removeEventListener("abort",abort);signal?.addEventListener("abort",abort,{once:true});queue.push(request);if(!scheduled){scheduled=true;setTimeout(flush,0)}})}
async function flush(){scheduled=false;const chunk=queue.splice(0,12);if(!chunk.length)return;if(queue.length){scheduled=true;setTimeout(flush,0)}const started=performance.now();
chunk.forEach(x=>logEvent("request","Chess.com",new URL(x.url).pathname));
try{const r=await fetch("api/?action=batch",{method:"POST",credentials:"same-origin",cache:"no-store",headers:{"Content-Type":"application/json","Accept":"application/json","X-P2K-OAuth-CSRF":csrf},body:JSON.stringify({requests:chunk.map(x=>({url:x.url}))})});const p=await r.json();if(!r.ok||p?.ok!==true||p?.mode!=="oauth-bearer"){const error=Error(p?.error?.message||"Bearer gateway HTTP "+r.status);error.status=r.status;throw error}
const entries=new Map((p.results||[]).map(x=>[String(x.id),x]));chunk.forEach((x,i)=>{const response=entries.get(String(i));if(!response){const err=Error("Bearer response missing for "+new URL(x.url).pathname);logEvent("error","gateway",err.message);x.reject(err);return}const code=Number(response.status)||0;const info={status:code,ms:Number(response.elapsed_ms)||0};logEvent(code>=200&&code<300?"success":"error","Chess.com",new URL(x.url).pathname,info);if(code>=200&&code<300&&response.json&&typeof response.json==="object")x.resolve(response.json);else{const error=Error("Chess.com returned HTTP "+code+" for "+new URL(x.url).pathname);error.status=code;const after=Number(response.retry_after);error.retryAfter=Number.isFinite(after)&&after>=0?after:0;x.reject(error)}})
}catch(e){logEvent("error","gateway",String(e.message||e),{ms:Math.round(performance.now()-started)});chunk.forEach(x=>x.reject(e))}
finally{chunk.forEach(x=>x.dispose?.())}}
async function chess(url,signal){for(let attempt=1;attempt<=3;attempt++){try{return await sendQueued(url,signal)}catch(e){if(signal?.aborted)throw e;const transient=[0,429,500,502,503,504].includes(Number(e.status)||0);if(!transient||attempt===3)throw e;const delay=Math.min(12000,Math.max((Number(e.retryAfter)||0)*1000,500*Math.pow(2,attempt-1)+Math.round(Math.random()*300)));logEvent("retry","Chess.com","retry "+attempt+" for "+new URL(url).pathname+" after "+delay+"ms");await pause(delay,signal)}}throw Error("Retries exhausted")}

function criteria(){const settings=state.match?.settings||{},category=C.ratingCategory(settings.rules),max=Number(settings.max_rating),min=Number(settings.min_rating);const s={ratingCategory:category,apiRules:category==="daily_chess960"?"chess960":"chess",min:Number.isFinite(min)&&min>0?min:0,max:Number.isFinite(max)&&max>0?max:Infinity,onlineDays:Number(u.online.value),maxTimeout:Number(u.timeout.value)};if(!Number.isFinite(s.onlineDays)||s.onlineDays<0.04||s.onlineDays>365)throw Error("Online within must be between 0.04 and 365 days.");if(!Number.isFinite(s.maxTimeout)||s.maxTimeout<0||s.maxTimeout>100)throw Error("Maximum timeout rate must be between 0 and 100%.");return s}
function registered(){return new Set([...(state.ours?.players||[]),...(state.opp?.players||[])].map(x=>key(x.username)).filter(Boolean))}
function rating(stats,cat){const entry=cat==="daily_chess960"?(stats?.chess960_daily||stats?.chess_daily960):(stats?.chess_daily);const value=Number(entry?.last?.rating);return Number.isFinite(value)&&value>0?value:null}
function liveDetails(stats,profile){const pct=Number(stats?.chess_daily?.record?.timeout_percent);return{last_online:Number(profile?.last_online)||null,timeout_percent:Number.isFinite(pct)?pct:null,current_match_load:null,current_match_load_error:"Not requested"}}
function note(text){u.notice.hidden=!text;u.notice.textContent=text||""}
async function load(){if(state.controller)return;const ctl=new AbortController();state.controller=ctl;busy(true);note("");status("Resolving match…","working",5);
try{const id=C.parseMatchReference(u.ref.value,location.href);if(!/^\d{1,18}$/.test(id))throw Error("Invalid match ID.");const match=await chess("https://api.chess.com/pub/match/"+encodeURIComponent(id),ctl.signal);const {ours,opp}=sides(match);
state.match=match;state.id=String(match.id||id);state.ours=ours;state.opp=opp;u.ref.value=state.id;u.settings.hidden=false;
const s=criteria();u.summary.textContent=(match.name||"Match "+id)+" · The Golden Phoenix vs "+(opp.name||clubSlug(opp))+" · "+(s.ratingCategory==="daily_chess960"?"Daily Chess960":"Standard Daily");u.results.textContent="Match resolved. Set the eligibility filters and start recruitment.";status("Match resolved.","success");
}catch(e){if(!ctl.signal.aborted){status(e.message||String(e),"error");u.settings.hidden=true;u.results.textContent="Unable to load this TGP match."}}finally{state.controller=null;busy(false)}}
async function mapPool(items,limit,task,signal,onComplete){let cursor=0,done=0;const results=new Array(items.length);await Promise.all(Array.from({length:Math.min(limit,items.length)},async()=>{while(cursor<items.length&&!signal.aborted){const idx=cursor++;try{results[idx]=await task(items[idx],idx)}catch(error){if(signal.aborted)break;results[idx]={kind:"unverified",username:items[idx].username||items[idx],reason:error?.message||String(error)}}finally{done++;onComplete?.(done,items.length,results[idx])}}}));if(signal.aborted)throw new DOMException("Cancelled","AbortError");return results}
function display(result){state.result=result;const eligible=result.rows.filter(x=>x.decision==="eligible"),unverified=result.rows.filter(x=>x.decision==="unverified");
u.results.innerHTML='<div class="p2k-result-cards"><div class="p2k-result-card"><div class="p2k-result-card-value">'+result.rosterCount+'</div><div class="p2k-result-card-label">TGP members</div></div><div class="p2k-result-card"><div class="p2k-result-card-value">'+result.ratingChecked+'</div><div class="p2k-result-card-label">Rating checked</div></div><div class="p2k-result-card p2k-good"><div class="p2k-result-card-value">'+eligible.length+'</div><div class="p2k-result-card-label">Eligible</div></div><div class="p2k-result-card"><div class="p2k-result-card-value">'+unverified.length+'</div><div class="p2k-result-card-label">Unverified</div></div></div>'+
'<div class="p2k-results-tools"><label>Search <input id="tgpSearch" type="search" placeholder="Username"></label><button class="p2k-main-button" id="tgpCsv" type="button">Download eligible CSV</button></div><div class="p2k-partial-note">Only currently verified TGP members who are not opponent-club members are included. '+unverified.length+' candidate(s) could not be verified.</div>'+
'<div class="p2k-recruit-table-wrap"><table class="p2k-recruit-table"><thead><tr><th data-sort="username">Username</th><th data-sort="rating">Rating</th><th>Category</th><th data-sort="last_online_age_hours">Last online</th><th data-sort="timeout">Timeout</th><th>Rating source</th></tr></thead><tbody id="tgpRows"></tbody></table></div>';
const draw=()=>{const q=key($("tgpSearch").value);const rows=eligible.filter(x=>key(x.username).includes(q)).sort((a,b)=>{const val=r=>state.sort==="timeout"?r.live.timeout_percent:r[state.sort];const aa=val(a),bb=val(b);const n=typeof aa==="string"?String(aa).localeCompare(String(bb)):Number(aa)-Number(bb);return (n||a.username.localeCompare(b.username))*state.direction});$("tgpRows").innerHTML=rows.map(r=>'<tr><td><a target="_blank" rel="noopener" href="https://www.chess.com/member/'+encodeURIComponent(r.username)+'">'+esc(r.username)+'</a></td><td>'+r.rating+'</td><td>'+esc(result.match.ratingCategory)+'</td><td>'+new Date(r.live.last_online*1000).toLocaleString()+'</td><td>'+Number(r.live.timeout_percent).toFixed(1)+'%</td><td>Chess.com live</td></tr>').join("")||'<tr><td colspan="6">No eligible players found.</td></tr>'};
$("tgpSearch").addEventListener("input",draw);u.results.querySelectorAll("th[data-sort]").forEach(th=>th.addEventListener("click",()=>{state.direction=state.sort===th.dataset.sort?-state.direction:-1;state.sort=th.dataset.sort;draw()}));
$("tgpCsv").addEventListener("click",()=>{const csv=C.csv(eligible,result.match),url=URL.createObjectURL(new Blob([csv],{type:"text/csv;charset=utf-8"})),a=document.createElement("a");a.href=url;a.download="tgp-match-"+state.id+"-eligible.csv";a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)});draw()}
async function scan(){if(state.controller||!state.match)return;const ctl=new AbortController();state.controller=ctl;busy(true);u.details.hidden=false;u.funnel.hidden=true;
try{
const s=criteria();state.result=null;u.results.textContent="Recruitment scan in progress…";note("");
status("Loading current TGP membership roster…","working",5);
const r=await fetch("api/?action=roster",{signal:ctl.signal,cache:"no-store",credentials:"same-origin",headers:{Accept:"application/json"}});
const roster=await r.json();logEvent(r.ok?"success":"error","TGP roster",r.ok?"Membership "+(roster.source||"unknown")+" ("+Number(roster.count||0)+" members)":"Roster request failed",{status:r.status});if(!r.ok||!roster.ok)throw Error(roster?.error?.message||"Membership list unavailable.");
note(roster.source==="fallback"?roster.warning:"");
const users=roster.members;if(!Array.isArray(users)||users.length===0)throw Error("No valid TGP member list is available.");
const reg=registered(),pool=users.filter(name=>!reg.has(key(name))).map(username=>({username,username_key:key(username)}));
u.funnel.hidden=false;u.funnel.textContent=users.length+" saved/live TGP members → "+pool.length+" not registered for this match";
let ratingChecked=0,eligibleCount=0;const started=performance.now(),completion=[];
const progress=(done,total,phase,from,to)=>{completion.push(performance.now()-started);const eta=C.eta(completion,total-done,performance.now()-started);u.details.textContent=done+" / "+total+" "+phase+" · "+eligibleCount+" eligible · ETA "+(eta==null?"calculating…":eta+"s");status("Verifying live "+phase+"…","working",from+(total?done/total*(to-from):to-from))};
const ratingResults=await mapPool(pool,4,async row=>{
const stats=await chess("https://api.chess.com/pub/player/"+encodeURIComponent(row.username)+"/stats",ctl.signal);
const value=rating(stats,s.ratingCategory);ratingChecked++;
if(value===null)return{kind:"excluded",reason:"No rating in the requested Daily category."};
if(value<s.min||value>s.max)return{kind:"excluded",reason:"Outside match rating limits."};
return{kind:"rated",...row,rating:value,rating_updated_at:new Date().toISOString(),rating_source:"chesscom_live",stats};
},ctl.signal,(done,total)=>progress(done,total,"ratings",10,40));
const candidates=ratingResults.filter(x=>x?.kind==="rated");
u.funnel.textContent=users.length+" TGP members → "+pool.length+" unregistered → "+candidates.length+" in rating range";
const checked=await mapPool(candidates,4,async row=>{
const base="https://api.chess.com/pub/player/"+encodeURIComponent(row.username);
const [clubList,profile]=await Promise.all([chess(base+"/clubs",ctl.signal),chess(base,ctl.signal)]);
const memberClubs=clubs(clubList);
if(memberClubs===null)throw Error("Club membership data is incomplete.");
if(!memberClubs.has(CLUB))return{...row,kind:"excluded",reason:"No longer a current TGP member."};
if(memberClubs.has(clubSlug(state.opp)))return{...row,kind:"excluded",reason:"Currently belongs to the opponent club."};
const v=C.verify(row,liveDetails(row.stats,profile),s);
if(v.decision==="eligible")eligibleCount++;
return{...v,kind:"checked"};
},ctl.signal,(done,total)=>progress(done,total,"eligibility and club membership",40,99));
const unverified=[...ratingResults,...checked].filter(x=>x?.kind==="unverified").map(x=>({username:x.username||"",decision:"unverified",reason:x.reason}));
const rows=[...checked.filter(x=>x?.kind==="checked"),...unverified];
const matchInfo={id:state.id,name:state.match.name||"Match "+state.id, ratingCategory:s.ratingCategory,min:s.min,max:s.max};
display({rows,rosterCount:users.length,ratingChecked,match:matchInfo});logEvent("info","scan",eligibleCount+" eligible · "+unverified.length+" unverified");
status("Recruitment analysis completed. "+eligibleCount+" eligible; "+unverified.length+" unverified.","success",100);
}catch(e){logEvent("error","scan",e.message||String(e));status(ctl.signal.aborted?"Recruitment cancelled.":e.message||String(e),ctl.signal.aborted?"working":"error")}finally{state.controller=null;busy(false)}}
document.getElementById("tgpLogCopy")?.addEventListener("click",()=>navigator.clipboard?.writeText(events.map(e=>JSON.stringify(e)).join("\n")).catch(()=>{}));
document.getElementById("tgpLogClear")?.addEventListener("click",()=>{events.length=0;requestCount=0;errorCount=0;logEvent("info","log","Log cleared")});
logEvent("info","session","Authenticated TGP recruitment · bearer requests enabled");
u.form.addEventListener("submit",e=>{e.preventDefault();void load()});
u.recruit.addEventListener("submit",e=>{e.preventDefault();void scan()});
u.cancel.addEventListener("click",()=>state.controller?.abort());
const initial=new URLSearchParams(location.search).get("match");if(initial){u.ref.value=initial;void load()}
})();
