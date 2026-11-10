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
function sides(match){const teams=match?.teams;if(!teams||typeof teams!=="object")throw Error("Le match ne contient aucune donnée d’équipe.");const entries=Object.entries(teams).filter(([,v])=>v&&typeof v==="object");if(entries.length!==2)throw Error("Le match ne comporte pas exactement deux équipes.");const ours=entries.find(([,v])=>clubSlug(v)===CLUB),opp=entries.find(x=>x!==ours);if(!ours||!opp||clubSlug(opp[1])==="")throw Error("Seuls les matchs impliquant Team France sont acceptés.");return{ours:ours[1],opp:opp[1]}}
const csrf=document.querySelector('meta[name="team-france-recruitment-csrf"]')?.content||"";
const queue=[];const events=[];let errorCount=0,requestCount=0;
const responseCache=new Map();const CACHE_TTL_MS=90000;
const BATCH_SIZE=12,MAX_GATEWAY_POSTS=2, BATCH_WINDOW_MS=28;
let activePosts=0,pumpTimer=null,logTimer=null;
let progressFrame=null,latestProgress=null,completedRequests=0,lastMeasuredRate=0,rateWindowStart=performance.now();
let adaptiveWorkers=18,successStreak=0,rateLimitCount=0;
function renderLog(){logTimer=null;const head=document.getElementById("tgpLogSummary"),body=document.getElementById("tgpLogContent");if(head)head.textContent="Journal des requêtes et événements · "+requestCount+" requests · "+errorCount+" errors · "+events.length+" recent events";if(body&&document.getElementById("tgpRequestLog")?.open)body.textContent=events.map(e=>"["+e.utc+"] "+e.level.toUpperCase()+" "+e.phase+" "+e.message+(e.status!==undefined?" (HTTP "+e.status+")":"")+(e.ms!==undefined?" "+e.ms+"ms":"")).join("\n")}
function scheduleLog(){if(logTimer===null)logTimer=setTimeout(renderLog,250)}
function logEvent(level,phase,message,meta={}){events.push({utc:new Date().toISOString(),level,phase,message,...meta});if(events.length>500)events.shift();if(level==="error")errorCount++;if(level==="request")requestCount++;scheduleLog()}
document.getElementById("tgpRequestLog")?.addEventListener("toggle",()=>{if(document.getElementById("tgpRequestLog")?.open)renderLog()});
function pause(ms,signal){return new Promise((resolve,reject)=>{if(signal?.aborted)return reject(new DOMException("Cancelled","AbortError"));let done=false;const abort=()=>{if(done)return;done=true;clearTimeout(t);reject(new DOMException("Cancelled","AbortError"))};const t=setTimeout(()=>{done=true;signal?.removeEventListener("abort",abort);resolve()},ms);signal?.addEventListener("abort",abort,{once:true})})}
function schedulePump(){if(pumpTimer!==null)return;const delay=queue.length>=BATCH_SIZE?0:BATCH_WINDOW_MS;pumpTimer=setTimeout(()=>{pumpTimer=null;void pump()},delay)}
function sendQueued(url,signal){if(signal?.aborted)return Promise.reject(new DOMException("Cancelled","AbortError"));return new Promise((resolve,reject)=>{const req={url,signal,resolve,reject};const abort=()=>{const i=queue.indexOf(req);if(i>=0){queue.splice(i,1);reject(new DOMException("Cancelled","AbortError"))}};req.dispose=()=>signal?.removeEventListener("abort",abort);signal?.addEventListener("abort",abort,{once:true});queue.push(req);schedulePump()})}
async function pump(){while(activePosts<MAX_GATEWAY_POSTS&&queue.length){const chunk=queue.splice(0,BATCH_SIZE);activePosts++;void executeBatch(chunk).finally(()=>{activePosts--;schedulePump()})}}
async function executeBatch(chunk){const started=performance.now();chunk.forEach(x=>logEvent("request","Chess.com",new URL(x.url).pathname));try{
const r=await fetch("api/?action=batch",{method:"POST",credentials:"same-origin",cache:"no-store",headers:{"Content-Type":"application/json","Accept":"application/json","X-P2K-OAuth-CSRF":csrf},body:JSON.stringify({requests:chunk.map(x=>({url:x.url}))})});
const p=await r.json();if(!r.ok||p?.ok!==true||p?.mode!=="oauth-bearer"){const error=Error(p?.error?.message||"Bearer gateway HTTP "+r.status);error.status=r.status;throw error}
const entries=new Map((p.results||[]).map(x=>[String(x.id),x]));for(let i=0;i<chunk.length;i++){
const x=chunk[i],response=entries.get(String(i));if(!response){const err=Error("Bearer response missing for "+new URL(x.url).pathname);err.status=502;logEvent("error","gateway",err.message);x.reject(err);continue}
const code=Number(response.status)||0,info={status:code,ms:Number(response.elapsed_ms)||0};completedRequests++;logEvent(code>=200&&code<300?"success":"error","Chess.com",new URL(x.url).pathname,info);
if(code===429){rateLimitCount++;successStreak=0;adaptiveWorkers=Math.max(6,Math.floor(adaptiveWorkers*.7));logEvent("info","scheduler","HTTP 429: reduced worker target to "+adaptiveWorkers)}
else if(code>=200&&code<300){successStreak++;if(successStreak>=80&&adaptiveWorkers<24){adaptiveWorkers+=2;successStreak=0;logEvent("info","scheduler","increased worker target to "+adaptiveWorkers)}}
if(code>=200&&code<300&&response.json&&typeof response.json==="object")x.resolve(response.json);
else{const error=Error("Chess.com returned HTTP "+code+" for "+new URL(x.url).pathname);error.status=code;const after=Number(response.retry_after);error.retryAfter=Number.isFinite(after)&&after>=0?after:0;x.reject(error)}
}}
catch(e){logEvent("error","gateway",String(e.message||e),{status:e.status||0,ms:Math.round(performance.now()-started)});chunk.forEach(x=>x.reject(e))}
finally{chunk.forEach(x=>x.dispose?.())}}
async function chess(url,signal){const reusable=!url.endsWith("/clubs")&&!url.includes("/pub/match/")&&!url.includes("/pub/club/");const cached=responseCache.get(url);if(reusable&&cached&&cached.until>Date.now()){logEvent("info","cache","reused "+new URL(url).pathname);return cached.body}
for(let attempt=1;attempt<=3;attempt++){try{const body=await sendQueued(url,signal);if(reusable){responseCache.set(url,{body,until:Date.now()+CACHE_TTL_MS});if(responseCache.size>5000){for(const [k,v] of responseCache){if(v.until<Date.now())responseCache.delete(k);if(responseCache.size<=4000)break}}}return body}catch(e){if(signal?.aborted)throw e;const status=Number(e.status)||0;const transient=[0,429,500,502,503,504].includes(status);if(!transient||attempt===3)throw e;const delay=Math.min(12000,Math.max((Number(e.retryAfter)||0)*1000,500*Math.pow(2,attempt-1)+Math.round(Math.random()*300)));logEvent("retry","Chess.com","attempt "+(attempt+1)+" for "+new URL(url).pathname+" after "+delay+"ms");await pause(delay,signal)}}throw Error("Retries exhausted")}

function criteria(){const settings=state.match?.settings||{},category=C.ratingCategory(settings.rules),max=Number(settings.max_rating),min=Number(settings.min_rating);const s={ratingCategory:category,apiRules:category==="daily_chess960"?"chess960":"chess",min:Number.isFinite(min)&&min>0?min:0,max:Number.isFinite(max)&&max>0?max:Infinity,onlineDays:Number(u.online.value),maxTimeout:Number(u.timeout.value)};if(!Number.isFinite(s.onlineDays)||s.onlineDays<0.04||s.onlineDays>365)throw Error("Le délai de dernière connexion doit être compris entre 0,04 et 365 jours.");if(!Number.isFinite(s.maxTimeout)||s.maxTimeout<0||s.maxTimeout>100)throw Error("Le taux maximal d’abandon doit être compris entre 0 et 100 %.");return s}
function registered(){return new Set([...(state.ours?.players||[]),...(state.opp?.players||[])].map(x=>key(x.username)).filter(Boolean))}
function maxRating(stats,cat){const entry=cat==="daily_chess960"?(stats?.chess960_daily||stats?.chess_daily960):stats?.chess_daily;const best=Number(entry?.best?.rating);return Number.isFinite(best)&&best>0?best:null}
function rating(stats,cat){const entry=cat==="daily_chess960"?(stats?.chess960_daily||stats?.chess_daily960):(stats?.chess_daily);const value=Number(entry?.last?.rating);return Number.isFinite(value)&&value>0?value:null}
function liveDetails(stats,profile){const pct=Number(stats?.chess_daily?.record?.timeout_percent);return{last_online:Number(profile?.last_online)||null,timeout_percent:Number.isFinite(pct)?pct:null,current_match_load:null,current_match_load_error:"Not requested"}}
function note(text){u.notice.hidden=!text;u.notice.textContent=text||""}
async function loadClubAvatar(){const avatar=document.getElementById("p2kSelectedTeamLogo");if(!avatar)return;try{const data=await chess("https://api.chess.com/pub/club/"+CLUB);const url=String(data?.icon||data?.image||"");const parsed=new URL(url);if(parsed.protocol!=="https:"||!(["images.chesscomfiles.com","www.chess.com","chess.com"].includes(parsed.hostname)))throw Error("Club avatar URL unavailable");avatar.src=url;avatar.hidden=false;avatar.onerror=()=>{avatar.hidden=true;logEvent("error","avatar","Impossible de charger le logo du club")}}catch(error){logEvent("error","avatar",String(error.message||error))}}

async function load(){if(state.controller)return;const ctl=new AbortController();state.controller=ctl;busy(true);note("");status("Recherche du match…","working",5);
try{const id=C.parseMatchReference(u.ref.value,location.href);if(!/^\d{1,18}$/.test(id))throw Error("Invalid match ID.");const match=await chess("https://api.chess.com/pub/match/"+encodeURIComponent(id),ctl.signal);const {ours,opp}=sides(match);
state.match=match;state.id=String(match.id||id);state.ours=ours;state.opp=opp;u.ref.value=state.id;u.settings.hidden=false;
const s=criteria();const title=match.name||"Match n°"+id;const matchLink=document.createElement("a");matchLink.textContent=title;matchLink.href="https://www.chess.com/club/matches/team-france/"+encodeURIComponent(state.id);matchLink.target="_blank";matchLink.rel="noopener noreferrer";u.summary.replaceChildren(matchLink,document.createTextNode(" · Team France vs "+(opp.name||clubSlug(opp))+" · "+(s.ratingCategory==="daily_chess960"?"Échecs 960 en différé":"Échecs en différé standard")));u.results.textContent="Match trouvé. Définissez les filtres et lancez le recrutement.";status("Match chargé.","success");
}catch(e){if(!ctl.signal.aborted){status(e.message||String(e),"error");u.settings.hidden=true;u.results.textContent="Impossible de charger ce match Team France."}}finally{state.controller=null;busy(false)}}
async function mapPool(items,task,signal,onComplete){let cursor=0,done=0,active=0;const results=new Array(items.length);const workers=Array.from({length:24},async(_,worker)=>{while(cursor<items.length&&!signal.aborted){if(worker>=adaptiveWorkers){await pause(100,signal);continue}const idx=cursor++;active++;try{results[idx]=await task(items[idx],idx)}catch(e){if(signal.aborted)throw e;results[idx]={kind:"unverified",username:items[idx]?.username||items[idx],reason:e?.message||String(e)};logEvent("error","candidate",results[idx].username+": "+results[idx].reason)}finally{active--;done++;onComplete?.(done,items.length,results[idx])}}});await Promise.all(workers);if(signal.aborted)throw new DOMException("Cancelled","AbortError");return results}
function display(result){state.result=result;const eligible=result.rows.filter(x=>x.decision==="eligible"),unverified=result.rows.filter(x=>x.decision==="unverified");
u.results.innerHTML='<div class="p2k-result-cards"><div class="p2k-result-card"><div class="p2k-result-card-value">'+result.rosterCount+'</div><div class="p2k-result-card-label">Membres Team France</div></div><div class="p2k-result-card"><div class="p2k-result-card-value">'+result.ratingChecked+'</div><div class="p2k-result-card-label">Classements vérifiés</div></div><div class="p2k-result-card p2k-good"><div class="p2k-result-card-value">'+eligible.length+'</div><div class="p2k-result-card-label">Éligibles</div></div><div class="p2k-result-card"><div class="p2k-result-card-value">'+unverified.length+'</div><div class="p2k-result-card-label">Non vérifiés</div></div></div>'+
'<div class="p2k-results-tools"><label>Rechercher <input id="tgpSearch" type="search" placeholder="Pseudo ou nom"></label><button class="p2k-main-button" id="tgpCsv" type="button">Télécharger le CSV des joueurs éligibles</button></div><div class="p2k-partial-note">Seuls les membres vérifiés de Team France n’appartenant pas au club adverse sont retenus. '+unverified.length+' candidat(s) non vérifiables.</div>'+
'<div class="p2k-recruit-table-wrap"><table class="p2k-recruit-table"><thead><tr><th data-sort="username">Pseudo</th><th data-sort="name">Nom</th><th data-sort="rating">Classement</th><th data-sort="max_rating">Classement maximal</th><th data-sort="timeout">Taux d’abandon</th><th data-sort="last_online_age_hours">Dernière connexion</th><th data-sort="joined_tgp_epoch">Membre depuis (UTC)</th></tr></thead><tbody id="tgpRows"></tbody></table></div><nav class="tgp-pagination" aria-label="Eligible players pagination"><button class="p2k-cancel-button" id="tgpPrevPage" type="button">Précédent</button><span id="tgpPageInfo" aria-live="polite"></span><button class="p2k-cancel-button" id="tgpNextPage" type="button">Suivant</button></nav>';
const utc=epoch=>Number(epoch)>0?new Date(Number(epoch)*1000).toISOString().replace("T"," ").replace(".000Z"," UTC").replace("Z"," UTC"):"";
let page=1;const PER_PAGE=50;const draw=()=>{const q=key($("tgpSearch").value);const rows=eligible.filter(x=>key(x.username).includes(q)||key(x.name).includes(q)).sort((a,b)=>{const val=r=>state.sort==="timeout"?r.live.timeout_percent:r[state.sort];const aa=val(a),bb=val(b);let n;if(aa==null||bb==null)n=aa==null?(bb==null?0:1):-1;else n=typeof aa==="string"?String(aa).localeCompare(String(bb)):Number(aa)-Number(bb);return(n||a.username.localeCompare(b.username))*state.direction});const pages=Math.max(1,Math.ceil(rows.length/PER_PAGE));page=Math.min(page,pages);$("tgpPageInfo").textContent="Page "+page+" sur "+pages+" · "+rows.length+" joueurs (50 par page)";$("tgpPrevPage").disabled=page<=1;$("tgpNextPage").disabled=page>=pages;$("tgpRows").innerHTML=rows.slice((page-1)*PER_PAGE,page*PER_PAGE).map(r=>'<tr><td><a target="_blank" rel="noopener" href="https://www.chess.com/member/'+encodeURIComponent(r.username)+'">'+esc(r.username)+'</a></td><td>'+esc(r.name||"")+'</td><td>'+r.rating+'</td><td>'+(r.max_rating??"")+'</td><td>'+Number(r.live.timeout_percent).toFixed(1)+'%</td><td>'+utc(r.live.last_online)+'</td><td>'+utc(r.joined_tgp_epoch)+'</td></tr>').join("")||'<tr><td colspan="7">Aucun joueur éligible.</td></tr>'};
$("tgpSearch").addEventListener("input",()=>{page=1;draw()});$("tgpPrevPage").onclick=()=>{if(page>1){page--;draw()}};$("tgpNextPage").onclick=()=>{page++;draw()};u.results.querySelectorAll("th[data-sort]").forEach(th=>th.addEventListener("click",()=>{state.direction=state.sort===th.dataset.sort?-state.direction:-1;state.sort=th.dataset.sort;page=1;draw()}));
$("tgpCsv").addEventListener("click",()=>{const csv=C.csv(eligible,result.match),url=URL.createObjectURL(new Blob([csv],{type:"text/csv;charset=utf-8"})),a=document.createElement("a");a.href=url;a.download="team-france-match-"+state.id+"-eligible.csv";a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)});draw()}

async function scan(){if(state.controller||!state.match)return;const ctl=new AbortController();state.controller=ctl;busy(true);u.details.hidden=false;u.funnel.hidden=true;
try{
const s=criteria();state.result=null;u.results.textContent="Analyse du recrutement en cours…";note("");
status("Chargement de la liste actuelle des membres Team France…","working",5);
const r=await fetch("api/?action=roster",{signal:ctl.signal,cache:"no-store",credentials:"same-origin",headers:{Accept:"application/json"}});
const roster=await r.json();logEvent(r.ok?"success":"error","Membres Team France",r.ok?"Liste de membres "+(roster.source||"unknown")+" ("+Number(roster.count||0)+" membres)":"Échec de récupération de la liste des membres",{status:r.status});if(!r.ok||!roster.ok)throw Error(roster?.error?.message||"Liste des membres indisponible.");
note(roster.source==="fallback"?roster.warning:"");
const users=roster.members;if(!Array.isArray(users)||users.length===0)throw Error("Aucune liste valide des membres Team France.");
// Normalize BOTH the original string roster and the v2 structured roster.
// Reject malformed responses before any candidate requests can be queued.
const joinMap=roster.joinedByUsername&&typeof roster.joinedByUsername==="object"?roster.joinedByUsername:{};
const normalizedUsers=users.map(item=>{
 const username=typeof item==="string"?item:(item&&typeof item.username==="string"?item.username:null);
 if(!username||!/^[a-z0-9_-]{1,64}$/i.test(username))throw Error("Pseudo invalide dans la liste Team France : analyse arrêtée. Rechargez la page.");
 const joined=typeof item==="object"&&item!==null?item.joined:joinMap[key(username)];
 return{username,joined_tgp_epoch:Number(joined)>0?Number(joined):null};
});
const reg=registered(),pool=normalizedUsers.filter(item=>!reg.has(key(item.username))).map(item=>({...item,username_key:key(item.username)}));
u.funnel.hidden=false;u.funnel.textContent=users.length+" membres Team France enregistrés/actuels → "+pool.length+" non inscrits à ce match";
let ratingChecked=0,eligibleCount=0,qualifiedCount=0,unverifiedCount=0;const started=performance.now(),completion=[];
const progress=(done,total)=>{latestProgress={done,total,qualifiedCount,eligibleCount,unverifiedCount};if(progressFrame!==null)return;progressFrame=setTimeout(()=>{progressFrame=null;const p=latestProgress;if(!p)return;const elapsed=performance.now()-started;const rate=p.done/Math.max(.001,elapsed/1000);const eta=rate>0?Math.ceil((p.total-p.done)/rate):null;const now=performance.now();if(now-rateWindowStart>=1000){lastMeasuredRate=completedRequests/((now-rateWindowStart)/1000);completedRequests=0;rateWindowStart=now}u.details.textContent=p.done+" / "+p.total+" checked · "+p.qualifiedCount+" within classement · "+p.eligibleCount+" éligibles · "+p.unverifiedCount+" non vérifiés · " +lastMeasuredRate.toFixed(1)+" requests/s · "+activePosts+" gateway POSTs · "+queue.length+" en attente · durée estimée "+(eta==null?"calculating…":eta+"s");status("Vérification des classements, de l’éligibilité et de l’appartenance…","working",10+(p.total?p.done/p.total*89:89))},120)};
const checked=await mapPool(pool,async row=>{
 const base="https://api.chess.com/pub/player/"+encodeURIComponent(row.username);
 const stats=await chess(base+"/stats",ctl.signal);ratingChecked++;
 const value=rating(stats,s.ratingCategory);
 if(value===null)return{...row,kind:"excluded",reason:"Aucun classement dans la catégorie de jeu en différé."};
 if(value<s.min||value>s.max)return{...row,kind:"excluded",reason:"Hors des limites de classement du match."};
 qualifiedCount++;
 const candidate={...row,rating:value,max_rating:maxRating(stats,s.ratingCategory)};
 const [clubList,profile]=await Promise.all([chess(base+"/clubs",ctl.signal),chess(base,ctl.signal)]);
 const memberClubs=clubs(clubList);
 if(memberClubs===null)throw Error("Les données d’appartenance aux clubs sont incomplètes.");
 if(!memberClubs.has(CLUB))return{...candidate,kind:"excluded",reason:"Ne fait plus partie de Team France."};
 if(memberClubs.has(clubSlug(state.opp)))return{...candidate,kind:"excluded",reason:"Appartient actuellement au club adverse."};
 const v=C.verify({...candidate,name:typeof profile?.name==="string"?profile.name.trim():""},liveDetails(stats,profile),s);
 if(v.decision==="eligible")eligibleCount++;
 return{...v,kind:"checked"};
},ctl.signal,(done,total,item)=>{if(item?.kind==="unverified")unverifiedCount++;progress(done,total)});
if(progressFrame!==null){clearTimeout(progressFrame);progressFrame=null}latestProgress={done:pool.length,total:pool.length,qualifiedCount,eligibleCount,unverifiedCount};
u.funnel.textContent=users.length+" Membres Team France → "+pool.length+" unregistered → "+qualifiedCount+" within rating → "+eligibleCount+" eligible";
const unverified=checked.filter(x=>x?.kind==="unverified").map(x=>({username:x.username||"",decision:"unverified",reason:x.reason}));
const rows=[...checked.filter(x=>x?.kind==="checked"),...unverified];
const matchInfo={id:state.id,name:state.match.name||"Match "+state.id, ratingCategory:s.ratingCategory,min:s.min,max:s.max};
display({rows,rosterCount:users.length,ratingChecked,match:matchInfo});logEvent("info","scan",eligibleCount+" éligibles · "+unverified.length+" unverified");
if(pool.length>0&&unverified.length===pool.length&&ratingChecked===0){
 logEvent("error","scan","Toutes les requêtes de statistiques ont échoué ; résultats invalides.");
 status("Échec : aucune statistique accessible. Consultez le journal. Les résultats sont invalides.","error",null);
}else status("Analyse terminée : "+eligibleCount+" eligible; "+unverified.length+" unverified.","success",100);
}catch(e){logEvent("error","scan",e.message||String(e));status(ctl.signal.aborted?"Analyse annulée.":e.message||String(e),ctl.signal.aborted?"working":"error")}finally{if(progressFrame!==null){clearTimeout(progressFrame);progressFrame=null}state.controller=null;busy(false)}}
document.getElementById("tgpLogCopy")?.addEventListener("click",()=>navigator.clipboard?.writeText(events.map(e=>JSON.stringify(e)).join("\n")).catch(()=>{}));
document.getElementById("tgpLogClear")?.addEventListener("click",()=>{events.length=0;requestCount=0;errorCount=0;logEvent("info","log","Log cleared")});
logEvent("info","session","Recrutement Team France authentifié · requêtes OAuth actives");void loadClubAvatar();
u.form.addEventListener("submit",e=>{e.preventDefault();void load()});
u.recruit.addEventListener("submit",e=>{e.preventDefault();void scan()});
u.cancel.addEventListener("click",()=>state.controller?.abort());
const initial=new URLSearchParams(location.search).get("match");if(initial){u.ref.value=initial;void load()}
})();
