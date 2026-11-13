/* P2K v2.15.9 — additive compact Player Spotlight / Tournament Winners embeds.
 * All scores, rank positions, aliases and excluded medalists come from existing
 * canonical public services. No raw source scraping or independently scored data.
 */
(()=>{"use strict";
const id=document.body.dataset.widget;
const isHall=id==='hall-of-fame';
if(!['player-spotlight','tournament-winners','hall-of-fame'].includes(id))return;
const main=document.getElementById('p2kwContent'),status=document.getElementById('p2kwStatus'),foot=document.getElementById('p2kwFoot');
if(!main||!status||!foot)return;
document.querySelector('.p2kw-panel')?.classList.add('p2kw-widget-panel');
const ROOT=new URL('../../../',document.currentScript.src);
const SPOT=new URL('server/widgets/public/api.php',ROOT);
const HALL=new URL('server/team-points/public/hall-search.php',ROOT);
const MEMBERS=new URL('server/team-points/public/members-insights.php',ROOT);
const ARENAS=new URL('server/team-points/public/arenas-insights.php',ROOT);
const TOURNAMENT_PAGE=new URL('Tournaments.html?panel=tournaments',ROOT).href;
const fmt=new Intl.NumberFormat('en-GB',{maximumFractionDigits:1});
const esc=v=>String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const num=v=>v===null||v===undefined||!Number.isFinite(Number(v))?'—':fmt.format(Number(v));
const key=v=>String(v||'').trim().toLowerCase();
const clamped=(value,min,max)=>Math.max(min,Math.min(max,Number(value)||min));
function url(base,params){const out=new URL(base);for(const [k,v] of Object.entries(params))if(v!==null&&v!==undefined&&v!=='')out.searchParams.set(k,String(v));return out.href}
function safeLink(value){try{const u=new URL(value);return ['https:','http:'].includes(u.protocol)?u.href:''}catch{return''}}
function notify(){try{parent.postMessage({type:'p2k-widget-height',widget:id,height:Math.ceil(document.documentElement.scrollHeight)},'*')}catch{}}
if('ResizeObserver' in window)new ResizeObserver(notify).observe(document.body);
const setStatus=value=>{status.textContent=value;notify()};
const setFoot=value=>{foot.textContent=value;notify()};
async function get(endpoint,params={},timeout=18000){
 const u=url(endpoint,params),ctrl=new AbortController(),timer=setTimeout(()=>ctrl.abort(),timeout);
 try{const r=await fetch(u,{credentials:'same-origin',cache:'default',headers:{Accept:'application/json'},signal:ctrl.signal});
 const j=await r.json().catch(()=>({}));if(!r.ok||j.ok===false)throw Error(j?.error?.message||'HTTP '+r.status);return j;
 }finally{clearTimeout(timer)}
}
function medal(label,count){return '<span>'+label+' <strong>'+esc(num(count))+'</strong></span>'}
function rankImage(rank,kind){
 if(!rank||rank.key==='unranked')return '';
 const name=kind==='live'?String(rank?.icon||''):String(rank?.image||'');
 const file=name.split(/[\\/]/).pop();
 if(!/^[a-z0-9_.-]+\.(?:png|webp|jpg|jpeg)$/i.test(file||''))return '';
 const src=new URL('assets/images/ranks/'+file,ROOT);
 return '<img src="'+esc(src.href)+'" alt="" loading="lazy">';
}
function rankCard(label,rank,kind){
 return '<div class="p2kw-rank-card">'+(rankImage(rank,kind)||'<span class="p2kw-medal-symbol">♟</span>')+'<span><small>'+esc(label)+'</small><strong>'+esc(rank?.name||'Unranked')+'</strong></span></div>';
}
function miniGrid(entries){return '<div class="p2kw-metric-grid">'+entries.map(([label,value])=>'<div class="p2kw-metric"><strong>'+esc(typeof value==='string'&&value.startsWith('#')?value:num(value))+'</strong><span>'+esc(label)+'</span></div>').join('')+'</div>'}
function row(label,value){return '<div class="p2kw-progress-row"><span>'+esc(label)+'</span><strong>'+esc(value)+'</strong></div>'}
function announce(message){main.innerHTML='<p class="p2kw-empty">'+esc(message)+'</p>';notify()}
let config={},currentUser='',playerEpoch=0,arenaPeriods=null,playerData=null,periodData={},activePlayerTab='daily';

// Hall of Fame uses the same detailed player/tournament renderers as the two
// standalone widgets. Only its compact/expanded leaderboard differs.
let hallMode='leaderboards',hallScope='all',hallExpanded=null,hallPage=1,hallEpoch=0;
const hallPages={daily:null,arena:null};
const hallTop={daily:null,arena:null};
let hallTabs=null;
if(isHall){
  hallTabs=document.createElement('nav');
  hallTabs.className='p2kw-hof-tabs';
  hallTabs.setAttribute('aria-label','Hall of Fame sections');
  main.parentNode.insertBefore(hallTabs,main);
  hallTabs.addEventListener('click',event=>{
    const button=event.target.closest('[data-hof-mode]');
    if(!button)return;
    const target=button.dataset.hofMode;
    if(target===hallMode)return;
    hallMode=target;
    playerEpoch++;tour.epoch++;hallEpoch++;
    currentUser='';setFoot('');main.scrollTop=0;
    drawHallTabs();
    if(target==='tournaments')void fetchTournaments();
    else void loadPlayerHome();
  });
  drawHallTabs();
}
function drawHallTabs(){
 if(!hallTabs)return;
 hallTabs.innerHTML=['leaderboards','tournaments'].map(v=>
   '<button type="button" data-hof-mode="'+v+'" aria-pressed="'+(hallMode===v)+'">'+
   (v==='leaderboards'?'Leaderboards':'Tournaments')+'</button>').join('');
}
function hallTitle(kind){
 return kind==='daily'?'Daily Match Points':'Multi Club Arenas Points';
}
function hallItems(data,expanded){
 const items=Array.isArray(data?.rows)?data.rows:[];
 const list=expanded?items:items.slice(0,5);
 return '<div class="p2kw-hof-list'+(expanded?' is-expanded':'')+'">'+
   (list.length?list.map((p,i)=>'<div class="p2kw-entry"><span class="p2kw-leader-position">'+
     esc(p.rank||i+1)+'</span><div class="p2kw-entry-main"><button type="button" class="p2kw-player-link" data-username="'+esc(p.username)+'">'+esc(p.username)+'</button></div>'+
     '<strong class="p2kw-leader-score">'+esc(num(p.points))+'</strong></div>').join('')
   :'<p class="p2kw-empty">No eligible players in this period.</p>')+'</div>';
}
function renderHallHome(){
 if(!isHall||hallMode!=='leaderboards')return;
 const mode=hallExpanded,periodLabels={all:'All time',quarter:'Quarter',month:'Month'};
 const periodSelect='<select id="p2kwHofPeriod" aria-label="Daily Match Points leaderboard period">'+
   ['all','quarter','month'].map(p=>'<option value="'+p+'" '+(p===hallScope?'selected':'')+'>'+periodLabels[p]+'</option>').join('')+'</select>';
 let html=playerHeader();
 for(const kind of ['daily','arena']){
   if(mode&&kind!==mode)continue;
   const data=mode?hallPages[kind]:(hallTop[kind]||hallPages[kind]);
   html+='<div class="p2kw-subhead p2kw-hof-subhead"><strong>'+hallTitle(kind)+'</strong>'+
     (kind==='daily'?periodSelect:'')+'</div>';
   if(!data){html+='<p class="p2kw-loading-status">Loading leaderboard…</p>';continue}
   html+=hallItems(data,Boolean(mode));
   if(!mode){
     html+='<button type="button" class="p2kw-mini-button p2kw-hof-more" data-hof-more="'+kind+'">show more+'</button>';
   }else{
     const pagination=data.pagination||{};
     html+='<div class="p2kw-widget-nav p2kw-hof-pagination">'+
       '<button class="p2kw-mini-button" type="button" data-hof-page="-1" '+(pagination.page<=1?'disabled':'')+'>Previous</button>'+
       '<span>Page '+num(pagination.page||1)+' / '+num(pagination.total_pages||1)+' · '+num(pagination.total_rows||0)+' players</span>'+
       '<button class="p2kw-mini-button" type="button" data-hof-page="1" '+(pagination.page>=pagination.total_pages?'disabled':'')+'>Next</button></div>'+
       '<button type="button" class="p2kw-mini-button p2kw-hof-more" data-hof-back>Back to top five</button>';
   }
 }
 main.innerHTML=html;
 bindPlayerSearch();
 main.querySelector('#p2kwHofPeriod')?.addEventListener('change',event=>{
   const next=event.target.value;
   if(!['all','quarter','month'].includes(next)||next===hallScope)return;
   hallScope=next;hallPage=1;hallPages.daily=null;hallTop.daily=null;
   renderHallHome();void hallFetch('daily',1);
 });
 main.querySelectorAll('[data-hof-more]').forEach(button=>button.onclick=()=>{
   hallExpanded=button.dataset.hofMore;hallPage=1;
   void hallFetch(hallExpanded,1);
 });
 main.querySelector('[data-hof-back]')?.addEventListener('click',()=>{
   hallExpanded=null;hallPage=1;renderHallHome();main.scrollTop=0;
 });
 main.querySelectorAll('[data-hof-page]').forEach(button=>button.onclick=()=>{
   const direction=Number(button.dataset.hofPage)||0;
   const pagination=hallPages[hallExpanded]?.pagination||{};
   const next=Math.max(1,Math.min(pagination.total_pages||1,(pagination.page||1)+direction));
   if(next!==(pagination.page||1)){hallPage=next;void hallFetch(hallExpanded,next);}
 });
 main.querySelectorAll('[data-username]').forEach(button=>button.onclick=()=>{
   currentUser=button.dataset.username;
   void lookupPlayer(currentUser);
 });
 notify();
}
async function hallFetch(kind,page=1){
 if(!isHall||hallMode!=='leaderboards')return;
 const epoch=++hallEpoch,scope=kind==='daily'?hallScope:'all';
 setStatus('Loading…');
 try{
   const response=await get(SPOT,{action:'hall-leaderboard',kind,period:scope,page},24000);
   if(epoch!==hallEpoch||hallMode!=='leaderboards'||scope!==(kind==='daily'?hallScope:'all'))return;
   hallPages[kind]=response;
   if((response.pagination?.page||1)===1)hallTop[kind]=response;
   if(hallExpanded===kind)hallPage=response.pagination?.page||1;
   setStatus('LIVE');renderHallHome();
 }catch(error){
   if(epoch!==hallEpoch||hallMode!=='leaderboards')return;
   setStatus('Unavailable');announce('Leaderboard temporarily unavailable: '+(error.message||error));
 }
}
async function hallLoadHome(){
 if(!isHall||hallMode!=='leaderboards')return;
 if(hallExpanded){
   if(hallPages[hallExpanded]?.pagination?.page===hallPage){setStatus('LIVE');renderHallHome();}
   else await hallFetch(hallExpanded,hallPage);
   return;
 }
 const epoch=++hallEpoch;
 setStatus('Loading…');renderHallHome();
 try{
   const pending=[];
   if(!hallTop.daily)pending.push(get(SPOT,{action:'hall-leaderboard',kind:'daily',period:hallScope,page:1},24000).then(r=>['daily',r]));
   if(!hallTop.arena)pending.push(get(SPOT,{action:'hall-leaderboard',kind:'arena',period:'all',page:1},24000).then(r=>['arena',r]));
   const values=await Promise.all(pending);
   if(epoch!==hallEpoch||hallMode!=='leaderboards')return;
   values.forEach(([kind,response])=>{hallTop[kind]=response;if(!hallPages[kind])hallPages[kind]=response;});
   setStatus('LIVE');renderHallHome();
 }catch(error){
   if(epoch!==hallEpoch||hallMode!=='leaderboards')return;
   setStatus('Unavailable');announce('Leaderboard temporarily unavailable: '+(error.message||error));
 }
}

const playerHeader=()=>'<form class="p2kw-search" id="p2kwPlayerForm"><input id="p2kwPlayerSearch" type="search" maxlength="80" autocomplete="off" spellcheck="false" aria-label="Chess.com member username" placeholder="Search member username" value="'+esc(currentUser)+'"><button type="submit">Find</button></form>';
function playerHome(data){
 const daily=(Array.isArray(data.daily)?data.daily:[]).slice(0,Math.min(5,config.limit||5));
 const arena=config.showArena===false?[]:(Array.isArray(data.arena)?data.arena:[]).slice(0,Math.min(5,config.limit||5));
 function leaders(items){return items.map((p,i)=>'<div class="p2kw-entry"><span class="p2kw-leader-position">'+(i+1)+'</span><div class="p2kw-entry-main"><button type="button" class="p2kw-player-link" data-username="'+esc(p.username)+'">'+esc(p.username)+'</button></div><strong class="p2kw-leader-score">'+esc(num(p.points))+'</strong></div>').join('')||'<div class="p2kw-notice">No published leaderboard rows.</div>'}
 main.innerHTML=playerHeader()+'<div class="p2kw-subhead"><span>Daily · Top '+daily.length+'</span><span class="p2kw-small">Team Points</span></div>'+leaders(daily)
 +(config.showArena===false?'':'<div class="p2kw-subhead"><span>MCA Arenas · Top '+arena.length+'</span><span class="p2kw-small">Live points</span></div>'+leaders(arena))
;
 bindPlayerSearch();main.querySelectorAll('[data-username]').forEach(el=>el.onclick=()=>{const input=main.querySelector('#p2kwPlayerSearch');input.value=el.dataset.username;void lookupPlayer(el.dataset.username)});notify();
}
function showPlayer(data){
 if(!data?.found||!data.daily){main.innerHTML=playerHeader()+'<p class="p2kw-empty">No current member matched that name.</p><button class="p2kw-mini-button" id="p2kwBackTop">'+(isHall?'Back to leaderboard':'Show top five')+'</button>';bindPlayerSearch();main.querySelector('#p2kwBackTop').onclick=()=>{currentUser='';void loadPlayerHome()};return}
 const daily=data.daily,live=data.live,medals=data.tournaments||{},user=data.username||daily.username||currentUser;
 const pos=periodData||{};
 const tab='<nav class="p2kw-seg" aria-label="Player scoring"><button type="button" data-scoretab="daily" aria-pressed="'+(activePlayerTab==='daily')+'">'+(isHall?'Daily Matches':'Daily')+'</button><button type="button" data-scoretab="arena" aria-pressed="'+(activePlayerTab==='arena')+'">'+(isHall?'Multi Club Arenas':'Arenas')+'</button></nav>';
 let details='';
 if(activePlayerTab==='daily'){
   const overall=pos.overall||{},month=pos.month||{},quarter=pos.quarter||{},last30=pos.last30||{};
   const change=Number(overall.position_change),valid=overall.position_change!==null&&overall.position_change!==undefined;
   details='<div class="p2kw-total-row"><div><div class="p2kw-label">Daily Team Points</div><div class="p2kw-big">'+esc(num(daily.points))+'</div></div><div class="p2kw-big-position"><strong>#'+esc(num(daily.team_position))+'</strong><small>Overall</small></div></div>'
    +miniGrid([['This month rank',month.team_position],['This quarter rank',quarter.team_position],['Last 30d pts',last30.points]])
    +row('Overall leaderboard evolution (1m)',valid?(change>0?'↑ '+change:change<0?'↓ '+Math.abs(change):'— No change'):'—')
    +(overall.previous_position!=null?row('Rank 1 month ago','#'+num(overall.previous_position)):'');
 }else{
   if(!live?.available){details='<p class="p2kw-empty">No published Arena score for this current member.</p>'}
   else {
     const p=arenaPeriods||{};
     details='<div class="p2kw-total-row"><div><div class="p2kw-label">Arena / Live points</div><div class="p2kw-big">'+esc(num(live.points))+'</div></div><div class="p2kw-big-position"><strong>#'+esc(num(live.team_position))+'</strong><small>Overall</small></div></div>'
       +miniGrid([['Month rank',p.month?.rank],['Quarter rank',p.quarter?.rank],['Last 30d pts',p.last30?.points]])
       +'<div class="p2kw-subhead"><span>Best arena results</span></div>'
       +miniGrid([['Best arena finish',live.best_rank==null?'—':'#'+num(live.best_rank)],['Arena participations',live.arenas],['Best win streak',live.best_streak]])
       ;
   }
 }
 const tUrl=new URL('Tournaments.html',ROOT);tUrl.searchParams.set('player',user);tUrl.searchParams.set('panel','tournaments');
 main.innerHTML=playerHeader()+'<div class="p2kw-subhead"><span>'+esc(user)+'</span><button id="p2kwTopButton" type="button" class="p2kw-mini-button">'+(isHall?'Back to leaderboard':'Top 5')+'</button></div>'
 +'<div class="p2kw-ranks">'+rankCard('Daily team rank',daily.rank,'daily')+rankCard('Live arena rank',live?.rank,'live')+'</div>'
 +tab+details
 +(activePlayerTab==='daily'&&config.showMedals!==false?'<div class="p2kw-subhead"><span><a class="p2kw-title-link" href="'+esc(tUrl.href)+'" target="_blank" rel="noopener">Tournament medals</a></span><span class="p2kw-small">Podiums</span></div><div class="p2kw-medals">'+medal('🥇',medals.gold)+medal('🥈',medals.silver)+medal('🥉',medals.bronze)+'</div>':'');
 bindPlayerSearch();main.querySelector('#p2kwTopButton').onclick=()=>{currentUser='';void loadPlayerHome()};
 main.querySelectorAll('[data-scoretab]').forEach(b=>b.onclick=()=>{activePlayerTab=b.dataset.scoretab;showPlayer(data);if(activePlayerTab==='arena'&&arenaPeriods===null&&live?.available)void loadArenaPeriods(user,playerEpoch)});
 notify();
}
function bindPlayerSearch(){const form=main.querySelector('#p2kwPlayerForm');if(form)form.onsubmit=e=>{e.preventDefault();void lookupPlayer(main.querySelector('#p2kwPlayerSearch').value.trim())}}
function utcDate(d){return d.toISOString().slice(0,10)}
function periods(){
 const now=new Date(),y=now.getUTCFullYear(),m=now.getUTCMonth(),q=Math.floor(m/3)*3;
 const first=utcDate(new Date(Date.UTC(y,m,1))),quarter=utcDate(new Date(Date.UTC(y,q,1)));
 const last30=utcDate(new Date(Date.UTC(y,m,now.getUTCDate()-29)));
 return {end:utcDate(now),month:first,quarter,last30};
}
async function loadDailyPeriods(user,epoch){
 const p=periods();
 const query={section:'table',search:user,sort:'points',direction:'desc',filter:'current',page_size:10,include_position_comparison:0};
 for(const [tag,extra] of [['overall',{evolution:'1m',include_position_comparison:1}],['month',{start:p.month,end:p.end}],['quarter',{start:p.quarter,end:p.end}],['last30',{start:p.last30,end:p.end}]]){
   try{
     const response=await get(MEMBERS,{...query,...extra},24000);
     if(epoch!==playerEpoch)return;
     periodData[tag]=(response.rows||[]).find(x=>key(x.username)===key(user))||null;
   }catch(error){if(epoch!==playerEpoch)return;console.warn('P2K widget daily period unavailable:',tag,error);periodData[tag]=null}
   if(activePlayerTab==='daily'&&epoch===playerEpoch)showPlayer(playerData);
 }
}
async function loadArenaPeriods(user,epoch){
 const p=periods();arenaPeriods={loading:true};showPlayer(playerData);
 for(const [tag,start] of [['month',p.month],['quarter',p.quarter],['last30',p.last30]]){
   try{
     const response=await get(ARENAS,{section:'leaders',filter:'current',sort:'points',direction:'desc',start,end:p.end},24000);
     if(epoch!==playerEpoch)return;
     const leaders=Array.isArray(response.leaders)?response.leaders:[];
     const index=leaders.findIndex(x=>key(x.username)===key(user));
     arenaPeriods[tag]=index>=0?{rank:index+1,points:leaders[index].points}:null;
   }catch(error){if(epoch!==playerEpoch)return;console.warn('P2K widget arena period unavailable:',tag,error);arenaPeriods[tag]=null}
   if(epoch===playerEpoch&&activePlayerTab==='arena')showPlayer(playerData);
 }
 arenaPeriods.loading=false;if(epoch===playerEpoch&&activePlayerTab==='arena')showPlayer(playerData);
}
async function lookupPlayer(name){
 currentUser=String(name||'').replace(/^@/,'').trim().slice(0,80);
 if(!currentUser){void loadPlayerHome();return}
 const epoch=++playerEpoch,requested=currentUser;
 setStatus('Loading…');main.innerHTML=playerHeader()+'<p class="p2kw-loading-status">Looking up current member…</p>';bindPlayerSearch();
 try{
   const response=await get(HALL,{username:requested},24000);
   if(epoch!==playerEpoch)return;
   playerData=response;activePlayerTab='daily';periodData={};arenaPeriods=null;
   if(response.found){currentUser=String(response.username||requested);setStatus('LIVE');setFoot('');showPlayer(response);void loadDailyPeriods(currentUser,epoch)}
   else{setStatus('NO MATCH');showPlayer(response)}
 }catch(error){if(epoch!==playerEpoch)return;setStatus('Unavailable');main.innerHTML=playerHeader()+'<p class="p2kw-empty">'+esc(error.message||'Player data unavailable')+'</p>';bindPlayerSearch()}
}
async function loadPlayerHome(){
 if(isHall){currentUser='';playerData=null;return hallLoadHome();}
 const epoch=++playerEpoch;playerData=null;periodData={};arenaPeriods=null;activePlayerTab='daily';setStatus('Loading…');
 try{const response=await get(SPOT,{action:'spotlight-leaders'});if(epoch!==playerEpoch)return;setStatus('LIVE');setFoot('');playerHome(response)}
 catch(error){setStatus('Unavailable');announce('Player leaderboards are temporarily unavailable: '+(error.message||error))}
}
const tour={completed:null,completedPage:1,completedPagination:{page:1,pages:1,total:0},selectedSearch:null,medalPage:1,search:'',searchKind:'tournament',searchPage:1,searchResults:[],pagination:{page:1,pages:1,total:0},epoch:0};
function podiumRows(t){const p=t?.podium||{},out=[];for(const [type,symbol] of [['gold','🥇'],['silver','🥈'],['bronze','🥉']])for(const item of Array.isArray(p[type])?p[type]:[]){const name=typeof item==='string'?item:String(item?.username||item?.name||'');if(name.trim())out.push({name,type,symbol})}return out}
function renderPlayerMedalSearch(){
 const results=tour.searchResults,p=tour.pagination;
 const medals=t=>Array.isArray(t.matched_medalists)?t.matched_medalists:[];
 const pageRows=results.map(t=>{
   const href=safeLink(t.webUrl)||TOURNAMENT_PAGE;
   const text=medals(t).map(m=>(m.medal==='gold'?'🥇':m.medal==='silver'?'🥈':'🥉')+' '+esc(m.username)).join(' · ');
   return '<div class="p2kw-entry p2kw-medal-search-entry"><div class="p2kw-entry-main">'
     +'<a class="p2kw-title-link" href="'+esc(href)+'" target="_blank" rel="noopener noreferrer">'+esc(t.name)+'</a>'
     +(text?'<small class="p2kw-medal-match">'+text+'</small>':'')
     +'</div><span class="p2kw-status-chip '+(key(t.status)==='finished'?'is-finished':key(t.status).includes('progress')?'is-progress':'')+'">'+esc(String(t.status||'unknown').replaceAll('_',' '))+'</span></div>';
 }).join('');
 main.innerHTML='<form class="p2kw-search" id="p2kwTournamentSearch"><input type="search" id="p2kwTournamentInput" maxlength="80" placeholder="Tournament or medalist" aria-label="Search tournament or medalist" value="'+esc(tour.search)+'"><button type="submit">Search</button></form>'
   +'<div class="p2kw-subhead"><span>Medals for '+esc(tour.search)+'</span><button type="button" class="p2kw-mini-button" id="p2kwClearSearch">Back</button></div>'
   +(pageRows||'<p class="p2kw-notice">No medal-winning tournaments matched this player.</p>')
   +'<div class="p2kw-widget-nav"><button type="button" class="p2kw-mini-button" id="p2kwTourSearchPrev" '+(p.page<=1?'disabled':'')+'>Previous</button><span>Page '+p.page+' / '+p.pages+' · '+p.total+' tournaments</span><button type="button" class="p2kw-mini-button" id="p2kwTourSearchNext" '+(p.page>=p.pages?'disabled':'')+'>Next</button></div>';
 main.querySelector('#p2kwTournamentSearch').onsubmit=e=>{e.preventDefault();tour.search=main.querySelector('#p2kwTournamentInput').value.trim();tour.searchPage=1;tour.selectedSearch=null;void fetchTournaments()};
 main.querySelector('#p2kwClearSearch').onclick=()=>{tour.search='';tour.searchKind='tournament';tour.selectedSearch=null;tour.searchResults=[];tour.searchPage=1;tour.completedPage=1;void fetchTournaments()};
 main.querySelector('#p2kwTourSearchPrev').onclick=()=>{tour.searchPage--;void fetchTournaments()};
 main.querySelector('#p2kwTourSearchNext').onclick=()=>{tour.searchPage++;void fetchTournaments()};
 notify();
}
function renderTour(){
 if(tour.search && tour.searchKind==='player') {renderPlayerMedalSearch();return;}
 const active=tour.selectedSearch||tour.completed,items=podiumRows(active),size=clamped(config.medalPageSize||3,1,20),pages=Math.max(1,Math.ceil(items.length/size)),page=Math.min(pages,Math.max(1,tour.medalPage));tour.medalPage=page;
 const pageItems=items.slice((page-1)*size,page*size);
 const link=safeLink(active?.webUrl)||TOURNAMENT_PAGE,title=active?.name||'No finished tournament';
 const activeStatus=String(active?.status||'unknown').replaceAll('_',' '),activeStatusClass=key(active?.status)==='finished'?'is-finished':key(active?.status).includes('progress')?'is-progress':'';
 main.innerHTML='<form class="p2kw-search" id="p2kwTournamentSearch"><input type="search" id="p2kwTournamentInput" maxlength="80" placeholder="Tournament or medalist" aria-label="Search tournament or medalist" value="'+esc(tour.search)+'"><button type="submit">Search</button></form>'
 +'<div class="p2kw-widget-nav"><button class="p2kw-mini-button" id="p2kwPrevT" '+(tour.completedPage<=1?'disabled':'')+'>◀</button><span>'+(tour.selectedSearch?'Search result':'Completed '+(tour.completedPagination.total?tour.completedPage:0)+' / '+tour.completedPagination.total)+'</span><button class="p2kw-mini-button" id="p2kwNextT" '+(tour.completedPage>=tour.completedPagination.pages?'disabled':'')+'>▶</button></div>'
 +(active?'<div class="p2kw-subhead"><a class="p2kw-title-link" href="'+esc(link)+'" target="_blank" rel="noopener noreferrer">'+esc(title)+'</a><span class="p2kw-status-chip '+activeStatusClass+'">'+esc(activeStatus)+'</span></div>':'<p class="p2kw-empty">No finished tournament is available.</p>')
 +'<div class="p2kw-subhead"><span>Medalists</span><span class="p2kw-small">'+items.length+' awarded positions</span></div>'
 +(pageItems.length?pageItems.map(x=>'<div class="p2kw-entry p2kw-medalist-row"><span class="p2kw-medal-symbol">'+x.symbol+'</span><div class="p2kw-entry-main"><strong>'+esc(x.name)+' <span class="p2kw-medal-inline">'+esc(x.type)+' medal</span></strong></div></div>').join(''):'<p class="p2kw-notice">No published medalist for this tournament.</p>')
 +'<div class="p2kw-widget-nav"><button class="p2kw-mini-button" id="p2kwMedalPrev" '+(page<=1?'disabled':'')+'>Previous</button><span>Medal page '+page+' / '+pages+'</span><button class="p2kw-mini-button" id="p2kwMedalNext" '+(page>=pages?'disabled':'')+'>Next</button></div>'
 +(tour.search?'<div class="p2kw-subhead"><span>'+(tour.searchKind==='player'?'Medaled tournaments':'Tournament results')+'</span><button class="p2kw-mini-button" id="p2kwClearSearch">Clear</button></div>'
 +tour.searchResults.map((t,i)=>'<div class="p2kw-entry"><div class="p2kw-entry-main"><button class="p2kw-player-link" type="button" data-tour-index="'+i+'">'+esc(t.name)+'</button>'+(Array.isArray(t.matched_medalists)&&t.matched_medalists.length?'<small class="p2kw-medal-match">'+t.matched_medalists.map(m=>(m.medal==='gold'?'🥇':m.medal==='silver'?'🥈':'🥉')+' '+esc(m.username)).join(' · ')+'</small>':'')+'</div><span class="p2kw-status-chip '+(key(t.status)==='finished'?'is-finished':key(t.status).includes('progress')?'is-progress':'')+'">'+esc(String(t.status||'unknown').replaceAll('_',' '))+'</span></div>').join('')
 +(!tour.searchResults.length?'<p class="p2kw-notice">No tournament matches this search.</p>':'')
 +'<div class="p2kw-widget-nav"><button class="p2kw-mini-button" id="p2kwTourSearchPrev" '+(tour.pagination.page<=1?'disabled':'')+'>Previous</button><span>Page '+tour.pagination.page+' / '+tour.pagination.pages+' · '+tour.pagination.total+' matches</span><button class="p2kw-mini-button" id="p2kwTourSearchNext" '+(tour.pagination.page>=tour.pagination.pages?'disabled':'')+'>Next</button></div>':'');
 main.querySelector('#p2kwTournamentSearch').onsubmit=e=>{e.preventDefault();tour.search=main.querySelector('#p2kwTournamentInput').value.trim();tour.searchPage=1;tour.selectedSearch=null;void fetchTournaments()};
 main.querySelector('#p2kwPrevT').onclick=()=>{tour.selectedSearch=null;tour.completedPage=Math.max(1,tour.completedPage-1);tour.medalPage=1;void fetchTournaments()};
 main.querySelector('#p2kwNextT').onclick=()=>{tour.selectedSearch=null;tour.completedPage=Math.min(tour.completedPagination.pages,tour.completedPage+1);tour.medalPage=1;void fetchTournaments()};
 main.querySelector('#p2kwMedalPrev').onclick=()=>{tour.medalPage--;renderTour()};
 main.querySelector('#p2kwMedalNext').onclick=()=>{tour.medalPage++;renderTour()};
 main.querySelector('#p2kwClearSearch')?.addEventListener('click',()=>{tour.search='';tour.searchKind='tournament';tour.selectedSearch=null;tour.searchResults=[];tour.searchPage=1;tour.completedPage=1;void fetchTournaments()});
 main.querySelector('#p2kwTourSearchPrev')?.addEventListener('click',()=>{tour.searchPage--;void fetchTournaments()});
 main.querySelector('#p2kwTourSearchNext')?.addEventListener('click',()=>{tour.searchPage++;void fetchTournaments()});
 main.querySelectorAll('[data-tour-index]').forEach(b=>b.onclick=()=>{const selected=tour.searchResults[Number(b.dataset.tourIndex)];if(!selected)return;tour.selectedSearch=selected;tour.medalPage=1;renderTour()});
 notify();
}
async function fetchTournaments(){
 const epoch=++tour.epoch;setStatus('Loading…');
 try{const response=await get(SPOT,{action:'tournament-highlights',search:tour.search,page:tour.searchPage,completed_page:tour.completedPage},24000);if(epoch!==tour.epoch)return;
 tour.completed=response.completed||null;
 tour.completedPagination=response.completed_pagination||{page:1,pages:1,total:0};
 tour.completedPage=tour.completedPagination.page;
 // Only the currently selected completed tournament is kept in memory.
 tour.searchResults=Array.isArray(response.matches)?response.matches:[];tour.searchKind=response.search_kind==='player'?'player':'tournament';tour.pagination=response.pagination||{page:1,pages:1,total:0};tour.searchPage=tour.pagination.page;setStatus('LIVE');setFoot('');renderTour();
 }catch(error){if(epoch!==tour.epoch)return;setStatus('Unavailable');if(tour.completed){setFoot('');renderTour()}else announce('Tournament archive unavailable: '+(error.message||error))}
}
(async()=>{try{const response=await get(SPOT,{action:'config',widget:id});config=response.settings||{};if(!config.enabled&&document.body.dataset.preview!=='1'){announce('Widget not published');return}
 if(id==='player-spotlight'||isHall)await loadPlayerHome();else await fetchTournaments();
 }catch(error){setStatus('Unavailable');announce(error.message||'Unable to load widget configuration')}})();
})();