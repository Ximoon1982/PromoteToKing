/* P2K v2.15.9 Recruitment Radar: verified, cached demand + rating-personalized matches.
   Loads only within the P2K iframe; host pages need no scripts. */
(() => {
  'use strict';
  if(document.body.dataset.widget!=='recruitment-radar')return;
  const ROOT=new URL('../../../',document.currentScript.src);
  const API=new URL('server/widgets/public/api.php',ROOT);
  const CATALOG=new URL('server/events-showcase/public/api.php',ROOT);
  const main=document.getElementById('p2kwContent');
  const status=document.getElementById('p2kwStatus');
  const foot=document.getElementById('p2kwFoot');
  const lineup=window.P2K_RECRUITMENT_LINEUP_CORE;
  const enc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number=v=>Number(v||0).toLocaleString('en-GB');
  const fmtDate=seconds=>{const d=new Date(Number(seconds)*1000);return Number.isFinite(d.getTime())
    ?new Intl.DateTimeFormat('en-GB',{timeZone:'UTC',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit',hour12:false}).format(d)+' UTC':'—';};
  const hoursFor={ '24h':24,'48h':48,'72h':72,'7d':168 };
  const state={settings:null,window:'48h',scope:'priority',catalog:[],upcoming:[],
    details:new Map(),needs:new Map(),username:'',draft:'',profile:null,
    fetching:false,scanned:0,total:0,scanToken:0,notice:'',error:''};
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
  const league=match=>match.category==='league'||match.isLeague===true;
  const timeControl=value=>{
    const raw=String(value??'').trim();
    if(!raw)return 'Time control unavailable';
    const match=raw.match(/^(?:\d+\/)?(\d+)$/);
    if(match){
      const seconds=Number(match[1]);
      if(seconds>=86400&&seconds%86400===0)return (seconds/86400)+' day'+(seconds===86400?'':'s')+'/move';
      if(seconds>=3600&&seconds%3600===0)return (seconds/3600)+'h/move';
      if(seconds>=60&&seconds%60===0)return (seconds/60)+'m/move';
      return seconds+'s/move';
    }
    return raw.replace(/_/g,' ').slice(0,35);
  };
  const range=detail=>{
    if(!detail?.verified)return 'Rating range unavailable';
    const min=Math.max(0,Number(detail.minRating)||0),max=Number(detail.maxRating)||0;
    if(!max)return min?min+'+':'Open rating';
    return min?min+'–'+max:'U'+max;
  };
  const activeMatches=()=>{
    const now=Date.now()/1000,max=now+(hoursFor[state.window]||48)*3600;
    return state.catalog.filter(m=>Number(m.startTime)>now&&Number(m.startTime)<=max)
      .sort((a,b)=>Number(a.startTime)-Number(b.startTime)||Number(a.matchId)-Number(b.matchId));
  };
  const ratingKind=detail=>/960/.test(String(detail?.rules||'').toLowerCase())?'chess960':'classical';
  function demand(match,detail){
    if(!detail?.verified)return {verified:false,priority:false,count:0,baseline:0};
    const sig=String(match.matchId);
    if(state.needs.has(sig))return state.needs.get(sig);
    const min=Number(detail.minRating)||0,max=Number(detail.maxRating)||0;
    const bounds={min_rating:min,max_rating:max||0};
    const ours=Array.isArray(detail.oursRatings)?detail.oursRatings:[];
    const other=Array.isArray(detail.opponentRatings)?detail.opponentRatings:[];
    const minPlayers=Number(detail.minimumPlayers)||0,maxPlayers=Number(detail.maximumPlayers)||0;
    let plan=null;
    try{
      if(lineup?.recruitmentMetrics){
        plan=lineup.recruitmentMetrics({
          match:{settings:{...bounds,min_team_players:minPlayers,max_team_players:maxPlayers,rules:detail.rules}},
          p2kTeam:{players:ours.map(r=>({rating:r})),locked:Boolean(detail.locked)},
          opponentTeam:{players:other.map(r=>({rating:r}))},
          isLeague:league(match)
        });
      }
    }catch(error){console.warn('Recruitment demand projection unavailable',error);}
    // No guesses when the shared recruitment algorithm could not run.
    const verified=plan!==null;
    const count=verified?Math.max(0,Number(plan.count)||0):0;
    const baseline=verified?Math.max(0,Number(plan.baseline)||0):0;
    const priority=verified&&!detail.locked&&!plan.ready&&count>0;
    const hours=Math.max(0,(Number(match.startTime)*1000-Date.now())/3600000);
    const below=Number(detail.oursCount)<minPlayers;
    const score=(baseline*15)+(league(match)?70:0)+(hours<48?80:0)+(below?100:0)+(priority&&baseline===0?20:0);
    const result={verified,priority,count,baseline,score,below};
    state.needs.set(sig,result);
    return result;
  }
  function eligible(match,detail){
    if(!state.username)return true;
    if(!state.profile||!detail?.verified)return false;
    if(detail.alreadyRegistered||detail.locked)return false;
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
    const selected=state.scope==='priority'?resultsFilterPriority(results):results;
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
      const attr=info?.verified?range(info)+' · '+tc:'Details pending · '+tc;
      const needsText=info?.verified&&need.verified?(need.baseline>0?number(need.baseline)+' short':need.priority?'Lineup improvement needed':'No additional players needed'):'';
      return '<div class="p2kw-entry p2kw-radar-entry"><div class="p2kw-entry-main">'+
        '<strong title="'+enc(m.name||name)+'">'+enc(name)+'</strong>'+
        '<small>'+enc(fmtDate(m.startTime))+' · '+(league(m)?'League':'Friendly')+'</small>'+
        '<small>'+enc(attr)+'</small>'+
        (needsText?'<small class="p2kw-radar-need">'+enc(needsText)+'</small>':'')+
        '</div>'+(safeUrl?'<a class="p2kw-action" href="'+enc(safeUrl)+'" target="_blank" rel="noopener noreferrer">Join</a>':'')+
        '</div>';
    }).join('');
  }
  function refresh(){
    const list=main.querySelector('#p2kwRadarList'),hint=main.querySelector('#p2kwRadarHint');
    const count=main.querySelector('#p2kwRadarCount'),overview=main.querySelector('#p2kwRadarOverview');
    if(!list||!hint)return;
    const seen=state.upcoming.filter(m=>state.details.has(String(m.matchId))).length;
    const qualified=results().length;
    if(count)count.textContent=number(qualified);
    if(overview)overview.textContent=state.username?'Rating-matched opportunities':number(state.upcoming.length)+' upcoming matches';
    list.innerHTML=entries()||'<p class="p2kw-empty">'+(state.fetching?
      'Assessing verified recruitment needs…':
      state.username&&!state.profile?'Player ratings unavailable. Correct the username or retry.':
      'No matches match these filters.')+'</p>';
    let note=state.error||state.notice||'';
    if(state.fetching)note='Checking live registration sheets · '+seen+' / '+state.upcoming.length;
    else{
      const unavailable=state.upcoming.filter(m=>!state.details.get(String(m.matchId))?.verified).length;
      if(unavailable)note=(note?note+' · ':'')+unavailable+' match'+(unavailable===1?'':'es')+' could not be verified';
      else if(!note)note='Registration needs checked';
    }
    hint.textContent=note;
    status.textContent=state.fetching?'Checking…':state.error?'PARTIAL':'LIVE';
    foot.textContent='';
  }
  function shell(){
    main.innerHTML='<div class="p2kw-total-row"><div><div class="p2kw-label">Matches matching filters</div><div class="p2kw-big" id="p2kwRadarCount">—</div></div><div class="p2kw-small" id="p2kwRadarOverview"></div></div>'+
      '<nav class="p2kw-seg p2kw-radar-scope" aria-label="Recruitment selection"><button type="button" data-radar-scope="priority" aria-pressed="'+(state.scope==='priority')+'">Priority</button><button type="button" data-radar-scope="all" aria-pressed="'+(state.scope==='all')+'">All</button></nav>'+
      '<form class="p2kw-search p2kw-radar-search" id="p2kwRadarForm"><input type="search" name="username" autocomplete="off" spellcheck="false" maxlength="25" aria-label="Chess.com username" placeholder="Chess.com username" value="'+enc(state.draft)+'"><button type="submit">Find</button></form>'+
      '<nav class="p2kw-seg p2kw-radar-window" aria-label="Starting within">'+
       Object.keys(hoursFor).map(k=>'<button type="button" data-radar-window="'+k+'" aria-pressed="'+(k===state.window)+'">'+k+'</button>').join('')+'</nav>'+
      '<div class="p2kw-subhead"><strong>Recruitment opportunities</strong><span class="p2kw-small">Priority = verified need</span></div>'+
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
      state.upcoming=activeMatches();state.needs.clear();refresh();
      void scan();
    });
    const form=main.querySelector('#p2kwRadarForm'),input=form.querySelector('input');
    input.oninput=()=>{state.draft=input.value;};
    form.onsubmit=async(event)=>{
      event.preventDefault();
      const name=input.value.trim();
      if(name&& !/^[a-zA-Z0-9_-]{2,25}$/.test(name)){
        state.error='Enter a valid Chess.com username.';refresh();return;
      }
      state.username=name;state.draft=name;state.profile=null;
      state.details.clear();state.needs.clear();state.error='';state.notice='';
      // Invalidate in-flight scans before changing the personalized context.
      ++state.scanToken;state.fetching=false;
      refresh();
      if(name){
        try{
          state.fetching=true;refresh();
          const profile=await get(url('radar-player',{player:name}));
          if(state.username!==name)return;
          state.profile=profile;
          state.error=(profile.classical==null&&profile.chess960==null)
            ?'No published Daily rating available for this username.':'';
        }catch(error){
          if(state.username!==name)return;
          state.error='Player ratings could not be verified: '+(error.message||error);
          state.profile=null;
        }
      }
      void scan();
    };
    refresh();
  }
  async function scan(){
    const generation=++state.scanToken;
    state.fetching=true;state.upcoming=activeMatches();state.total=state.upcoming.length;state.scanned=0;
    refresh();
    const pending=state.upcoming.filter(m=>!state.details.has(String(m.matchId)));
    for(let i=0;i<pending.length;i+=4){
      if(generation!==state.scanToken)return;
      const batch=pending.slice(i,i+4),ids=batch.map(m=>m.matchId);
      try{
        const payload=await get(url('radar-batch',{ids:ids.join(','),player:state.username}));
        if(generation!==state.scanToken)return;
        for(const record of payload.rows||[]){
          state.details.set(String(record.matchId),record);
          const m=state.upcoming.find(x=>String(x.matchId)===String(record.matchId));
          if(m)demand(m,record);
        }
      }catch(error){
        if(generation!==state.scanToken)return;
        for(const item of batch)state.details.set(String(item.matchId),{
          matchId:item.matchId,verified:false,reason:'live_match_unavailable'
        });
        state.notice='Some live registrations could not be checked.';
      }
      if(generation!==state.scanToken)return;
      state.scanned=Math.min(state.upcoming.length,i+4);refresh();
    }
    if(generation!==state.scanToken)return;
    state.fetching=false;refresh();
  }
  (async()=>{
    try{
      const response=await get(url('config',{widget:'recruitment-radar'}));
      state.settings=response.settings||{};
      if(!state.settings.enabled&&document.body.dataset.preview!=='1'){
        main.innerHTML='<div class="p2kw-empty">Widget not published.</div>';return;
      }
      state.window=hoursFor[state.settings.window]?state.settings.window:'48h';
      const catalog=await get(new URL('server/events-showcase/public/api.php?action=catalog',ROOT));
      state.catalog=Array.isArray(catalog.matches)?catalog.matches:[];
      state.upcoming=activeMatches();shell();void scan();
    }catch(error){
      main.innerHTML='<p class="p2kw-empty">Recruitment catalogue temporarily unavailable: '+enc(error.message||error)+'</p>';
      status.textContent='Unavailable';
    }
  })();
})();
