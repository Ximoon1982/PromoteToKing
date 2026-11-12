/* P2K v2.15.9 — additive compact Player Spotlight / Tournament Winners embeds.
 * All scores, rank positions, aliases and excluded medalists come from existing
 * canonical public services. No raw source scraping or independently scored data.
 */
(()=>{"use strict";
const id=document.body.dataset.widget;
if(!['player-spotlight','tournament-winners'].includes(id))return;
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
 if(!data?.found||!data.daily){main.innerHTML=playerHeader()+'<p class="p2kw-empty">No current member matched that name.</p><button class="p2kw-mini-button" id="p2kwBackTop">Show top five</button>';bindPlayerSearch();main.querySelector('#p2kwBackTop').onclick=()=>{currentUser='';void loadPlayerHome()};return}
 const daily=data.daily,live=data.live,medals=data.tournaments||{},user=data.username||daily.username||currentUser;
 const pos=periodData||{};
 const tab='<nav class="p2kw-seg" aria-label="Player scoring"><button type="button" data-scoretab="daily" aria-pressed="'+(activePlayerTab==='daily')+'">Daily</button><button type="button" data-scoretab="arena" aria-pressed="'+(activePlayerTab==='arena')+'">Arenas</button></nav>';
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
       +row('Longest arena participation streak',p.streaks?.longest_participation_streak??'—')
       +row('Current arena participation streak',p.streaks?.current_participation_streak??'—');
   }
 }
 const tUrl=new URL('Tournaments.html',ROOT);tUrl.searchParams.set('player',user);tUrl.searchParams.set('panel','tournaments');
 main.innerHTML=playerHeader()+'<div class="p2kw-subhead"><span>'+esc(user)+'</span><button id="p2kwTopButton" type="button" class="p2kw-mini-button">Top 5</button></div>'
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
 for(const [tag,extra] of [['overall',{evolution:'1m'}],['month',{start:p.month,end:p.end}],['quarter',{start:p.quarter,end:p.end}],['last30',{start:p.last30,end:p.end}]]){
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
 // All-time leader search uses canonical, identity-reconciled MCA Results.
 // Do not infer participation streaks from individual CSV filenames in the browser.
 try{
   const history=await get(ARENAS,{section:'leaders',filter:'current',search:user,sort:'points',direction:'desc'},24000);
   if(epoch!==playerEpoch)return;
   const detail=(Array.isArray(history.leaders)?history.leaders:[]).find(x=>key(x.username)===key(user));
   arenaPeriods.streaks=detail?{
     longest_participation_streak:detail.longest_participation_streak??null,
     current_participation_streak:detail.current_participation_streak??null
   }:null;
 }catch(error){if(epoch!==playerEpoch)return;console.warn('P2K widget MCA participation streaks unavailable',error);arenaPeriods.streaks=null}
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
 const epoch=++playerEpoch;playerData=null;periodData={};arenaPeriods=null;activePlayerTab='daily';setStatus('Loading…');
 try{const response=await get(SPOT,{action:'spotlight-leaders'});if(epoch!==playerEpoch)return;setStatus('LIVE');setFoot('');playerHome(response)}
 catch(error){setStatus('Unavailable');announce('Player leaderboards are temporarily unavailable: '+(error.message||error))}
}
const tour={recent:[],selected:0,medalPage:1,search:'',searchKind:'tournament',searchPage:1,searchResults:[],pagination:{page:1,pages:1,total:0},epoch:0};
function podiumRows(t){const p=t?.podium||{},out=[];for(const [type,symbol] of [['gold','🥇'],['silver','🥈'],['bronze','🥉']])for(const item of Array.isArray(p[type])?p[type]:[]){const name=typeof item==='string'?item:String(item?.username||item?.name||'');if(name.trim())out.push({name,type,symbol})}return out}
function renderTour(){
 const active=tour.recent[tour.selected],items=podiumRows(active),size=clamped(config.medalPageSize||3,1,20),pages=Math.max(1,Math.ceil(items.length/size)),page=Math.min(pages,Math.max(1,tour.medalPage));tour.medalPage=page;
 const pageItems=items.slice((page-1)*size,page*size);
 const link=safeLink(active?.webUrl)||TOURNAMENT_PAGE,title=active?.name||'No finished tournament';
 main.innerHTML='<form class="p2kw-search" id="p2kwTournamentSearch"><input type="search" id="p2kwTournamentInput" maxlength="80" placeholder="Tournament or medalist" aria-label="Search tournament or medalist" value="'+esc(tour.search)+'"><button type="submit">Search</button></form>'
 +'<div class="p2kw-widget-nav"><button class="p2kw-mini-button" id="p2kwPrevT" '+(tour.selected<=0?'disabled':'')+'>◀</button><span>Completed '+(tour.recent.length?tour.selected+1:0)+' / '+tour.recent.length+'</span><button class="p2kw-mini-button" id="p2kwNextT" '+(tour.selected>=tour.recent.length-1?'disabled':'')+'>▶</button></div>'
 +(active?'<div class="p2kw-subhead"><a class="p2kw-title-link" href="'+esc(link)+'" target="_blank" rel="noopener noreferrer">'+esc(title)+'</a><span class="p2kw-status-chip is-finished">Finished</span></div>':'<p class="p2kw-empty">No finished tournament is available.</p>')
 +'<div class="p2kw-subhead"><span>Medalists</span><span class="p2kw-small">'+items.length+' awarded positions</span></div>'
 +(pageItems.length?pageItems.map(x=>'<div class="p2kw-entry p2kw-medalist-row"><span class="p2kw-medal-symbol">'+x.symbol+'</span><div class="p2kw-entry-main"><strong>'+esc(x.name)+' <span class="p2kw-medal-inline">'+esc(x.type)+' medal</span></strong></div></div>').join(''):'<p class="p2kw-notice">No published medalist for this tournament.</p>')
 +'<div class="p2kw-widget-nav"><button class="p2kw-mini-button" id="p2kwMedalPrev" '+(page<=1?'disabled':'')+'>Previous</button><span>Medal page '+page+' / '+pages+'</span><button class="p2kw-mini-button" id="p2kwMedalNext" '+(page>=pages?'disabled':'')+'>Next</button></div>'
 +(tour.search?'<div class="p2kw-subhead"><span>'+(tour.searchKind==='player'?'Medaled tournaments':'Tournament results')+'</span><button class="p2kw-mini-button" id="p2kwClearSearch">Clear</button></div>'
 +tour.searchResults.map((t,i)=>'<div class="p2kw-entry"><div class="p2kw-entry-main"><button class="p2kw-player-link" type="button" data-tour-index="'+i+'">'+esc(t.name)+'</button>'+(Array.isArray(t.matched_medalists)&&t.matched_medalists.length?'<small class="p2kw-medal-match">'+t.matched_medalists.map(m=>(m.medal==='gold'?'🥇':m.medal==='silver'?'🥈':'🥉')+' '+esc(m.username)).join(' · ')+'</small>':'')+'</div><span class="p2kw-status-chip '+(key(t.status)==='finished'?'is-finished':key(t.status).includes('progress')?'is-progress':'')+'">'+esc(String(t.status||'unknown').replaceAll('_',' '))+'</span></div>').join('')
 +(!tour.searchResults.length?'<p class="p2kw-notice">No tournament matches this search.</p>':'')
 +'<div class="p2kw-widget-nav"><button class="p2kw-mini-button" id="p2kwTourSearchPrev" '+(tour.pagination.page<=1?'disabled':'')+'>Previous</button><span>Page '+tour.pagination.page+' / '+tour.pagination.pages+' · '+tour.pagination.total+' matches</span><button class="p2kw-mini-button" id="p2kwTourSearchNext" '+(tour.pagination.page>=tour.pagination.pages?'disabled':'')+'>Next</button></div>':'');
 main.querySelector('#p2kwTournamentSearch').onsubmit=e=>{e.preventDefault();tour.search=main.querySelector('#p2kwTournamentInput').value.trim();tour.searchPage=1;void fetchTournaments()};
 main.querySelector('#p2kwPrevT').onclick=()=>{tour.selected=Math.max(0,tour.selected-1);tour.medalPage=1;renderTour()};
 main.querySelector('#p2kwNextT').onclick=()=>{tour.selected=Math.min(tour.recent.length-1,tour.selected+1);tour.medalPage=1;renderTour()};
 main.querySelector('#p2kwMedalPrev').onclick=()=>{tour.medalPage--;renderTour()};
 main.querySelector('#p2kwMedalNext').onclick=()=>{tour.medalPage++;renderTour()};
 main.querySelector('#p2kwClearSearch')?.addEventListener('click',()=>{tour.search='';tour.searchResults=[];tour.pagination={page:1,pages:1,total:0};renderTour()});
 main.querySelector('#p2kwTourSearchPrev')?.addEventListener('click',()=>{tour.searchPage--;void fetchTournaments()});
 main.querySelector('#p2kwTourSearchNext')?.addEventListener('click',()=>{tour.searchPage++;void fetchTournaments()});
 main.querySelectorAll('[data-tour-index]').forEach(b=>b.onclick=()=>{const selected=tour.searchResults[Number(b.dataset.tourIndex)];if(!selected)return;tour.recent=[selected,...tour.recent.filter(x=>x.slug!==selected.slug||x.name!==selected.name)].slice(0,Math.max(5,config.limit||5));tour.selected=0;tour.medalPage=1;renderTour()});
 notify();
}
async function fetchTournaments(){
 const epoch=++tour.epoch;setStatus('Loading…');
 try{const response=await get(SPOT,{action:'tournament-highlights',search:tour.search,page:tour.searchPage},24000);if(epoch!==tour.epoch)return;
 const recent=Array.isArray(response.recent)?response.recent:[];
 if(!tour.recent.length||!tour.search) {tour.recent=recent.slice(0,Math.min(5,config.limit||5));tour.selected=Math.min(tour.selected,Math.max(0,tour.recent.length-1));}
 tour.searchResults=Array.isArray(response.matches)?response.matches:[];tour.searchKind=response.search_kind==='player'?'player':'tournament';tour.pagination=response.pagination||{page:1,pages:1,total:0};tour.searchPage=tour.pagination.page;setStatus('LIVE');setFoot('');renderTour();
 }catch(error){if(epoch!==tour.epoch)return;setStatus('Unavailable');if(tour.recent.length){setFoot('');renderTour()}else announce('Tournament archive unavailable: '+(error.message||error))}
}
(async()=>{try{const response=await get(SPOT,{action:'config',widget:id});config=response.settings||{};if(!config.enabled&&document.body.dataset.preview!=='1'){announce('Widget not published');return}
 if(id==='player-spotlight')await loadPlayerHome();else await fetchTournaments();
 }catch(error){setStatus('Unavailable');announce(error.message||'Unable to load widget configuration')}})();
})();