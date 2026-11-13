/* P2K v2.15.9 Recruitment Radar: synchronized Green database only.
   Loads only within the P2K iframe; host pages need no scripts. */
(() => {
  'use strict';
  if(document.body.dataset.widget!=='recruitment-radar')return;
  const ROOT=new URL('../../../',document.currentScript.src);
  const API=new URL('server/widgets/public/api.php',ROOT);
  const OAUTH_SESSION=new URL('server/team-points/public/oauth.php?action=session',ROOT);
  const main=document.getElementById('p2kwContent');
  const status=document.getElementById('p2kwStatus');
  const foot=document.getElementById('p2kwFoot');
  const lineup=window.P2K_RECRUITMENT_LINEUP_CORE;
  const enc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number=v=>Number(v||0).toLocaleString('en-GB');
  const fmtDate=seconds=>{const d=new Date(Number(seconds)*1000);return Number.isFinite(d.getTime())
    ?new Intl.DateTimeFormat('en-GB',{timeZone:'UTC',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit',hour12:false}).format(d)+' UTC':'—';};
  const hoursFor={ '24h':24,'48h':48,'72h':72,'7d':168 };
  const windows=Object.keys(hoursFor);
  const shortDate=seconds=>{const d=new Date(Number(seconds)*1000);return Number.isFinite(d.getTime())?new Intl.DateTimeFormat('en-GB',{timeZone:'UTC',day:'numeric',month:'short'}).format(d):'—';};
  const state={settings:null,window:'48h',scope:'priority',catalog:[],upcoming:[],
    details:new Map(),needs:new Map(),username:'',draft:'',profile:null,authenticatedUser:'',
    fetching:false,scanned:0,total:0,scanToken:0,notice:'',error:'',effectiveWindow:'48h',baseDetails:[],partial:false};
  const get=async(address,signal)=>{
    const url=new URL(address);const ac=new AbortController();
    const timer=setTimeout(()=>ac.abort(),15000);
    try{
      if(signal?.aborted)throw new Error('Request superseded');
      const res=await fetch(url.href,{signal:ac.signal,credentials:'same-origin',headers:{Accept:'application/json'}});
      const result=await res.json().catch(()=>({}));
      if(!res.ok||result.ok===false)throw new Error(result?.error?.message||'HTTP '+res.status);
      return result;
    }finally{clearTimeout(timer)}
  };
  const url=(action,params={})=>{
    const out=new URL(API);out.searchParams.set('action',action);
    Object.entries(params).forEach(([k,v])=>out.searchParams.set(k,String(v)));
    return out;
  };
  async function authenticatedUsername(){
    try{
      const identity=await get(OAUTH_SESSION);
      if(identity?.authenticated!==true||identity?.oauth_verified!==true)return '';
      const username=String(identity.profile?.username||'').trim();
      return /^[a-zA-Z0-9_-]{2,25}$/.test(username)?username:'';
    }catch(error){
      // Missing/expired third-party OAuth cookies must leave the public widget usable.
      return '';
    }
  }
  const league=match=>match.category==='league'||match.isLeague===true;
  const timeControl=value=>{
    const raw=String(value??'').trim();
    if(!raw)return '—';
    const ratio=raw.match(/^(\d+)\/(\d+)$/);
    const seconds=ratio?(Number(ratio[2])/Math.max(1,Number(ratio[1]))):/^\d+$/.test(raw)?Number(raw):null;
    if(seconds!==null&&Number.isFinite(seconds)&&seconds>0){
      if(seconds>=86400)return (Math.round(seconds/86400*10)/10)+'d/m';
      if(seconds>=3600)return (Math.round(seconds/3600*10)/10)+'h/m';
      if(seconds>=60)return (Math.round(seconds/60*10)/10)+'m/m';
      return seconds+'s/m';
    }
    return raw.replace(/_/g,' ').slice(0,16);
  };
  const range=detail=>{
    if(!detail?.verified)return '—';
    // Chess.com omits the minimum setting for an unrestricted 0 rating floor.
    const min=Math.max(0,Number(detail.minRating)||0),max=Number(detail.maxRating)||0;
    if(!max)return min?min+'+':'Open';
    return min?min+'–'+max:'U'+max;
  };
  function availableWithin(windowLabel){
    const now=Date.now()/1000,max=now+(hoursFor[windowLabel]||48)*3600;
    return state.catalog.filter(m=>Number(m.startTime)>now&&Number(m.startTime)<=max)
      .sort((a,b)=>Number(a.startTime)-Number(b.startTime)||Number(a.matchId)-Number(b.matchId));
  }
  // Widen only when no results match the selected priority, player, and window.
  // The selected button remains unchanged so the wider effective window is clear.
  function resolveWindow(){
    const from=Math.max(0,windows.indexOf(state.window));
    for(let i=from;i<windows.length;i++){
      const label=windows[i],possible=availableWithin(label);
      const qualifying=possible.filter(m=>{
        const info=state.details.get(String(m.matchId));
        return eligible(m,info)&&(state.scope==='all'||demand(m,info).priority);
      });
      if(qualifying.length||i===windows.length-1){
        state.effectiveWindow=label;state.upcoming=possible;
        return;
      }
    }
  }
  const ratingKind=detail=>/960/.test(String(detail?.rules||'').toLowerCase())?'chess960':'classical';
  function demand(match,detail){
    if(!detail?.verified)return {verified:false,priority:false,count:0,baseline:0};
    const sig=String(match.matchId);
    if(state.needs.has(sig))return state.needs.get(sig);
    const min=Math.max(0,Number(detail.minRating)||0),max=Number(detail.maxRating)||0;
    const filter=values=>(Array.isArray(values)?values:[])
      .map(Number).filter(r=>Number.isFinite(r)&&r>=min&&(!max||r<=max))
      .sort((a,b)=>b-a);
    const ours=filter(detail.oursRatings),other=filter(detail.opponentRatings);
    const minimum=Math.max(0,Number(detail.minimumPlayers)||0);
    const maximum=Number(detail.maximumPlayers)>0?Number(detail.maximumPlayers):Infinity;
    // Identical player-deficit envelope to Recruitment Demand Planner:
    // match the larger of mandatory and opposing eligible lineup, capped at the
    // match's maximum team size. Do not infer a deficit from names or timing.
    const target=Math.min(maximum,Math.max(minimum,other.length));
    const baseline=Math.max(0,target-ours.length);
    const below=ours.length<minimum;
    let strengthen=false;
    // Cheap expected-score projection from the shared recruitment engine:
    // avoids running the expensive full multi-bucket portfolio optimizer in a
    // public iframe, which would stall mobile browsers for large rosters.
    if(baseline===0&&ours.length<maximum&&other.length>0&&lineup?.compareRecruitmentLineupsCheap){
      try{
        const current=lineup.compareRecruitmentLineupsCheap(ours,other,maximum);
        const candidate=Math.max(min,Math.min(max||Infinity,2000));
        const projected=[...ours,candidate].sort((a,b)=>b-a);
        const improved=lineup.compareRecruitmentLineupsCheap(projected,other,maximum);
        const targetShare=league(match) ? 0.55 : 0.50;
        strengthen=Number.isFinite(current.expectedScoreShare)
          &&Number.isFinite(improved.expectedScoreShare)
          &&current.expectedScoreShare<targetShare-.002
          &&improved.expectedScoreShare-current.expectedScoreShare>.005;
      }catch(error){console.warn('Radar lineup comparison unavailable',error);}
    }
    const priority=!detail.locked&&(baseline>0||strengthen);
    const hours=Math.max(0,(Number(match.startTime)*1000-Date.now())/3600000);
    const score=(baseline*15)+(league(match)?70:0)+(hours<48?80:0)
      +(below?100:0)+(strengthen?20:0);
    const result={verified:true,priority,count:baseline,baseline,score,below,strengthen};
    state.needs.set(sig,result);
    return result;
  }

  function eligible(match,detail){
    if(!state.username)return true;
    if(!state.profile||!detail?.verified)return false;
    if(detail.alreadyRegistered||detail.locked===true)return false;
    // An absent upper rating bound is open; numeric bounds are enforced below.
    const maxPlayers=Number(detail.maximumPlayers)||0;
    if(maxPlayers>0&&Number(detail.oursCount)>=maxPlayers)return false;
    const rating=state.profile[ratingKind(detail)];
    if(rating===null||rating===undefined||!Number.isFinite(Number(rating)))return false;
    const n=Number(rating),min=Math.max(0,Number(detail.minRating)||0),max=Number(detail.maxRating)||0;
    return n>=min&&(!max||n<=max);
  }
  function results(){
    const matches=state.upcoming.map(m=>{
      const info=state.details.get(String(m.matchId));
      return {match:m,info,need:demand(m,info)};
    }).filter(r=>eligible(r.match,r.info));
    const selected=state.scope==='priority'?resultsFilterPriority(matches):matches;
    function resultsFilterPriority(list){return list.filter(r=>r.need.verified&&r.need.priority)}
    if(state.scope==='priority'){
      selected.sort((a,b)=>b.need.score-a.need.score||Number(a.match.startTime)-Number(b.match.startTime));
    }else{
      selected.sort((a,b)=>Number(a.match.startTime)-Number(b.match.startTime));
    }
    return selected;
  }
  function entries(){
    const limit=Math.max(1,Math.min(10,Number(state.settings?.limit)||4));
    return results().slice(0,limit).map(({match:m,info,need})=>{
      const name=String(m.opponentName||m.name||'Match'),safeUrl=(()=>{
        try{const u=new URL(m.url,ROOT);return u.protocol==='https:'||u.protocol==='http:'?u.href:'';}catch{return'';}
      })();
      const tc=timeControl(info?.timeControl||m.timeControl);
      const attr=range(info);

      const needsText=info?.verified&&need.verified?(need.baseline>0?number(need.baseline)+' short':need.priority?'Lineup improvement needed':'No additional players needed'):'';
      return '<div class="p2kw-entry p2kw-radar-entry"><div class="p2kw-entry-main">'+
        '<strong title="'+enc(m.name||name)+'">'+enc(name)+'</strong>'+
        '<div class="p2kw-radar-meta" title="'+enc(fmtDate(m.startTime)+' · '+(league(m)?'League':'Friendly')+' · '+attr+' · '+timeControl(info?.timeControl||m.timeControl))+'">'+
          '<span class="p2kw-radar-pill '+(league(m)?'is-league':'is-friendly')+'">'+(league(m)?'League':'Friendly')+'</span>'+
          '<span class="p2kw-radar-date">'+enc(shortDate(m.startTime))+'</span>'+
          '<span class="p2kw-radar-rating">'+enc(attr)+'</span>'+
          '<span class="p2kw-radar-control">'+enc(tc)+'</span></div>'+
        (needsText?'<small class="p2kw-radar-need">'+enc(needsText)+'</small>':'')+
        '</div>'+(safeUrl?'<a class="p2kw-action" href="'+enc(safeUrl)+'" target="_blank" rel="noopener noreferrer">Join</a>':'')+
        '</div>';
    }).join('');
  }
  function refresh(){
    const list=main.querySelector('#p2kwRadarList'),hint=main.querySelector('#p2kwRadarHint');
    const count=main.querySelector('#p2kwRadarCount'),overview=main.querySelector('#p2kwRadarOverview');
    if(!list||!hint)return;
    resolveWindow();
    const qualified=results().length;
    if(count)count.textContent=number(qualified);
    if(overview)overview.textContent=number(state.upcoming.length)+' upcoming matches';
    list.innerHTML=entries()||'<p class="p2kw-empty">'+(state.fetching?
      'Checking recruitment opportunities…':
      state.username&&!state.profile?(state.authenticatedUser?'Your ratings are temporarily unavailable.':'Player ratings unavailable. Check the username or retry.'):
      'No matches match these filters.')+'</p>';
    let note=state.error||state.notice||'';
    if(state.fetching)note='Checking match recommendations…';
    else{
      if(state.effectiveWindow!==state.window)note=(note?note+' · ':'')+'No matching opportunities in '+state.window+'; showing '+state.effectiveWindow;
      const unavailable=state.upcoming.filter(m=>!state.details.get(String(m.matchId))?.verified).length;
      if(unavailable)note=(note?note+' · ':'')+unavailable+' match'+(unavailable===1?'':'es')+' temporarily unavailable';
    }
    hint.textContent=note;
    status.textContent=state.fetching?'Checking…':state.error?'PARTIAL':'LIVE';
    foot.textContent='';
  }
  async function loadFor(name){
    const generation=++state.scanToken;
    state.username=name;state.draft=name;state.profile=null;state.error='';state.notice='';
    state.needs.clear();
    state.fetching=true;refresh();
    try{
      if(name){
        const [profile,catalog]=await Promise.all([
          get(url('radar-player',{player:name})),
          get(url('radar-catalog',{player:name}))
        ]);
        if(generation!==state.scanToken)return;
        state.profile=profile;
        state.catalog=Array.isArray(catalog.matches)?catalog.matches:[];
        state.details=new Map((catalog.details||[]).map(d=>[String(d.matchId),d]));
        state.error=(profile.classical==null&&profile.chess960==null)
          ?'No Daily or Chess960 rating is available for this player.':'';
        if(profile.statsCheckedAt){
          state.notice='Ratings checked: '+String(profile.statsCheckedAt)+' UTC';
        }
      }else{
        const catalog=await get(url('radar-catalog'));
        if(generation!==state.scanToken)return;
        state.catalog=Array.isArray(catalog.matches)?catalog.matches:[];
        state.details=new Map((catalog.details||[]).map(d=>[String(d.matchId),d]));
      }
    }catch(error){
      if(generation!==state.scanToken)return;
      state.error='Match recommendations are temporarily unavailable.';
    }
    if(generation!==state.scanToken)return;
    state.fetching=false;refresh();
  }
  function shell(){
    main.innerHTML='<div class="p2kw-radar-summary" aria-label="Match counts">'+
      '<span class="p2kw-radar-summary-label">Matches matching filters</span>'+
      '<span id="p2kwRadarCount">—</span>'+
      '<span id="p2kwRadarOverview"></span></div>'+
      '<nav class="p2kw-seg p2kw-radar-scope" aria-label="Recruitment selection"><button type="button" data-radar-scope="priority" aria-pressed="'+(state.scope==='priority')+'">Priority</button><button type="button" data-radar-scope="all" aria-pressed="'+(state.scope==='all')+'">All</button></nav>'+
      (state.authenticatedUser?'<div class="p2kw-radar-identity"><span>For '+enc(state.authenticatedUser)+'</span><small>Verified Chess.com account</small></div>':'<form class="p2kw-search p2kw-radar-search" id="p2kwRadarForm"><input type="search" name="username" autocomplete="off" spellcheck="false" maxlength="25" aria-label="Chess.com username" placeholder="Chess.com username" value="'+enc(state.draft)+'"><button type="submit">Find</button></form>')+
      '<nav class="p2kw-seg p2kw-radar-window" aria-label="Starting within">'+
       Object.keys(hoursFor).map(k=>'<button type="button" data-radar-window="'+k+'" aria-pressed="'+(k===state.window)+'">'+k+'</button>').join('')+'</nav>'+
      '<div class="p2kw-subhead p2kw-radar-subhead"><strong>Upcoming matches</strong></div>'+
      '<div id="p2kwRadarList"></div><p class="p2kw-radar-hint" id="p2kwRadarHint"></p>';
    main.querySelectorAll('[data-radar-scope]').forEach(b=>b.onclick=()=>{
      state.scope=b.dataset.radarScope;
      main.querySelectorAll('[data-radar-scope]').forEach(x=>x.setAttribute('aria-pressed',String(x===b)));
      refresh();
    });
    main.querySelectorAll('[data-radar-window]').forEach(b=>b.onclick=()=>{
      if(state.window===b.dataset.radarWindow)return;
      state.window=b.dataset.radarWindow;
      main.querySelectorAll('[data-radar-window]').forEach(x=>x.setAttribute('aria-pressed',String(x===b)));
      state.needs.clear();refresh();
    });
    const form=main.querySelector('#p2kwRadarForm');
    if(form){
      const input=form.querySelector('input');
      input.oninput=()=>{state.draft=input.value;};
      form.onsubmit=event=>{
        event.preventDefault();
        const name=input.value.trim();
        if(name&&!/^[a-zA-Z0-9_-]{2,25}$/.test(name)){
          state.error='Enter a valid Chess.com username.';refresh();return;
        }
        return loadFor(name);
      };
    }
    refresh();
  }
  (async()=>{
    try{
      const response=await get(url('config',{widget:'recruitment-radar'}));
      state.settings=response.settings||{};
      if(!state.settings.enabled&&document.body.dataset.preview!=='1'){
        main.innerHTML='<div class="p2kw-empty">Widget not published.</div>';return;
      }
      state.window=hoursFor[state.settings.window]?state.settings.window:'48h';
      state.authenticatedUser=await authenticatedUsername();
      if(state.authenticatedUser){
        // One verified automatic personalized lookup, logged via radar-player.
        await loadFor(state.authenticatedUser);
      }else{
        const catalog=await get(url('radar-catalog'));
        state.catalog=Array.isArray(catalog.matches)?catalog.matches:[];
        state.details=new Map((catalog.details||[]).map(d=>[String(d.matchId),d]));
      }
      shell();refresh();
    }catch(error){
      main.innerHTML='<p class="p2kw-empty">Match recommendations are temporarily unavailable.</p>';
      status.textContent='Unavailable';
    }
  })();
})();
