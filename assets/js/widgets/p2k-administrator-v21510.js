/* P2K 2.15.10 Administrator widget: same-origin admin services, no duplicate data store. */
(()=>{'use strict';
const $=id=>document.getElementById(id);
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#039;'}[c]));
const root=new URL('../../../',document.currentScript.src);
const url=p=>new URL(p,root).href;
const paths={
 actions:'server/team-points/public/intelligence.php?scope=freshness',
 members:'server/team-points-green/public/api.php',
 showcase:'server/events-showcase/public/api.php',
 admin:'server/events-showcase/public/admin-api.php',
 matches:'server/widgets/public/administrator.php?action=matches'
};
const state={view:'actions',metric:'matches',days:[],selectedDay:null,actions:[],memberPage:1,memberPages:1,showcase:null,discovery:null,sub:'manage',editing:-1,scanBusy:false,scanCancel:false};
async function get(target){const r=await fetch(url(target),{credentials:'same-origin',cache:'no-store'});const data=await r.json().catch(()=>null);if(!r.ok||!data||data.ok===false)throw new Error(data?.error?.message||data?.error||'HTTP '+r.status);return data;}
function message(id,text,error=false){const el=$(id);if(el){el.textContent=text;el.classList.toggle('aw-error',error);}}
function safeLink(raw){try{const u=new URL(String(raw),root);return u.protocol==='https:'&&['www.promotetoking.org','promotetoking.org','www.chess.com','chess.com'].includes(u.hostname)?u.href:'';}catch{return '';}}
function safeArenaLink(raw){try{const u=new URL(String(raw));return u.protocol==='https:'&&['www.chess.com','chess.com'].includes(u.hostname)&&/^\/(?:play\/arena|tournament\/live\/arena)\/\d+\/?$/.test(u.pathname)?u.href:'';}catch{return '';}}
function selectTab(view){if(!['actions','matches','members','showcase'].includes(view))return;state.view=view;document.querySelectorAll('[data-aw-tab]').forEach(b=>b.setAttribute('aria-selected',String(b.dataset.awTab===view)));document.querySelectorAll('[data-aw-view]').forEach(v=>v.hidden=v.dataset.awView!==view);$('awScroll').scrollTop=0;
 if(view==='actions'&&!state.actionPayload)void loadActions();
 if(view==='matches'&&!state.days.length)void loadMatches();
 if(view==='members')void loadMembers();
 if(view==='showcase'&&!state.showcase)void loadShowcase();
}
document.querySelectorAll('[data-aw-tab]').forEach(b=>b.addEventListener('click',()=>selectTab(b.dataset.awTab)));
// The dashboard's Match Assistant is the authoritative source for its five
// recruitment metrics. The Green read below is a clearly marked DB-only fallback
// for authenticated standalone widget embeds without a parent dashboard scan.
const openedActionGroups=new Set();
function actionHref(row){
 const matchId=Number(row.matchId||row.match_id||0);
 if(matchId>0){
   const p=new URL('ui-v2.html',root);p.searchParams.set('ui','v2');
   p.searchParams.set('page','administration');p.searchParams.set('adminTool','open');
   p.searchParams.set('club','promote-to-king');p.searchParams.set('scope','promote-to-king');
   p.searchParams.set('match',String(row.apiUrl||row.url||'https://www.chess.com/club/matches/'+matchId));
   return p.href;
 }
 return safeLink(row.url);
}
function dashboardSnapshot(){
 if(window.parent===window)return Promise.resolve(null);
 return new Promise(resolve=>{
   const nonce=String(Date.now())+'-'+Math.random().toString(36).slice(2);
   let completed=false;
   function finish(value){if(completed)return;completed=true;window.removeEventListener('message',handler);clearTimeout(timer);resolve(value);}
   function handler(e){if(e.origin!==location.origin||e.source!==window.parent||
       e.data?.type!=='p2k-administrator-widget-queue-response'||e.data.nonce!==nonce)return;
       finish(e.data);}
   window.addEventListener('message',handler);
   const timer=setTimeout(()=>finish(null),900);
   window.parent.postMessage({type:'p2k-administrator-widget-queue-request',nonce},location.origin);
 });
}
function greenQueue(catalog){
 const matches=Array.isArray(catalog?.matches)?catalog.matches:[];
 const details=new Map((catalog?.details||[]).map(d=>[String(d.matchId),d]));
 const now=Date.now()/1000;
 const rows=matches.map(m=>{
   const d=details.get(String(m.matchId))||{};
   const start=Number(m.startTime||0);
   const hours=start>0?Math.max(0,Math.round((start-now)/3600)):null;
   const within48=start>=now&&start<=now+48*3600;
   const within7=start>=now&&start<=now+7*86400;
   const ours=Math.max(0,Number(d.oursCount||0)),opponents=Math.max(0,Number(d.opponentCount||0));
   const min=Math.max(0,Number(d.minimumPlayers||0)),max=Number(d.maximumPlayers||0);
   const ratingMin=Math.max(0,Number(d.minRating||0)),ratingMax=Number(d.maxRating||0);
   const eligible=Array.isArray(d.oursRatings)?d.oursRatings.filter(v=>Number(v)>=ratingMin&&(!ratingMax||Number(v)<=ratingMax)).length:0;
   const verified=d.verified===true&&d.rosterComplete===true;
   // Missing rosters must never be interpreted as 0 registered players.
   const underfilled=verified&&min>0&&eligible<min&&within7;
   const league=m.isLeague===true||m.category==='league';
   const recruits=verified?Math.max(0,Math.min(max>0?max:Infinity,Math.max(min,opponents))-ours):0;
   const needsRecruitment=verified&&d.locked!==true&&(underfilled||(league&&recruits>0));
   const lockable=verified&&d.locked!==true&&min>0&&ours>=min&&ours>opponents;
   const tone=(underfilled&&within48)||(league&&recruits>0&&hours!==null&&hours<=24)?'bad':underfilled||needsRecruitment||within48?'warn':'info';
   const desc=[];
   if(underfilled)desc.push('Below minimum by '+(min-eligible));
   if(recruits>0&&verified)desc.push(recruits+' recruit(s) advised');
   if(hours!==null&&hours<=72)desc.push('Starts in '+hours+' h');
   if(!verified)desc.push('Roster snapshot incomplete');
   return {matchId:Number(m.matchId),title:m.name||'Team match',name:m.name||'Team match',
      detail:desc.join(' · ')||'Registration review',url:m.url||'',startTime:start,
      league,belowMinimumWarning:underfilled,startsWithin48:within48,
      needsRecruitment,lockable,recruits,winProbability:null,
      rosterComplete:verified,oursCount:verified?ours:null,opponentCount:verified?opponents:null,
      minimumPlayers:min,tone,action:needsRecruitment?'Recruit':'Review'};
 }).filter(m=>m.startTime>=now);
 const metric={underfilled:rows.filter(r=>r.belowMinimumWarning).length,
   starts48:rows.filter(r=>r.startsWithin48).length,
   leagueRecruitment:rows.filter(r=>r.league&&r.needsRecruitment).length,
   recruitsAdvised:rows.filter(r=>r.league&&r.needsRecruitment).reduce((n,r)=>n+r.recruits,0)};
 const score=r=>(r.tone==='bad'?300:r.tone==='warn'?180:60)+(r.league?80:0)
   +Math.min(80,r.recruits*8)+(r.belowMinimumWarning?100:0);
 return {source:'Green synchronized fallback (dashboard scan unavailable)',generatedAt:catalog?.generatedAt,
   rows,queue:rows.filter(r=>r.belowMinimumWarning||r.startsWithin48||r.needsRecruitment||r.lockable)
     .sort((a,b)=>score(b)-score(a)||a.startTime-b.startTime).slice(0,6),metrics:metric};
}
function fallbackHealth(fresh,runtime){
 const green=fresh?.data?.green||{},matches=green.matches||{},roster=green.roster||{};
 const health=[];
 const push=(name,age,target,path)=>{
   if(age===null||age===undefined){health.push({name,detail:'No Green timestamp recorded',tone:'warn',url:path});return;}
   const minutes=Math.round(Number(age)/60);
   const tone=age>3600?'bad':age>900?'warn':'good';
   health.push({name,detail:'Last verified '+minutes+' min ago · target '+Math.round(target/60)+' min',tone,url:path});
 };
 const maintenance='ui-v2.html?ui=v2&page=administration&adminTool=tasks';
 push('Green club match index',matches.index_age_seconds,green.targets?.club_index_seconds||300,maintenance);
 push('Green member roster',roster.age_seconds,green.targets?.roster_seconds||600,maintenance);
 if(runtime){
   const st=runtime.state||{},healthy=runtime.healthy===true;
   health.push({name:'Green Team Points worker',tone:healthy?'good':'warn',
      detail:'Cycle #'+(st.cycle_no??'—')+' · '+String(st.mode||'—')+' / '+String(st.stage||'—'),
      url:maintenance});
 }else health.push({name:'Green Team Points worker',detail:'Runtime health unavailable',tone:'warn',url:maintenance});
 return health;
}
async function loadActions(){
 message('awActions','Reading dashboard recruitment queue and Green health…');
 const [snapshot,radar,recent,fresh,runtime]=await Promise.all([
    dashboardSnapshot(),
    get('server/widgets/public/api.php?action=radar-catalog').catch(()=>null),
    get('server/team-points/public/recent-matches.php?hours=24').catch(()=>null),
    get('server/team-points/public/intelligence.php?scope=freshness').catch(()=>null),
    get('server/team-points-green/public/api.php?action=runtime-health').catch(()=>null)
 ]);
 state.actionPayload=snapshot?.queue&&Array.isArray(snapshot.queue.rows)
     ?{...snapshot.queue,source:'Dashboard Match Assistant'}:greenQueue(radar);
 state.actionHealth=Array.isArray(snapshot?.health)&&snapshot.health.length
     ?snapshot.health:fallbackHealth(fresh,runtime);
 state.recentMatches=Array.isArray(snapshot?.recent)?snapshot.recent:
     Array.isArray(recent?.matches)?recent.matches:null;
 state.actionFailures=Math.max(0,Number(snapshot?.failures||0));
 state.actions=state.actionPayload.queue||[];
 renderActions();
}
function actionItem(row,index){
 const name=String(row.title||row.name||'Team match');
 const desc=String(row.detail||row.status||'');
 const players=row.oursCount===null||row.oursCount===undefined?'':' · '+row.oursCount+' P2K players';
 return '<div class="aw-row"><div class="aw-info"><strong>'+esc(name)+'</strong>'+
   '<small>'+esc(desc+players)+'</small></div><button type="button" class="aw-btn" data-action="'+index+'">Open</button></div>';
}
function actionSections(){
 const data=state.actionPayload||{},rows=Array.isArray(data.rows)?data.rows:[],health=state.actionHealth||[];
 const groups=[
   {id:'underfilled',title:'Below minimum',items:rows.filter(r=>r.belowMinimumWarning)},
   {id:'starts48',title:'Start within 48 h',items:rows.filter(r=>r.startsWithin48)},
   {id:'league',title:'League recruitment',items:rows.filter(r=>r.league&&r.needsRecruitment)},
   {id:'exceptions',title:'Operational exceptions',items:health.filter(h=>h.tone!=='good')},
   {id:'new24',title:'New matches · 24 h',items:state.recentMatches||[]}
 ];
 const expandedList=(g)=>{
   if(g.id==='exceptions')return g.items.map(h=>'<div class="aw-row"><div class="aw-info"><strong>'+esc(h.name)+'</strong><small>'+esc(h.detail)+'</small></div>'+ (safeLink(h.url)?'<a class="aw-btn" href="'+esc(safeLink(h.url))+'" target="_blank" rel="noopener">Open</a>':'')+'</div>').join('')||'<p class="aw-note">No current operational exceptions.</p>';
   return g.items.map(r=>'<div class="aw-row"><div class="aw-info"><strong>'+esc(r.title||r.name||'Match #'+(r.matchId||r.match_id))+'</strong><small>'+esc(r.detail||(r.first_discovered_at?'Discovered '+formatWhen(r.first_discovered_at):''))+'</small></div><a class="aw-btn" href="'+esc(actionHref(r))+'" target="_blank" rel="noopener">Open</a></div>').join('')||'<p class="aw-note">No matches.</p>';
 };
 return groups.map(g=>'<details class="aw-fold aw-action-fold" data-aw-group="'+g.id+'" '+(openedActionGroups.has(g.id)?'open':'')+'><summary>'+esc(g.title)+' <strong>'+g.items.length+'</strong></summary><div>'+expandedList(g)+'</div></details>').join('');
}
function renderActions(){
 const list=$('awActions');$('awActionDetail').hidden=true;list.hidden=false;
 const payload=state.actionPayload||{},health=state.actionHealth||[],queue=state.actions||[];
 const source=String(payload.source||'Green synchronized fallback');
 list.innerHTML='<p class="aw-muted">Source: '+esc(source)+'</p>'+actionSections()+
 '<div class="aw-heading"><strong>Highest-priority actions</strong><span class="aw-muted">'+queue.length+'</span></div>'+
 (queue.length?queue.map(actionItem).join(''):'<p class="aw-note">No immediate recruitment intervention.</p>')+
 '<div class="aw-heading"><strong>System health</strong><span class="aw-muted">'+health.filter(h=>h.tone!=='good').length+' exception(s)</span></div>'+
 (health.length?health.map(h=>'<div class="aw-row"><span class="aw-dot aw-'+esc(h.tone||'warn')+'"></span><div class="aw-info"><strong>'+esc(h.name)+'</strong><small>'+esc(h.detail)+'</small></div></div>').join(''):'<p class="aw-note">Health data unavailable.</p>');
 list.querySelectorAll('[data-aw-group]').forEach(d=>d.addEventListener('toggle',()=>{if(d.open)openedActionGroups.add(d.dataset.awGroup);else openedActionGroups.delete(d.dataset.awGroup);}));
 list.querySelectorAll('[data-action]').forEach(b=>b.onclick=()=>openAction(Number(b.dataset.action)));
}
function openAction(index){
 const a=state.actions[index];if(!a)return;
 const href=safeLink(actionHref(a)),host=$('awActionDetail');
 host.innerHTML='<div class="aw-detail"><button type="button" class="aw-btn" id="awBackAction">← Back to queue</button>'+
 '<div class="aw-heading"><strong>'+esc(a.title||a.name||'Match')+'</strong></div><p>'+esc(a.detail||'Registration review')+'</p>'+
 '<p class="aw-muted">Priority: '+esc(a.tone||'info')+'</p>'+
 (a.winProbability===null||a.winProbability===undefined?'':'<p>Win probability: '+esc(a.winProbability)+'%</p>')+
 (href?'<a class="aw-btn aw-primary" target="_blank" rel="noopener" href="'+esc(href)+'">Open match tool ↗</a>':'')+'</div>';
 $('awActions').hidden=true;host.hidden=false;$('awBackAction').onclick=renderActions;
 $('awScroll').scrollTop=0;
}
$('awRefreshActions').onclick=()=>void loadActions();
async function loadMatches(){
 message('awChartCaption','Loading synchronized Green match schedule…');
 try{const d=await get(paths.matches);state.days=Array.isArray(d.days)?d.days:[];drawBars();}
 catch(e){message('awChartCaption',e.message,true);}
}
function renderDayMatches(){
 const day=state.days[state.selectedDay];
 if(!day){$('awDayMatches').innerHTML='';return;}
 const rows=Array.isArray(day.items)?day.items:[];
 $('awDayMatches').innerHTML='<div class="aw-heading"><strong>'+esc(day.date)+'</strong><span class="aw-muted">'+day.matches+' matches · '+day.boards+' boards</span></div>'+
 (rows.length?rows.map(r=>'<div class="aw-row"><div class="aw-info"><strong>'+esc(r.name)+'</strong><small>'+esc(r.opponent)+' · '+new Date(r.startTime*1000).toLocaleTimeString('en-GB',{timeZone:'Europe/Luxembourg',hour:'2-digit',minute:'2-digit'})+'</small><small>'+
 (r.rosterComplete?esc(r.oursCount+' P2K / '+r.opponentCount+' opponent players'):'Roster not yet verified')+
 '</small></div><a class="aw-btn" target="_blank" rel="noopener" href="'+esc(r.url)+'">Open</a></div>').join(''):'<p class="aw-note">No upcoming starts on this day.</p>');
}
function drawBars(){
 const metric=state.metric,values=state.days.map(d=>Number(d[metric]||0)),max=Math.max(1,...values);
 $('awBars').innerHTML=state.days.map((d,i)=>{
   const height=values[i]===0?2:Math.max(3,Math.round(values[i]/max*78));
   return '<div class="aw-bar-wrap"><span class="aw-bar-count">'+values[i]+'</span>'+
   '<button type="button" class="aw-bar aw-bar-'+metric+(state.selectedDay===i?' aw-selected':'')+'" data-day="'+i+'" aria-label="'+esc(d.date)+': '+values[i]+' '+metric+'" style="height:'+height+'px"></button>'+
   '<small>'+esc(d.date.slice(5))+'</small></div>';
 }).join('');
 message('awChartCaption','Future registered starts · '+values.reduce((a,b)=>a+b,0)+' '+metric+' · local dates');
 $('awBars').querySelectorAll('[data-day]').forEach(b=>b.onclick=()=>{
   state.selectedDay=Number(b.dataset.day);drawBars();renderDayMatches();
 });
 renderDayMatches();
}
document.querySelectorAll('[data-aw-metric]').forEach(b=>b.onclick=()=>{
 state.metric=b.dataset.awMetric;
 document.querySelectorAll('[data-aw-metric]').forEach(x=>x.setAttribute('aria-pressed',String(x===b)));
 drawBars();
});
$('awRefreshMatches').onclick=()=>void loadMatches();
function formatWhen(raw){
 if(raw===null||raw===undefined||raw==='')return '—';
 const input=String(raw).trim();
 const d=/^\d+$/.test(input)?new Date(Number(input)*1000):
   new Date(input.replace(' ','T')+(/[zZ]|[+-]\d\d:?\d\d$/.test(input)?'':'Z'));
 return Number.isFinite(d.getTime())?d.toLocaleString('en-GB',
   {dateStyle:'medium',timeStyle:'short',timeZone:'Europe/Luxembourg'}):input;
}
function readableReason(raw){
 const reason=String(raw||'').trim().toLowerCase();
 if(!reason)return '';
 if(/^http_40[4]$|^http_410$/.test(reason))return 'Account unavailable ('+reason.replace('_',' ').toUpperCase()+') — closure reason not confirmed';
 if(/fair.?play|cheat/.test(reason))return 'Fair play violation';
 if(/abuse/.test(reason))return 'Abuse';
 return reason.replace(/[_-]+/g,' ').replace(/^./,c=>c.toUpperCase());
}
function closureReason(event){
 if(!event)return '';
 const meta=event.metadata||{};
 const raw=meta.profile_closure_reason||meta.closure_reason||meta.closed_reason||meta.reason||'';
 return readableReason(raw);
}
function eventLabel(e){
 const type=String(e.event_type||'');
 if(type==='name_changed')return 'Name changed: '+String(e.previous_username||e.username||'Unknown')+
   ' → '+String(e.new_username||'Unknown')+(e.inferred||e.metadata?.inferred_from_alias?' (inferred from aliases)':'');
 if(type==='left'){
   const status=String(e.profile_status||'').toLowerCase();
   const profile=String(e.metadata?.profile_account_status||'').toLowerCase();
   return 'Left club'+(status==='closed'?' · account closed':status==='active'?' · account active':'')+
      (closureReason(e)?' · '+closureReason(e):'')+
      (profile&&profile!=='closed'&&profile!=='active'?' · '+profile:'');
 }
 return ({joined:'Joined club',rejoined:'Rejoined club',discovered:'First observed'})[type]||
   type.replace(/_/g,' ');
}
function closeMember(){
 $('awMemberResult').hidden=true;$('awMembersIndex').hidden=false;
 $('awScroll').scrollTop=state.memberScroll||0;
}
async function loadMembers(){
 message('awEvents','Loading membership chronology…');
 try{
   const d=await get(paths.members+'?action=member-events&page='+state.memberPage+'&per_page=10');
   state.memberPage=Number(d.pagination?.page||state.memberPage);
   state.memberPages=Math.max(1,Number(d.pagination?.pages||1));
   $('awEvents').innerHTML=(d.events||[]).map(e=>
     '<div class="aw-row"><div class="aw-info"><strong>'+esc(eventLabel(e))+'</strong>'+
     '<small>'+esc(e.new_username||e.username||'Unknown member')+' · '+esc(formatWhen(e.detected_at))+
     '</small></div><button class="aw-btn" data-member="'+esc(e.new_username||e.username||'')+'">View</button></div>'
   ).join('')||'<p class="aw-note">No events found.</p>';
   $('awEvents').querySelectorAll('[data-member]').forEach(b=>b.onclick=()=>{
     $('awMemberName').value=b.dataset.member;void lookupMember(b.dataset.member);
   });
   $('awEventPage').textContent=state.memberPage+' / '+state.memberPages;
   $('awEventPrev').disabled=state.memberPage<=1;
   $('awEventNext').disabled=state.memberPage>=state.memberPages;
 }catch(e){message('awEvents',e.message,true);}
}
$('awEventPrev').onclick=()=>{if(state.memberPage>1){state.memberPage--;void loadMembers();}};
$('awEventNext').onclick=()=>{if(state.memberPage<state.memberPages){state.memberPage++;void loadMembers();}};
function memberDossier(x,name){
 if(!x.found)return '<p class="aw-note">No Green member identity found for '+esc(name)+'.</p>';
 const m=x.member||{},a=x.activity||{},lc=x.lifecycle||{},events=Array.isArray(lc.events)?lc.events:[],
    aliases=Array.isArray(x.aliases)?x.aliases:[],lastLeft=lc.last_left||events.find(e=>e.event_type==='left'),
    closure=lc.last_closure||events.find(e=>e.event_type==='left'&&e.profile_status==='closed');
 const eventsOf=type=>events.filter(e=>e.event_type===type);
 const firstJoin=eventsOf('joined').at(-1)||eventsOf('rejoined').at(-1);
 const lastRejoin=eventsOf('rejoined')[0];
 const mostRecentChange=eventsOf('name_changed')[0];
 const accountClosed=String(m.account_status||'').toLowerCase().includes('closed')||Boolean(closure);
 const status=accountClosed?'Account closed':String(m.account_status||'').trim()||'Account status unavailable';
 const details=[
   ['Membership',m.current_member?'Current member':'Former member'],
   ['Account',status],
   ['First joined',m.joined_epoch?formatWhen(m.joined_epoch):firstJoin?formatWhen(firstJoin.detected_at)+' (observed)':'—'],
   ['Last rejoined',lastRejoin?formatWhen(lastRejoin.effective_at||lastRejoin.detected_at)+' (observed)':'—'],
   ['Last left / quit',m.left_at?formatWhen(m.left_at):lastLeft?formatWhen(lastLeft.effective_at||lastLeft.detected_at)+' (observed)':'—'],
   ['Closure reason',accountClosed?(closureReason(closure)||'Not provided by Chess.com'):'Not applicable'],
   ['Daily rating',m.daily_rating??'—'],
   ['Chess960 rating',m.chess960_rating??'—'],
   ['Daily games',a.games??'—'],
   ['W / D / L',String(a.wins??0)+' / '+String(a.draws??0)+' / '+String(a.losses??0)],
   ['Daily points',a.points??'—'],
   ['Registered / ongoing matches',String(a.registered_matches??0)+' / '+String(a.in_progress_matches??0)],
   ['Achievements',x.achievements??'—']
 ];
 const row=(label,value)=>'<div class="aw-dossier-row"><span>'+esc(label)+'</span><strong>'+esc(value)+'</strong></div>';
 const aliasesHtml=aliases.length?aliases.map(alias=>
    '<div class="aw-row"><div class="aw-info"><strong>'+esc(alias.username||alias.username_key||'—')+
    (alias.current?' · current':'')+'</strong><small>'+
    (alias.first_seen_at?'First seen '+esc(formatWhen(alias.first_seen_at))+' · ':'')+
    (alias.last_seen_at?'Last seen '+esc(formatWhen(alias.last_seen_at)):'')+
    '</small></div></div>').join(''):'<p class="aw-note">No additional known names.</p>';
 const recent=events.slice(0,30),older=events.slice(30);
 const timeline=rows=>rows.map(e=>'<div class="aw-row"><div class="aw-info"><strong>'+
    esc(eventLabel(e))+'</strong><small>'+esc(formatWhen(e.effective_at||e.detected_at))+
    (e.effective_at?'':' · observed')+'</small></div></div>').join('');
 return '<div class="aw-detail"><strong>'+esc(m.username||name)+'</strong>'+
   (mostRecentChange?'<p class="aw-note">Latest name change: '+esc(eventLabel(mostRecentChange))+'</p>':'')+
   '<div class="aw-dossier">'+details.map(([label,value])=>row(label,value)).join('')+'</div>'+
   '<div class="aw-heading"><strong>Known names ('+aliases.length+')</strong></div>'+aliasesHtml+
   '<div class="aw-heading"><strong>Membership chronology ('+events.length+')</strong></div>'+
   (events.length?timeline(recent)+(older.length?'<details class="aw-fold"><summary>Show '+older.length+' older events</summary>'+timeline(older)+'</details>':''):'<p class="aw-note">No recorded lifecycle events.</p>')+
   '</div>';
}
async function lookupMember(name){
 state.memberScroll=$('awScroll').scrollTop;
 $('awMembersIndex').hidden=true;$('awMemberResult').hidden=false;
 $('awMemberResult').innerHTML='<button type="button" class="aw-btn" id="awBackMember">← Back to Members</button><p class="aw-note">Loading member dossier…</p>';
 $('awBackMember').onclick=closeMember;$('awScroll').scrollTop=0;
 if(!/^[a-z0-9_-]{2,25}$/i.test(name)){
   $('awMemberResult').insertAdjacentHTML('beforeend','<p class="aw-error">Enter a valid Chess.com username.</p>');return;
 }
 try{
   const d=await get(paths.members+'?action=member-lookup&username='+encodeURIComponent(name));
   // The username and result are escaped before entering HTML.
   $('awMemberResult').innerHTML='<button type="button" class="aw-btn" id="awBackMember">← Back to Members</button>'+memberDossier(d.lookup||{},name);
   $('awBackMember').onclick=closeMember;
 }catch(e){
   $('awMemberResult').innerHTML='<button type="button" class="aw-btn" id="awBackMember">← Back to Members</button><p class="aw-error">'+esc(e.message)+'</p>';
   $('awBackMember').onclick=closeMember;
 }
}
$('awMemberForm').onsubmit=e=>{e.preventDefault();void lookupMember($('awMemberName').value.trim());};
function getWriteClient(){let c=window.P2K_TEAM_POINTS_CLIENT;try{if(!c&&window.parent!==window)c=window.parent.P2K_TEAM_POINTS_CLIENT;}catch{}if(!c?.endpointRequest)throw new Error('Admin write client unavailable in this embed. Open the widget from P2K administration.');return c;}
async function post(endpoint,action,body){const c=getWriteClient();if(c.connect)await c.connect();return c.endpointRequest(url(endpoint+'?action='+action),{method:'POST',body,requestKind:'events-showcase',timeoutMs:30000});}
async function saveShowcase(items,arenas){const c=getWriteClient();if(c.connect)await c.connect();const d=await c.endpointRequest(url(paths.showcase+'?action=save'),{method:'PUT',body:{revision:state.showcase.revision,items,arenas},requestKind:'events-showcase',timeoutMs:30000});state.showcase={...state.showcase,...d};renderShowcase();return d;}
async function loadShowcase(){try{const [s,d]=await Promise.all([get(paths.showcase+'?action=state'),get(paths.admin+'?action=config')]);state.showcase=s;state.discovery=d;renderShowcase();}catch(e){message('awShowcaseFeedback',e.message,true);}}
function renderShowcase(){if(!state.showcase||!state.discovery)return;renderOrganizers();renderScan();renderArenas();renderSelectedMatches();}
function renderOrganizers(){const org=state.discovery.organizers||[];message('awClubCount','('+org.length+')');$('awClubList').innerHTML=org.map((o,i)=>'<div class="aw-row"><input type="checkbox" class="aw-check" data-org-on="'+i+'" '+(o.enabled!==false?'checked':'')+'><div class="aw-info"><strong>'+esc(o.name)+'</strong><small>#'+esc(o.clubId)+'</small></div><button class="aw-btn" data-org-del="'+i+'">×</button></div>').join('');$('awClubList').querySelectorAll('[data-org-on]').forEach(b=>b.onchange=()=>void updateOrganizers(org.map((o,i)=>i===Number(b.dataset.orgOn)?{...o,enabled:b.checked}:o)));$('awClubList').querySelectorAll('[data-org-del]').forEach(b=>b.onclick=()=>void updateOrganizers(org.filter((_,i)=>i!==Number(b.dataset.orgDel))));}
async function updateOrganizers(next){if(state.scanBusy){message('awShowcaseFeedback','Cancel scan before editing clubs.',true);return;}try{const r=await post(paths.admin,'config',{revision:state.discovery.revision,organizers:next});state.discovery={...state.discovery,...r};renderShowcase();message('awShowcaseFeedback','Organizing clubs saved.');}catch(e){message('awShowcaseFeedback',e.message,true);}}
$('awClubForm').onsubmit=async e=>{e.preventDefault();const raw=$('awClubId').value.trim();if(!raw)return;const button=$('awClubForm').querySelector('button');button.disabled=true;message('awShowcaseFeedback','Resolving club identity…');try{const club=await get('server/widgets/public/administrator.php?action=resolve-club&input='+encodeURIComponent(raw));if(state.discovery.organizers.some(o=>String(o.clubId)===String(club.clubId)))throw new Error('This club is already monitored.');await updateOrganizers([...state.discovery.organizers,{clubId:String(club.clubId),name:String(club.name),enabled:true}]);$('awClubId').value='';if(!club.resolved)message('awShowcaseFeedback',club.message||'Club ID saved; name not yet verified.');}catch(error){message('awShowcaseFeedback',error.message,true);}finally{button.disabled=false;}};
function knownArena(id){return (state.showcase?.arenas||[]).some(a=>String(a.link||'').match(/\/(?:play\/arena|tournament\/live\/arena)\/(\d+)/)?.[1]===String(id));}
function renderScan(){const scan=state.discovery.scan;message('awScanStatus',scan?String(scan.status)+' · '+(scan.pages||0)+' pages · '+(scan.seen||0)+' inspected · '+(scan.results||[]).length+' candidates'+(scan.message?' · '+scan.message:''):'No scan yet.');$('awScan').disabled=state.scanBusy;$('awScan').textContent=scan?.status==='running'?'Resume arena scan':'Scan upcoming arenas';$('awCancelScan').hidden=!state.scanBusy&&scan?.status!=='running';
 const candidates=(scan?.results||[]).filter(a=>!knownArena(a.arenaId)&&Number(a.startEpoch)*1000>Date.now());$('awScanResults').innerHTML=candidates.map((a,i)=>'<div class="aw-row"><div class="aw-info"><strong>'+esc(a.name)+'</strong><small>'+esc(a.organizer)+' · '+esc(a.localDate)+' '+esc(a.localTime)+'</small></div><button class="aw-btn" data-prefill="'+i+'">Add</button></div>').join('');$('awScanResults').querySelectorAll('[data-prefill]').forEach(b=>b.onclick=()=>prefillArena(candidates[Number(b.dataset.prefill)]));}
async function scan(){if(state.scanBusy)return;state.scanBusy=true;state.scanCancel=false;renderScan();try{if(state.discovery.scan?.status!=='running'){const d=await post(paths.admin,'arena-scan-begin',{});state.discovery={...state.discovery,...d};}while(!state.scanCancel&&state.discovery.scan?.status==='running'){const sc=state.discovery.scan;const r=await post(paths.admin,'arena-scan-page',{scanId:sc.id,page:Number(sc.pages||0)+1});state.discovery.scan=r.scan;renderScan();}if(state.scanCancel&&state.discovery.scan?.status==='running'){const r=await post(paths.admin,'arena-scan-cancel',{scanId:state.discovery.scan.id});state.discovery.scan=r.scan;}message('awShowcaseFeedback','Arena scan '+(state.discovery.scan?.status||'finished')+'.');}catch(e){message('awShowcaseFeedback',e.message,true);try{const d=await get(paths.admin+'?action=config');state.discovery=d;}catch{}}finally{state.scanBusy=false;renderScan();}}
$('awScan').onclick=()=>void scan();$('awCancelScan').onclick=()=>{state.scanCancel=true;message('awShowcaseFeedback','Stopping after the current page…');};
function selectSub(sub){state.sub=sub;document.querySelectorAll('[data-aw-sub]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.awSub===sub)));document.querySelectorAll('[data-aw-panel]').forEach(p=>p.hidden=p.dataset.awPanel!==sub);}
document.querySelectorAll('[data-aw-sub]').forEach(b=>b.onclick=()=>selectSub(b.dataset.awSub));
function renderArenas(){$('awArenaList').innerHTML=(state.showcase.arenas||[]).map((a,i)=>'<div class="aw-row"><input type="checkbox" class="aw-check" data-arena-toggle="'+i+'" '+(a.active?'checked':'')+'><div class="aw-info"><strong>'+esc(a.name||'Arena')+'</strong><small>'+esc(a.date||'')+' '+esc(a.time||'')+'</small></div><button class="aw-btn" data-arena-edit="'+i+'">Edit</button></div>').join('')||'<p class="aw-note">No configured arenas.</p>';$('awArenaList').querySelectorAll('[data-arena-edit]').forEach(b=>b.onclick=()=>editArena(Number(b.dataset.arenaEdit)));$('awArenaList').querySelectorAll('[data-arena-toggle]').forEach(b=>b.onchange=()=>{const a=state.showcase.arenas.map(x=>({...x}));a[Number(b.dataset.arenaToggle)].active=b.checked;void saveShowcase(state.showcase.items,a).catch(e=>message('awShowcaseFeedback',e.message,true));});}
function renderSelectedMatches(){$('awMatchList').innerHTML=(state.showcase.items||[]).map((m,i)=>'<div class="aw-row"><input type="checkbox" class="aw-check" data-match-toggle="'+i+'" '+(m.enabled!==false?'checked':'')+'><div class="aw-info"><strong>Match #'+esc(m.matchId)+'</strong><small>'+esc(m.urgent?'Urgent':'Standard')+'</small></div><button class="aw-btn" data-match-remove="'+i+'">×</button></div>').join('')||'<p class="aw-note">No selected matches.</p>';$('awMatchList').querySelectorAll('[data-match-toggle]').forEach(b=>b.onchange=()=>{const items=state.showcase.items.map(x=>({...x}));items[Number(b.dataset.matchToggle)].enabled=b.checked;void saveShowcase(items,state.showcase.arenas).catch(e=>message('awShowcaseFeedback',e.message,true));});$('awMatchList').querySelectorAll('[data-match-remove]').forEach(b=>b.onclick=()=>{if(!confirm('Remove this match from Showcase?'))return;const items=state.showcase.items.filter((_,i)=>i!==Number(b.dataset.matchRemove));void saveShowcase(items,state.showcase.arenas).catch(e=>message('awShowcaseFeedback',e.message,true));});}
function editArena(index){state.editing=index;const a=state.showcase.arenas[index];$('awArenaName').value=a.name||'';$('awArenaLink').value=a.link||'';$('awArenaDate').value=a.date||'';$('awArenaTime').value=a.time||'';$('awArenaTc').value=a.timeControl||'';$('awArenaDuration').value=a.duration||'';message('awArenaFormTitle','Edit arena');refreshArenaLink();selectSub('add');}
function prefillArena(a){state.editing=-1;$('awArenaName').value=a?.name||'';$('awArenaLink').value=a?.url||'';$('awArenaDate').value=a?.localDate||'';$('awArenaTime').value=a?.localTime||'';$('awArenaTc').value='';$('awArenaDuration').value='';message('awArenaFormTitle','Add arena');refreshArenaLink();selectSub('add');}
function refreshArenaLink(){const link=safeArenaLink($('awArenaLink').value);$('awOpenArena').hidden=!link;if(link)$('awOpenArena').href=link;}
$('awArenaLink').oninput=refreshArenaLink;$('awArenaDiscard').onclick=()=>{state.editing=-1;selectSub('manage');};
$('awArenaForm').onsubmit=e=>{e.preventDefault();const name=$('awArenaName').value.trim(),link=safeArenaLink($('awArenaLink').value),date=$('awArenaDate').value,time=$('awArenaTime').value,duration=$('awArenaDuration').value.trim();if(!name||!link||!date||!time||!duration){message('awShowcaseFeedback','Complete required arena fields and use a Chess.com arena URL.',true);return;}const a={...(state.editing>=0?state.showcase.arenas[state.editing]:{}),name,link,date,time,timeControl:$('awArenaTc').value.trim(),duration,active:state.editing>=0?state.showcase.arenas[state.editing].active:false};const arr=state.showcase.arenas.map(x=>({...x}));if(state.editing>=0)arr[state.editing]=a;else arr.push(a);void saveShowcase(state.showcase.items,arr).then(()=>{state.editing=-1;selectSub('manage');message('awShowcaseFeedback','Arena saved.');}).catch(err=>message('awShowcaseFeedback',err.message,true));};
$('awReloadShowcase').onclick=()=>void loadShowcase();
selectTab('actions');
})();
