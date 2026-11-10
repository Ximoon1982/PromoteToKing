(()=>{"use strict";
const grid=document.getElementById("tgpGrid");if(!grid)return;
const esc=v=>String(v??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/\"/g,"&quot;").replace(/'/g,"&#039;");
const numericSetting=(value,fallback)=>value===undefined||value===null||value===""?fallback:(Number.isFinite(Number(value))?Number(value):fallback);
const ratingBounds=match=>({minimum:Math.max(0,numericSetting(match?.settings?.min_rating,0)),maximum:(()=>{const n=numericSetting(match?.settings?.max_rating,Number.POSITIVE_INFINITY);return n>0?n:Number.POSITIVE_INFINITY;})()});
const teamRatings=(team,bounds)=>(Array.isArray(team?.players)?team.players:[]).map(p=>Number(p?.rating)).filter(r=>Number.isFinite(r)&&r>=bounds.minimum&&r<=bounds.maximum).sort((a,b)=>b-a);
const minimumTeamPlayers=match=>{const n=Number(match?.settings?.min_team_players);return Number.isFinite(n)&&n>0?Math.floor(n):0};
const maximumTeamPlayers=match=>{const n=Number(match?.settings?.max_team_players);return Number.isFinite(n)&&n>0?Math.floor(n):Number.POSITIVE_INFINITY};
const eloExpectedScore=(a,b)=>1/(1+Math.pow(10,(b-a)/400));
function matchOutcomeProbabilities(scores){let d=[1];scores.forEach(score=>{for(let t=0;t<4;t++){const n=new Array(d.length+1).fill(0);for(let i=0;i<d.length;i++){n[i]+=d[i]*(1-score);n[i+1]+=d[i]*score}d=n}});const threshold=scores.length*2;let ours=0,theirs=0,draw=0;d.forEach((p,u)=>{if(u>threshold)ours+=p;else if(u<threshold)theirs+=p;else draw+=p});return{ours,theirs,draw}}
function reliable(r){const n=Number(r);if(!Number.isFinite(n))return 0;if(n>=1200)return n;return n-25-(1200-n)*.25}
function compare(a,b,maxPlayers){const p=Number.isFinite(maxPlayers)?a.slice(0,maxPlayers):[...a],o=Number.isFinite(maxPlayers)?b.slice(0,maxPlayers):[...b],count=Math.min(p.length,o.length),scores=[];let pa=0,oa=0,delta=0;for(let i=0;i<count;i++){const d=p[i]-o[i];delta+=reliable(p[i])-reliable(o[i]);if(d>0)pa++;else if(d<0)oa++;scores.push(eloExpectedScore(p[i],o[i]))}const out=scores.length?matchOutcomeProbabilities(scores):{ours:0,theirs:0,draw:1};return{boardCount:count,netBoardAdvantage:pa-oa,ourAdvantageBoards:pa,ourWinProbability:out.ours,opponentWinProbability:out.theirs}}
function reached(c,target,isLeague){if(!c||c.boardCount<target)return false;if(isLeague){const net=Math.min(target,Math.max(2,Math.ceil(target*.20))),ahead=Math.max(net,Math.ceil(target*.60));return c.netBoardAdvantage>=net&&c.ourAdvantageBoards>=ahead&&c.ourWinProbability>=.65}return c.ourWinProbability>=c.opponentWinProbability}
function preferredFloor(opponent,target,min,max){if(!opponent.length)return Math.min(max,Math.max(min,1200));const count=Math.max(1,Math.min(target,opponent.length)),topHalf=Math.max(1,Math.ceil(count/2)),boundary=Number(opponent[topHalf-1])||1200,preferred=Math.max(1200,boundary+50);return Math.min(max,Math.max(min,Math.ceil(preferred/25)*25))}
function candidates(min,max,preferred,isLeague){const out=[],add=(a,b)=>{for(let r=a;r<=b;r+=25)out.push(r)};if(isLeague)add(min,max);else{add(preferred,max);if(preferred>min)add(min,preferred-25)}return out}
function recruitment(match){const ours=match?.ourTeam,theirs=match?.opponentTeam,isLeague=match?.category==="league";if(!ours||!theirs)return null;const bounds=ratingBounds(match),maxPlayers=maximumTeamPlayers(match),minPlayers=minimumTeamPlayers(match),ourRatings=teamRatings(ours,bounds),opRatings=teamRatings(theirs,bounds),mandatory=Math.max(0,minPlayers),capped=Number.isFinite(maxPlayers)?Math.min(maxPlayers,mandatory):mandatory,missing=Math.max(0,capped-ourRatings.length),oppCapacity=Number.isFinite(maxPlayers)?Math.min(maxPlayers,opRatings.length):opRatings.length,target=isLeague?(Number.isFinite(maxPlayers)?Math.min(maxPlayers,Math.max(capped,oppCapacity)):Math.max(capped,oppCapacity)):oppCapacity;let compOpp=[...opRatings];if(isLeague&&opRatings.length<target){const current=[...opRatings].sort((a,b)=>b-a),fallback=Math.max(bounds.minimum,Math.min(Number.isFinite(bounds.maximum)?bounds.maximum:1200,1200)),avg=current.length?current.reduce((a,b)=>a+b,0)/current.length:fallback,median=current.length?current[Math.floor(current.length/2)]:fallback,assumed=Math.max(bounds.minimum,Math.min(Number.isFinite(bounds.maximum)?bounds.maximum:3000,Math.ceil(Math.max(avg,median)/25)*25));compOpp=[...current,...Array.from({length:Math.max(0,target-current.length)},()=>assumed)].sort((a,b)=>b-a)}const baseline=Math.max(0,target-ourRatings.length,missing),current=compare(ourRatings,compOpp,maxPlayers);if(ours?.locked===true)return{count:0,ready:true};if(target===0&&missing===0)return{count:0,ready:true};if(missing===0&&ourRatings.length>=target&&reached(current,target,isLeague))return{count:0,ready:true};const observedTop=Math.max(1800,ourRatings[0]||0,compOpp[0]||0),maxCandidate=Number.isFinite(bounds.maximum)?Math.min(3000,Math.floor(bounds.maximum/25)*25):Math.min(3000,Math.ceil((observedTop+400)/25)*25),minCandidate=Math.min(maxCandidate,Math.max(100,Math.ceil(bounds.minimum/25)*25)),maxRecruit=Math.max(baseline,Math.min(12,Math.max(Number.isFinite(maxPlayers)?Math.min(maxPlayers,8):6,1))),preferred=isLeague?minCandidate:preferredFloor(compOpp,target,minCandidate,maxCandidate),pool=candidates(minCandidate,maxCandidate,preferred,isLeague);let solution=null;for(let count=Math.max(1,baseline);count<=maxRecruit&&!solution;count++){for(const rating of pool){const projected=[...ourRatings,...Array.from({length:count},()=>rating)].sort((a,b)=>b-a),cmp=compare(projected,compOpp,maxPlayers);if(projected.length>=capped&&projected.length>=target&&(target===0||reached(cmp,target,isLeague))){solution={count,rating};break}}}const model=solution?.count??Math.max(1,baseline),margin=Math.max(2,Math.ceil(model*.25));return{count:model+margin,ready:false}}
function timeControlSeconds(value){if(value===undefined||value===null||value==="")return null;if(typeof value==="number")return Number.isFinite(value)&&value>0?value:null;const raw=String(value).trim();if(!raw)return null;const ratio=raw.match(/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/);if(ratio){const moves=Number(ratio[1]),seconds=Number(ratio[2]);return Number.isFinite(moves)&&moves>0&&Number.isFinite(seconds)&&seconds>0?seconds/moves:null}const n=Number(raw);return Number.isFinite(n)&&n>0?n:null}
function compactTime(value){const n=timeControlSeconds(value);if(!Number.isFinite(n)||n<=0)return"—";if(Math.abs(n/86400-Math.round(n/86400))<1e-9)return`${Math.round(n/86400)}d/m`;if(Math.abs(n/3600-Math.round(n/3600))<1e-9)return`${Math.round(n/3600)}h/m`;if(Math.abs(n/60-Math.round(n/60))<1e-9)return`${Math.round(n/60)}m/m`;return`${Math.round(n)}s/m`}
function fullTime(value){const n=timeControlSeconds(value);if(!Number.isFinite(n)||n<=0)return"Time control unavailable";if(Math.abs(n/86400-Math.round(n/86400))<1e-9){const d=Math.round(n/86400);return`${d} day${d===1?"":"s"}/move`}if(Math.abs(n/3600-Math.round(n/3600))<1e-9){const h=Math.round(n/3600);return`${h} hour${h===1?"":"s"}/move`}if(Math.abs(n/60-Math.round(n/60))<1e-9)return`${Math.round(n/60)} min/move`;return`${Math.round(n)} sec/move`}
function ratingLabel(match){const b=ratingBounds(match),min=Math.floor(b.minimum),max=Number.isFinite(b.maximum)?Math.floor(b.maximum):null;if(min<=0&&max===null)return"Open";if(min<=0)return`≤${max}`;if(max===null)return`${min}+`;return`${min}–${max}`}
function formatStartCompact(epoch){if(!Number.isFinite(Number(epoch))||Number(epoch)<=0)return"—";return new Intl.DateTimeFormat("en-GB",{day:"numeric",month:"short",timeZone:"UTC"}).format(new Date(Number(epoch)*1000))}
function formatStart(epoch){if(!Number.isFinite(Number(epoch))||Number(epoch)<=0)return"Start unavailable";return new Intl.DateTimeFormat("en-GB",{dateStyle:"medium",timeStyle:"short",timeZone:"UTC"}).format(new Date(Number(epoch)*1000))}
function urgency(epoch){const seconds=Number(epoch);if(!Number.isFinite(seconds)||seconds<=0)return{level:"",title:"Start unavailable",label:"—"};const delta=seconds*1000-Date.now();if(delta<=0)return{level:"urgent",title:formatStart(seconds),label:"Started"};const hours=delta/3600000;if(hours<1)return{level:"urgent",title:formatStart(seconds),label:`${Math.max(1,Math.ceil(delta/60000))}m`};if(hours<24)return{level:hours<=6?"urgent":"soon",title:formatStart(seconds),label:`${Math.ceil(hours)}h`};const days=hours/24;if(days<7)return{level:days<=3?"soon":"",title:formatStart(seconds),label:`${Math.ceil(days)}d`};return{level:"",title:formatStart(seconds),label:formatStartCompact(seconds)}}
function initials(name){return(String(name||"?").trim().split(/\s+/).filter(Boolean).slice(0,2).map(w=>w[0]||"").join("")||"?").toUpperCase()}
function needClass(n){return!Number.isFinite(Number(n))?"gold":Number(n)<=0?"green":Number(n)>=8?"red":"gold"}
function card(match){const rec=recruitment(match),count=Number.isFinite(Number(rec?.count))?Number(rec.count):null,need=count===null?"Need ?":count===0?"Need ✓":`Need ${count}`,u=urgency(match.startTime),badge=match.category==="league"?((match.leagueAcronyms||[]).join("/")||"League"):"Friendly",opponent=match.opponentName||"Opponent",logo=match.opponentLogo?`<img class="tgp-logo" src="${esc(match.opponentLogo)}" alt="${esc(opponent)} club logo" loading="lazy" referrerpolicy="no-referrer">`:`<div class="tgp-logo-fallback" aria-hidden="true">${esc(initials(opponent))}</div>`,rating=ratingLabel(match),time=compactTime(match.time_control),timeFull=fullTime(match.time_control),startMs=Number(match.startTime)*1000,league48=match.category==="league"&&Number.isFinite(startMs)&&startMs>=Date.now()&&startMs-Date.now()<=48*3600000,startClass=league48?"red":u.level==="urgent"?"red":u.level==="soon"?"gold":"muted",title=`${opponent} — ${match.name||`Match #${match.matchId}`} · ${u.title} · ${rating} · ${timeFull} · ${need}`;return`<a class="tgp-card${league48?" league-48h":""}" href="${esc(match.url)}" target="_blank" rel="noopener noreferrer" title="${esc(title)}" aria-label="${esc(title)}"><div class="tgp-visual">${logo}<span class="tgp-badge${match.category==="friendly"?" friendly":""}">${esc(badge)}</span>${match.urgent?'<span class="tgp-priority">Priority</span>':""}</div><div class="tgp-info"><div class="tgp-line tgp-primary"><span class="tgp-start ${startClass}">${esc(u.label)}</span><span class="tgp-separator">·</span><span class="tgp-need ${needClass(count)}">${esc(need)}</span></div><div class="tgp-line tgp-secondary"><span class="tgp-time" title="${esc(timeFull)}">${esc(time)}</span><span class="tgp-separator">·</span><span class="tgp-rating">${esc(rating)}</span></div></div></a>`}


const arenaHost = document.getElementById("arenaShowcase");
const arenaSection = document.getElementById("arenaSection");
let configuredArenas = [];

function arenaTiming(a, now=Date.now()) {
  const start=Date.parse(a?.startAt||"");
  const end=Date.parse(a?.endAt||"") || start+Number(a?.durationMinutes||0)*60000;
  if(!Number.isFinite(start)||!Number.isFinite(end)||end<=start||now>=end) return null;
  return {start,end,phase:now<start-3600000?"upcoming":now<start?"registration":"ongoing"};
}
function parseArenaControl(value) {
  const raw=String(value||"").trim().toLowerCase();
  if(raw.includes("bullet"))return "bullet";
  if(raw.includes("blitz"))return "blitz";
  if(raw.includes("rapid"))return "rapid";
  const hit=raw.match(/(\d+(?:\.\d+)?)\s*(?:\+|\||\/)\s*(\d+(?:\.\d+)?)/);
  const estimated=hit?Number(hit[1])+Number(hit[2])*40/60:Number(raw);
  return Number.isFinite(estimated)&&estimated<3?"bullet":Number.isFinite(estimated)&&estimated<10?"blitz":"rapid";
}
function arenaSpeedIcon(kind) {
  if(kind==="bullet")return '<svg class="tgp-speed-icon bullet" viewBox="0 0 120 120" role="img" aria-label="Bullet"><path fill="currentColor" d="M20 72h52c18 0 30-10 30-24S90 24 72 24H59c-3 0-6 2-7 5L39 66c-1 4 2 6 6 6h27z"/><rect x="13" y="32" width="29" height="7" rx="3.5" fill="currentColor" opacity=".66"/><rect x="7" y="47" width="34" height="7" rx="3.5" fill="currentColor" opacity=".48"/><rect x="16" y="62" width="24" height="7" rx="3.5" fill="currentColor" opacity=".34"/></svg>';
  if(kind==="blitz")return '<svg class="tgp-speed-icon blitz" viewBox="0 0 120 120" role="img" aria-label="Blitz"><path fill="currentColor" d="M68 8 24 66h29l-8 46 50-65H65z"/></svg>';
  return '<svg class="tgp-speed-icon rapid" viewBox="0 0 120 120" role="img" aria-label="Rapid"><circle cx="60" cy="62" r="42" fill="none" stroke="currentColor" stroke-width="10"/><path d="M60 38v26l19 12" fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M42 12h36" stroke="currentColor" stroke-width="10" stroke-linecap="round"/></svg>';
}
function arenaCountdown(ms) {
  const total=Math.max(0,Math.ceil(ms/1000)),h=Math.floor(total/3600),m=Math.floor(total%3600/60),s=total%60;
  return String(h)+"h "+String(m).padStart(2,"0")+"m "+String(s).padStart(2,"0")+"s";
}
function arenaStartText(start) {
  const date=new Date(start);
  if(!Number.isFinite(date.getTime()))return "—";
  const options={timeZone:"Europe/Luxembourg"};
  return new Intl.DateTimeFormat("en-GB",{...options,day:"numeric",month:"short"}).format(date)+" · "+
    new Intl.DateTimeFormat("en-GB",{...options,hour:"2-digit",minute:"2-digit",hour12:false}).format(date);
}
function arenaLiveText(t,now) {
  if(t.phase==="ongoing")return arenaCountdown(t.end-now);
  if(t.phase==="registration")return "Starts in "+Math.max(1,Math.ceil((t.start-now)/60000))+" mn";
  return arenaStartText(t.start);
}
function arenaCard(a,t,now) {
  const kind=parseArenaControl(a.timeControl), status=t.phase==="upcoming"?"Upcoming":t.phase==="registration"?"Registration":"On-going";
  const name=String(a.name||"Arena"),control=String(a.timeControl||"—"),duration=String(a.duration||"—");
  const title=name+" · "+status+" · "+duration+" · "+control+" ("+kind+")";
  return '<a class="tgp-card arena-card" href="'+esc(a.link)+'" target="_blank" rel="noopener noreferrer" title="'+esc(title)+'" aria-label="'+esc(title)+'" data-arena-start="'+t.start+'" data-arena-end="'+t.end+'" data-arena-phase="'+t.phase+'">'+
    '<div class="tgp-visual">'+arenaSpeedIcon(kind)+'<span class="tgp-badge '+t.phase+'">'+status+'</span></div>'+
    '<div class="tgp-info"><div class="arena-start-line '+t.phase+'" data-arena-live>'+esc(arenaLiveText(t,now))+'</div>'+
    '<div class="arena-meta-line" title="'+esc(name)+'"><strong>'+esc(duration)+'</strong> · '+esc(control)+'</div></div></a>';
}
function renderArenas(next) {
  if(Array.isArray(next))configuredArenas=next;
  if(!arenaHost||!arenaSection)return;
  const now=Date.now();
  const visible=configuredArenas.map(a=>({a,t:arenaTiming(a,now)}))
    .filter(x=>x.a?.active===true&&x.a?.name&&x.a?.link&&x.t)
    .sort((a,b)=>a.t.start-b.t.start).slice(0,2);
  arenaSection.hidden=visible.length===0;
  arenaHost.innerHTML=visible.map(x=>arenaCard(x.a,x.t,now)).join("");
}
function tickArenaClock() {
  if(!arenaSection||arenaSection.hidden)return;
  const now=Date.now();
  let changed=false;
  arenaHost.querySelectorAll(".arena-card[data-arena-start]").forEach(card=>{
    const start=Number(card.dataset.arenaStart),end=Number(card.dataset.arenaEnd);
    if(!Number.isFinite(start)||!Number.isFinite(end)||now>=end){changed=true;return;}
    const phase=now<start-3600000?"upcoming":now<start?"registration":"ongoing";
    if(phase!==card.dataset.arenaPhase){changed=true;return;}
    const live=card.querySelector("[data-arena-live]");
    if(live&&phase!=="upcoming")live.textContent=arenaLiveText({start,end,phase},now);
  });
  if(changed)renderArenas();
}

async function load(){try{const r=await fetch(`api.php?action=cards&_=${Date.now()}`,{cache:"no-store",credentials:"same-origin",headers:{Accept:"application/json"}}),p=await r.json();if(!r.ok||p?.ok===false)throw Error(p?.error?.message||`HTTP ${r.status}`);const cards=Array.isArray(p.cards)?p.cards:[];grid.innerHTML=cards.map(card).join("");renderArenas(p.arenas)}catch(error){console.error("Team France Match Showcase",error);grid.replaceChildren();renderArenas([])}}
void load();window.setInterval(load,60000);window.setInterval(tickArenaClock,1000);
})();
