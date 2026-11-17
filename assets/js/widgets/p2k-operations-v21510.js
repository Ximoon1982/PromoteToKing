/* P2K v2.15.10 public Club Operations; strictly read-only, no protected endpoints. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const esc = v => String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#039;'}[c]));
  const root=new URL('../../../',document.currentScript.src);
  const url=path=>new URL(path,root).href;
  const api='server/widgets/public/operations-api.php';
  const state={view:'actions',days:[],metric:'matches',selectedDay:null,memberPage:1,memberPages:1,
    previousSuggestions:'',queryRevision:0,analyzedId:0,analysisRevision:0,history:[],current:null,recent:null};
  const core=()=>window.P2K_RECRUITMENT_LINEUP_CORE;
  const fmt=n=>Number(n||0).toLocaleString('en-GB');
  const when=value=>{if(!value)return '—';const d=new Date(typeof value==='number'?(value<1e11?value*1000:value):String(value));return Number.isFinite(d.getTime())?d.toLocaleString('en-GB',{timeZone:'Europe/Luxembourg',day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}):'—';};
  const text=(id,s)=>{const e=$(id);if(e)e.textContent=s;};
  const request=async(path,signal)=>{
    const response=await fetch(url(path),{credentials:'same-origin',cache:'no-store',signal});
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data||data.ok===false){
      const error=typeof data?.error==='string'?data.error:data?.error?.message;
      throw new Error(error||'Service temporarily unavailable (HTTP '+response.status+')');
    }return data;
  };
  const emit=(action)=>{try{window.P2K_TRAFFIC_ANALYTICS?.widgetAction?.(action);}catch{}};
  function selectTab(view){
    if(!['actions','matches','members','analysis'].includes(view))return;
    state.view=view;
    document.querySelectorAll('[data-aw-tab]').forEach(b=>{const active=b.dataset.awTab===view;b.setAttribute('aria-selected',String(active));b.tabIndex=active?0:-1;});
    document.querySelectorAll('[data-aw-view]').forEach(v=>v.hidden=v.dataset.awView!==view);
    $('awScroll').scrollTop=0;
    if(view==='actions'&&!state.days.length)void loadActions();
    if(view==='matches'&&!state.days.length)void loadMatches().catch(e=>text('awChartCaption',e.message));
    if(view==='members')void loadMembers();
  }
  document.querySelectorAll('[data-aw-tab]').forEach(b=>b.onclick=()=>selectTab(b.dataset.awTab));

  function isLeague(name){return /(?:^|[^a-z0-9])(?:1WL|TCMAC|KOTML|TMCL|WKCL|PCL|CW|WCGC)(?:[^a-z0-9]|$)/i.test(name||'');}
  function actionRows(){
    const now=Date.now()/1000;
    return state.days.flatMap(d=>d.items||[]).map(m=>{
      const hours=Math.round((m.startTime-now)/3600);
      const verified=!!m.verified&&!!m.rosterComplete;
      const under=verified&&m.minimumPlayers>0&&m.oursEligible<m.minimumPlayers;
      const recruit=verified&&!m.locked&&Math.max(0,Math.max(m.minimumPlayers||0,m.opponentCount)-m.oursCount);
      return {...m,hours,under,recruit,league:isLeague(m.name),
        detail:(under?'Below minimum · ':'')+(recruit?'Recruit +'+recruit+' · ':'')+(hours>=0?'Starts in '+hours+'h':'Starting')};
    }).filter(r=>r.hours>=-1);
  }
  const matchLink=m=>'<a class="aw-btn" target="_blank" rel="noopener noreferrer" href="'+esc(m.url)+'">Open</a>';
  function actionList(list,empty='No matching entries.'){
    return list.length?list.slice(0,32).map(m=>'<div class="aw-row"><div class="aw-info"><strong>'+esc(m.name)+'</strong><small>'+
      esc(m.opponent+' · '+m.detail)+'</small></div>'+matchLink(m)+'</div>').join(''):
      '<p class="aw-note">'+esc(empty)+'</p>';
  }
  function renderActions(){
    const rows=actionRows(),low=rows.filter(m=>m.under),starts=rows.filter(m=>m.hours<=48),
      league=rows.filter(m=>m.league&&m.recruit>0),priority=rows.filter(m=>m.recruit>0).sort((a,b)=>a.hours-b.hours);
    const exceptions=rows.filter(m=>!m.rosterComplete||!m.verified);
    const recent=Array.isArray(state.recent)?state.recent:[];
    $('awActions').innerHTML=
      '<p class="aw-muted">Public Green match registrations · club-wide, not personalized</p>'+
      [
        ['Below minimum',low],['Start within 48 h',starts],
        ['League recruitment',league],['Operational exceptions (incomplete roster)',exceptions],
        ['New matches · 24 h',recent]
      ].map(([label,items],i)=>'<details class="aw-fold" '+(i===0?'open':'')+'><summary>'+esc(label)+' <strong>'+fmt(items.length)+'</strong></summary>'+
        actionList(items)+'</details>').join('')+
      '<div class="aw-heading"><strong>Highest priority</strong><span class="aw-muted">'+fmt(priority.length)+'</span></div>'+
      actionList(priority,'No immediate recruitment needs.')+
      '<div class="aw-heading"><strong>System health indicators</strong></div>'+
      '<div class="aw-row"><div class="aw-info"><strong>Verified registration index</strong>'+
      '<small>'+esc('Snapshot: '+(state.lastFetch||'—')+' · '+rows.length+' upcoming matches')+'</small></div></div>'+
      '<div class="aw-row"><div class="aw-info"><strong>Roster coverage</strong>'+
      '<small>'+esc(exceptions.length+' upcoming matches pending full verification')+'</small></div></div>'+
      '<p class="aw-muted">Public indicators only. Detailed worker diagnostics remain protected in P2K administration.</p>';
  }
  async function loadActions(){
    text('awActions','Loading public Green match data…');
    try{
      if(!state.days.length)await loadMatches();
      if(!state.recent){
        const d=await request('server/team-points/public/recent-matches.php?hours=24').catch(()=>({matches:[]}));
        state.recent=(d.matches||[]).slice(0,30).filter(x=>Number.isInteger(Number(x.match_id))&&Number(x.match_id)>0).map(x=>({
          name:String(x.name||'Match #'+x.match_id),opponent:String(x.opponent_name||'Opponent'),
          url:'https://www.chess.com/club/matches/'+Number(x.match_id),
          detail:'Discovered '+when(x.first_discovered_at),startTime:0
        }));
      }
      renderActions();
    }catch(e){text('awActions',e.message);}
  }
  $('awRefreshActions').onclick=()=>{state.days=[];state.recent=null;void loadActions();};

  async function loadMatches(){
    text('awChartCaption','Loading verified seven-day match schedule…');
    const d=await request(api+'?action=matches');
    state.days=Array.isArray(d.days)?d.days:[];
    state.lastFetch=when(d.asOf);
    drawBars();if(state.view==='actions')renderActions();
  }
  function drawBars(){
    if(!state.days.length){text('awChartCaption','No upcoming data.');return;}
    const metric=state.metric,vals=state.days.map(d=>Number(d[metric]||0)),max=Math.max(1,...vals);
    $('awBars').innerHTML=state.days.map((d,i)=>{
      const h=vals[i]===0?2:Math.max(3,Math.round(vals[i]/max*78));
      return '<div class="aw-bar-wrap"><span class="aw-bar-count">'+fmt(vals[i])+'</span>'+
        '<button class="aw-bar aw-bar-'+metric+(state.selectedDay===i?' aw-selected':'')+'" data-day="'+i+
        '" type="button" style="height:'+h+'px" aria-label="'+esc(d.date+' '+vals[i]+' '+metric)+'"></button>'+
        '<small>'+esc(d.date.slice(5))+'</small></div>';
    }).join('');
    text('awChartCaption','Registered starts · '+fmt(vals.reduce((a,b)=>a+b,0))+' '+metric);
    $('awBars').querySelectorAll('[data-day]').forEach(b=>b.onclick=()=>{
      state.selectedDay=Number(b.dataset.day);drawBars();renderDay();
    });renderDay();
  }
  function renderDay(){
    const d=state.days[state.selectedDay];
    if(!d){$('awDayMatches').innerHTML='';return;}
    $('awDayMatches').innerHTML='<div class="aw-heading"><strong>'+esc(d.date)+'</strong><span>'+
      fmt(d.matches)+' matches · '+fmt(d.boards)+' boards</span></div>'+
      (d.items||[]).map(m=>'<div class="aw-row"><div class="aw-info"><strong>'+esc(m.name)+'</strong>'+
      '<small>'+esc(m.opponent+' · '+when(m.startTime))+'</small><small>'+
      (m.rosterComplete?fmt(m.oursCount)+' P2K / '+fmt(m.opponentCount)+' opponent':'Roster incomplete')+
      '</small></div>'+matchLink(m)+'</div>').join('');
  }
  document.querySelectorAll('[data-aw-metric]').forEach(b=>b.onclick=()=>{
    state.metric=b.dataset.awMetric;
    document.querySelectorAll('[data-aw-metric]').forEach(x=>x.setAttribute('aria-pressed',String(x===b)));
    drawBars();
  });
  $('awRefreshMatches').onclick=()=>void loadMatches().catch(e=>text('awChartCaption',e.message));

  const eventLabel=e=>{
    const type=String(e.event_type||'');
    if(type==='name_changed')return (e.old_username||e.username||'?')+' → '+(e.new_username||'?');
    return type==='rejoined'?'Rejoined P2K':type==='joined'?'Joined P2K':type==='left'?'Left P2K':type||'Event';
  };
  async function loadMembers(){
    text('awEvents','Loading member chronology…');
    try{
      const d=await request(api+'?action=member-events&page='+state.memberPage);
      state.memberPage=Number(d.pagination?.page||state.memberPage);
      state.memberPages=Math.max(1,Number(d.pagination?.pages||1));
      $('awEvents').innerHTML=(d.events||[]).map(e=>'<div class="aw-row"><div class="aw-info"><strong>'+
        esc(eventLabel(e))+'</strong><small>'+esc((e.new_username||e.username||'Member')+' · '+when(e.effective_at||e.detected_at))+
        '</small></div><button class="aw-btn" type="button" data-member="'+esc(e.new_username||e.username||'')+'">View</button></div>').join('')||
        '<p class="aw-note">No events recorded.</p>';
      $('awEvents').querySelectorAll('[data-member]').forEach(b=>b.onclick=()=>void lookupMember(b.dataset.member));
      text('awEventPage',state.memberPage+' / '+state.memberPages);
      $('awEventPrev').disabled=state.memberPage<=1;
      $('awEventNext').disabled=state.memberPage>=state.memberPages;
    }catch(e){text('awEvents',e.message);}
  }
  $('awEventPrev').onclick=()=>{if(state.memberPage>1){state.memberPage--;void loadMembers();}};
  $('awEventNext').onclick=()=>{if(state.memberPage<state.memberPages){state.memberPage++;void loadMembers();}};
  function memberDossier(r,name){
    if(!r.found)return '<p class="aw-note">No P2K member record found for '+esc(name)+'.</p>';
    const m=r.member||{},a=r.activity||{},ev=r.lifecycle?.events||[],aliases=r.aliases||[];
    const joined=[...ev].reverse().find(e=>e.event_type==='joined'||e.event_type==='rejoined');
    const lastRejoin=ev.find(e=>e.event_type==='rejoined');
    const info=[
      ['Membership',m.current_member?'Current P2K member':'Former member'],
      ['First joined P2K',joined?when(joined.effective_at||joined.detected_at):'—'],
      ['Chess.com joined',when(m.joined_epoch)],
      ['Last rejoined',lastRejoin?when(lastRejoin.effective_at||lastRejoin.detected_at):'—'],
      ['Last left',when(m.left_at)],
      ['Daily / 960 rating',(m.daily_rating??'—')+' / '+(m.chess960_rating??'—')],
      ['Daily games',a.games??'—'],
      ['W / D / L',(a.wins??0)+' / '+(a.draws??0)+' / '+(a.losses??0)],
      ['Daily points',a.points??'—'],
      ['Registered / ongoing matches',(a.registered_matches??0)+' / '+(a.in_progress_matches??0)]
    ];
    return '<div class="aw-detail"><strong>'+esc(m.username||name)+'</strong>'+
      '<div class="aw-dossier">'+info.map(([k,v])=>'<div class="aw-dossier-row"><span>'+esc(k)+'</span><strong>'+esc(v)+'</strong></div>').join('')+'</div>'+
      '<div class="aw-heading"><strong>Known names ('+aliases.length+')</strong></div>'+
      (aliases.length?aliases.map(x=>'<div class="aw-row"><div class="aw-info"><strong>'+esc(x.username)+(x.current?' · current':'')+
        '</strong><small>'+esc('Seen: '+when(x.first_seen_at)+' – '+when(x.last_seen_at))+'</small></div></div>').join(''):'<p class="aw-note">No known aliases.</p>')+
      '<div class="aw-heading"><strong>Membership chronology</strong></div>'+
      (ev.length?ev.map(x=>'<div class="aw-row"><div class="aw-info"><strong>'+esc(eventLabel(x))+'</strong><small>'+
        esc(when(x.effective_at||x.detected_at))+'</small></div></div>').join(''):'<p class="aw-note">No recorded events.</p>')+'</div>';
  }
  async function lookupMember(name){
    name=String(name||'').trim();
    if(!/^[a-z0-9_-]{2,25}$/i.test(name)){text('awEvents','Enter a valid username.');return;}
    $('awMembersIndex').hidden=true;$('awMemberResult').hidden=false;
    $('awMemberResult').innerHTML='<button class="aw-btn" id="awBackMember" type="button">← Back</button><p class="aw-note">Loading…</p>';
    const back=()=>{$('awMemberResult').hidden=true;$('awMembersIndex').hidden=false;};
    $('awBackMember').onclick=back;
    try{const d=await request(api+'?action=member-lookup&username='+encodeURIComponent(name));
      $('awMemberResult').innerHTML='<button class="aw-btn" id="awBackMember" type="button">← Back</button>'+
        memberDossier(d.lookup||{},name);
      $('awBackMember').onclick=back;
    }catch(e){$('awMemberResult').insertAdjacentHTML('beforeend','<p class="aw-note">'+esc(e.message)+'</p>');}
    $('awScroll').scrollTop=0;
  }
  $('awMemberForm').onsubmit=e=>{e.preventDefault();void lookupMember($('awMemberName').value);};

  // Match searches are served by P2K's synchronized Green DB; no Chess.com
  // requests or administrator credentials are issued from this iframe.
  const names=()=>[...document.querySelectorAll('#awAnalysisSuggestions [data-match]')];
  const showSuggestions=items=>{
    $('awAnalysisSuggestions').innerHTML=items.map(m=>
      '<button class="aw-suggestion" type="button" role="option" data-match="'+m.matchId+'"><strong>'+
      esc(m.name)+'</strong><small>#'+m.matchId+' · '+esc(m.opponent)+' · '+esc(when(m.startTime))+'</small></button>').join('');
    names().forEach(b=>b.onclick=()=>void loadAnalysis(Number(b.dataset.match)));
  };
  async function searchMatches(exact=false){
    const q=$('awAnalysisSearch').value.trim(),sequence=++state.queryRevision;
    if(!q){showSuggestions([]);text('awAnalysisFeedback','');return;}
    if(q.length>150)return;
    try{
      const result=await request(api+'?action=match-search&q='+encodeURIComponent(q));
      if(sequence!==state.queryRevision)return;
      const items=result.results||[];
      showSuggestions(items);
      text('awAnalysisFeedback',items.length?items.length+' matching P2K daily match'+(items.length===1?'':'es'):'No verified P2K match found.');
      if(exact&&items.length===1)void loadAnalysis(Number(items[0].matchId));
    }catch(e){if(sequence===state.queryRevision)text('awAnalysisFeedback',e.message);}
  }
  let delay=0;
  $('awAnalysisSearch').addEventListener('input',()=>{
    window.clearTimeout(delay);
    delay=window.setTimeout(()=>void searchMatches(false),260);
  });
  $('awAnalysisForm').onsubmit=e=>{
    e.preventDefault();window.clearTimeout(delay);void searchMatches(true);
    emit('match-search');
  };

  const eligible=(ratings,minimum,maximum)=>ratings.filter(r=>Number.isFinite(r)&&r>=minimum&&(!maximum||r<=maximum)).sort((a,b)=>b-a);
  const modelFor=(match,p2k,opponents,includeRecruitment=false,historyOnly=false)=>{
    const C=core();if(!C)return null;
    const settings={min_rating:match.minRating,max_rating:match.maxRating,
      min_team_players:match.minimumPlayers,max_team_players:match.maximumPlayers};
    const template={settings};
    const pTeam={players:p2k.map(r=>({rating:r})),locked:match.locked===true};
    const oTeam={players:opponents.map(r=>({rating:r}))};
    const bounds=C.ratingBounds(template),max=C.maximumTeamPlayers(template);
    const p=C.teamRatings(pTeam,bounds),o=C.teamRatings(oTeam,bounds);
    const n=Math.min(p.length,o.length,Number.isFinite(max)?max:Infinity);
    if(n===0 || (historyOnly && n>512))return null;
    const compare=C.compareRecruitmentLineups(p,o,max);
    const minimum=C.minimumTeamPlayers(template);
    const forfeited=minimum>0&&p.length<minimum;
    if(historyOnly)return {win:forfeited?0:compare.p2kWinProbability,forfeited};
    const probs=[];
    for(let i=0;i<n;i++)probs.push(C.eloExpectedScore(p[i],o[i]));
    // The full analyzer uses four independent half-point trials per paired board.
    // We retain its modal half-point score, not an arithmetic rounding shortcut.
    let dist=[1];
    for(const probability of probs)for(let trial=0;trial<4;trial++){
      const next=new Array(dist.length+1).fill(0);
      for(let i=0;i<dist.length;i++){
        next[i]+=dist[i]*(1-probability);next[i+1]+=dist[i]*probability;
      }dist=next;
    }
    const modal=dist.reduce((best,x,i)=>x>dist[best]?i:best,0);
    const target=Number.isFinite(max)?Math.min(max,Math.max(minimum,o.length)):Math.max(minimum,o.length);
    const baseline=Math.max(0,target-p.length);
    // The optimizer explores many possible portfolios, so retain the exact model
    // for ordinary matches and show the minimum need for very large lineups.
    const recruitment=!includeRecruitment?null:Math.max(p.length,o.length)>300?
      {count:baseline,baseline,largeMatch:true,ready:baseline===0,locked:match.locked===true}:
      C.recruitmentMetrics({match:template,p2kTeam:pTeam,opponentTeam:oTeam,isLeague:isLeague(match.name)});
    return {win:forfeited?0:compare.p2kWinProbability,predicted:[modal/2,n*2-modal/2],
      boards:n,recruitment,forfeited};
  };
  const svg=(content,height=100,label='Chart')=>'<svg viewBox="0 0 240 '+height+'" width="100%" role="img" aria-label="'+esc(label)+'" xmlns="http://www.w3.org/2000/svg">'+content+'</svg>';
  const poly=(points,color,width=2)=>'<polyline points="'+points.map(p=>p.map(x=>Number(x).toFixed(1)).join(',')).join(' ')+'" fill="none" stroke="'+color+'" stroke-width="'+width+'" stroke-linejoin="round" stroke-linecap="round"/>';
  const palette={ours:'#25a36e',other:'#d98350',win:'#4b96e5'};
  function legend(history=false){return '<div class="aw-op-legend"><span style="color:'+palette.ours+'">● P2K</span><span style="color:'+palette.other+'">● Opponent</span>'+
    (history?'<span style="color:'+palette.win+'">● Win %</span>':'')+'</div>';}
  function renderRatings(match,p,o){
    const all=p.concat(o);
    if(!all.length){text('awChartCumulative','No rated players available.');text('awChartHistogram','No rated players available.');return;}
    const minRating=Math.max(0,Math.floor(Math.min(...all)/100)*100-100);
    const maxRating=Math.min(3600,Math.ceil(Math.max(...all)/100)*100+100);
    const thresholds=[];
    for(let r=minRating;r<=maxRating;r+=100)thresholds.push(r);
    const maxC=Math.max(1,p.length,o.length);
    const x=i=>18+204*(i/Math.max(1,thresholds.length-1));
    const yc=v=>77-60*v/maxC;
    const cumulative=ratings=>thresholds.map((r,i)=>[x(i),yc(ratings.filter(n=>n>=r).length)]);
    const grid=[15,45,77].map(y=>'<line x1="18" y1="'+y+'" x2="225" y2="'+y+'" stroke="currentColor" opacity=".1" stroke-dasharray="2 3"/>').join('');
    const label='<text x="18" y="91" fill="currentColor" font-size="9">'+minRating+'</text>'+
      '<text x="226" y="91" text-anchor="end" fill="currentColor" font-size="9">'+maxRating+' Elo</text>';
    $('awChartCumulative').innerHTML=svg(grid+poly(cumulative(p),palette.ours)+poly(cumulative(o),palette.other)+label,96,
      'Cumulative P2K and opponent registrations at or above each 100 Elo threshold')+legend();
    // Histogram preserves the analyzer's 100-point bins, using horizontal scrolling
    // for matches with a wide Elo spread rather than silently merging bins.
    const bins=thresholds.slice(0,-1),maxH=Math.max(1,...bins.flatMap(r=>[
      p.filter(v=>v>=r&&v<r+100).length,o.filter(v=>v>=r&&v<r+100).length]));
    const width=Math.max(240,bins.length*24+18);
    const box=bins.map((r,i)=>{
      const pc=p.filter(v=>v>=r&&v<r+100).length,oc=o.filter(v=>v>=r&&v<r+100).length;
      const h1=60*pc/maxH,h2=60*oc/maxH;
      const unavailable=r<match.minRating||(match.maxRating&&r>match.maxRating);
      return '<rect x="'+(18+i*24)+'" y="'+(77-h1)+'" width="10" height="'+h1+
        '" fill="'+palette.ours+'" opacity="'+(unavailable?0.35:1)+'"><title>'+r+'–'+(r+99)+' P2K: '+pc+'</title></rect>'+
        '<rect x="'+(29+i*24)+'" y="'+(77-h2)+'" width="10" height="'+h2+
        '" fill="'+palette.other+'" opacity="'+(unavailable?0.35:1)+'"><title>'+r+'–'+(r+99)+' opponent: '+oc+'</title></rect>';
    }).join('');
    const labels=bins.filter((_,i)=>i%Math.max(1,Math.ceil(bins.length/6))===0).map(r=>{
      const i=bins.indexOf(r);return '<text x="'+(20+i*24)+'" y="92" fill="currentColor" font-size="8">'+r+'</text>';
    }).join('');
    $('awChartHistogram').innerHTML='<div class="aw-op-scroll"><svg viewBox="0 0 '+width+' 98" width="'+width+
      '" height="98" role="img" aria-label="P2K and opponent registered players by 100 Elo rating brackets">'+
      '<line x1="16" y1="77" x2="'+(width-5)+'" y2="77" stroke="currentColor" opacity=".3"/>'+box+labels+'</svg></div>'+legend();
  }
  function renderHistory(match,history,current){
    const clean=(history||[]).filter(x=>Number.isFinite(Date.parse(x.at))).sort((a,b)=>Date.parse(a.at)-Date.parse(b.at));
    if(!clean.length){$('awChartHistory').innerHTML='<p class="aw-note">No stored registration snapshots for this match.</p>';return;}
    const rows=clean.map(x=>({...x}));
    if(current)rows.push({at:current.asOf,p2k:current.roster.p2k,opponent:current.roster.opponent});
    const samples=rows.slice(-32),max=Math.max(1,...samples.map(r=>Math.max(r.p2k||0,r.opponent||0)));
    const x=i=>18+207*i/Math.max(1,samples.length-1),y=n=>70-53*(Number(n)||0)/max;
    const grid=[17,44,70].map(v=>'<line x1="18" y1="'+v+'" x2="225" y2="'+v+
      '" stroke="currentColor" opacity=".1" stroke-dasharray="2 3"/>').join('');
    const players=svg(grid+poly(samples.map((r,i)=>[x(i),y(r.p2k)]),palette.ours)+
      poly(samples.map((r,i)=>[x(i),y(r.opponent)]),palette.other)+
      '<text x="18" y="90" font-size="9" fill="currentColor">'+esc(when(samples[0].at).slice(0,11))+'</text>'+
      '<text x="225" y="90" font-size="9" text-anchor="end" fill="currentColor">Now</text>',96,
      'Stored snapshot progression of P2K and opponent registered players');
    const probabilitySamples=clean.slice(-12);
    const wins=probabilitySamples.map(r=>({
      at:r.at,win:modelFor(match,r.p2kRatings||[],r.opponentRatings||[],false,true)?.win??null
    })).filter(r=>r.win!==null);
    if(current){
      const latest=modelFor(match,current.roster.p2kRatings||[],current.roster.opponentRatings||[],false,true);
      if(latest?.win!==null && latest?.win!==undefined)wins.push({at:current.asOf,win:latest.win});
    }
    const winline=wins.length>1?svg('<line x1="18" y1="37" x2="225" y2="37" stroke="currentColor" opacity=".1"/>'+
      poly(wins.map((r,i)=>[18+207*i/Math.max(1,wins.length-1),50-42*r.win]),palette.win)+
      '<text x="18" y="61" font-size="9" fill="currentColor">0%</text>'+
      '<text x="225" y="61" text-anchor="end" font-size="9" fill="currentColor">100%</text>',66,
      'Modeled historical P2K match win probability'):'<p class="aw-muted">Historical win-probability data is insufficient.</p>';
    $('awChartHistory').innerHTML=players+'<p class="aw-muted">Modeled P2K win probability</p>'+winline+legend(true)+
      '<p class="aw-muted">Observed snapshots; probability curve samples up to 12 recent history points (not individual join timestamps).</p>';
  }
  async function loadAnalysis(id){
    if(!Number.isInteger(id)||id<=0)return;
    const rev=++state.analysisRevision;
    state.analyzedId=id;
    $('awAnalysisResult').hidden=true;
    text('awAnalysisFeedback','Reading registered players and stored history…');
    showSuggestions([]);
    try{
      const [d,history]=await Promise.all([
        request(api+'?action=match-data&id='+id),
        request(api+'?action=match-history&id='+id).catch(()=>({points:[]}))
      ]);
      if(rev!==state.analysisRevision)return;
      if(!d.found)throw new Error('Match not found in verified P2K daily matches.');
      state.current=d;state.history=history.points||[];
      const m=d.match,r=d.roster,p=r.p2kRatings||[],o=r.opponentRatings||[];
      text('awAnalysisTitle',m.name||'Match #'+id);
      text('awAnalysisMeta','#'+id+' · '+m.opponent+' · '+when(m.startTime));
      const model=r.complete?modelFor(m,p,o,true):null;
      $('awAnalysisMetrics').innerHTML=[
        ['P2K players',fmt(r.p2k)],['Opponent',fmt(r.opponent)],
        ['P2K win',model?(model.win*100).toFixed(1)+'%':'—']
      ].map(([a,b])=>'<div><small>'+esc(a)+'</small><strong>'+esc(b)+'</strong></div>').join('');
      let advice='Unavailable';
      if(model){const rec=model.recruitment;advice=rec?.locked?'Registration locked':
        rec?.largeMatch?(rec.count>0?'At least +'+rec.count+' players (minimum)':'Optimized target: open full analyzer'):
        rec?.ready?'No additional players advised':rec?'+'+rec.count+' players'+
        (rec.minimumRating!==undefined&&rec.minimumRating!==null?' · '+rec.minimumRating+'+ Elo':''):'Unavailable';}
      $('awAnalysisPrediction').innerHTML=
        '<div><span>Predicted score</span><strong>'+ (model?model.predicted.map(v=>v.toFixed(1)).join(' – '):'Unavailable')+'</strong></div>'+
        '<div><span>Advised recruitment</span><strong>'+esc(advice)+'</strong></div>'+
        (model?.forfeited?'<p class="aw-note">P2K has not met the minimum eligible-player count.</p>':'')+
        (!r.complete?'<p class="aw-note">Verified roster incomplete; predictions withheld.</p>':'');
      const allp=eligible(p,0,null),allo=eligible(o,0,null);
      renderRatings(m,allp,allo);
      renderHistory(m,history.points||[],d);
      text('awAnalysisFreshness','Green last verified: '+when(m.lastVerified)+
        ' · '+(r.complete?'Registered lineup available':'Incomplete or unverified lineup')+
        ' · '+(r.truncated?'Roster capped':''));
      $('awFullAnalysis').href=url('AnalyzeMatch.html?match='+id+'&club=promote-to-king');
      $('awFullAnalysis').onclick=()=>emit('match-full-analysis-open');
      $('awAnalysisResult').hidden=false;
      text('awAnalysisFeedback','');
      emit('match-analysis-view');
    }catch(e){if(rev===state.analysisRevision){$('awAnalysisResult').hidden=true;text('awAnalysisFeedback',e.message);}}
  }
  selectTab('actions');
})();