/* P2K 2.15.3: six read-only embeddable widgets, consuming existing cached public data. */
(() => {
  "use strict";
  const id=document.body.dataset.widget;
  const allowed=['club-pulse','recruitment-radar','trophy-spotlight','rivalry-spotlight','club-progress','around-the-world'];
  if(!allowed.includes(id))return;
  const main=document.getElementById('p2kwContent');
  const status=document.getElementById('p2kwStatus');
  const foot=document.getElementById('p2kwFoot');
  const ROOT=new URL('../../../',new URL(document.currentScript.src));
  const WIDGET_API=new URL('server/widgets/public/api.php',ROOT);
  const PUB=new URL('server/team-points/public/public.php',ROOT);
  const INSIGHTS=new URL('server/team-points/public/team-insights.php',ROOT);
  const SHOWCASE=new URL('server/events-showcase/public/api.php',ROOT);
  const TROPHIES=new URL('server/trophy-gallery/public/api.php',ROOT);
  const OPPONENTS=new URL('server/team-points/public/opponents.php',ROOT);
  const fmt=new Intl.NumberFormat('en-GB',{maximumFractionDigits:1});
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const number=v=>Number.isFinite(Number(v))?fmt.format(Number(v)):'—';
  const text=v=>v===null||v===undefined||v===''?'—':String(v);
  const date=d=>{let value=new Date(d);return Number.isFinite(value.getTime())?new Intl.DateTimeFormat('en-GB',{day:'numeric',month:'short',timeZone:'UTC'}).format(value):'—';};
  const fullDay=d=>{const dt=new Date(d);return Number.isFinite(dt.getTime())?new Intl.DateTimeFormat('en-GB',{dateStyle:'medium',timeZone:'UTC'}).format(dt):'Unknown date';};
  function link(url) {try{const u=new URL(url,ROOT);return ['https:','http:'].includes(u.protocol)?u.href:'';}catch{return'';}}
  function element(cls,content){return `<div class="${cls}">${content}</div>`;}
  function kv(name,value){return `<div class="p2kw-metric"><strong>${esc(text(value))}</strong><span>${esc(name)}</span></div>`;}
  function seg(options,selected){const buttons=options.map(([value,label])=>`<button type="button" data-choice="${esc(value)}" aria-pressed="${value===selected?'true':'false'}">${esc(label)}</button>`).join('');return `<nav class="p2kw-seg" aria-label="Widget options">${buttons}</nav>`;}
  function wire(handler){main.querySelectorAll('[data-choice]').forEach(b=>b.addEventListener('click',()=>handler(b.dataset.choice)));}
  async function get(url){const u=new URL(url);const ac=new AbortController();const timer=setTimeout(()=>ac.abort(),18000);try{
    const response=await fetch(u.href,{credentials:'same-origin',signal:ac.signal,headers:{Accept:'application/json'}});
    const payload=await response.json();if(!response.ok||payload?.ok===false)throw new Error(payload?.error?.message||`HTTP ${response.status}`);return payload;
  }finally{clearTimeout(timer);}}
  function notifySize(){try{parent.postMessage({type:'p2k-widget-height',widget:id,height:Math.ceil(document.documentElement.scrollHeight)},'*');}catch{}}
  if('ResizeObserver' in window)new ResizeObserver(notifySize).observe(document.body);
  function setFoot(t){foot.textContent=t||'';notifySize();}
  function setStatus(t){status.textContent=t||'';}
  function spark(rows){const values=rows.map(v=>Number(v?.value??v)).filter(v=>Number.isFinite(v));if(values.length<2)return'<div class="p2kw-notice">No historical trend is available.</div>';const width=226,height=76,lo=Math.min(...values),hi=Math.max(...values),span=Math.max(1,hi-lo);const points=values.map((v,i)=>[(i*width/(values.length-1)).toFixed(1),(height-3-(v-lo)/span*(height-11)).toFixed(1)]);const poly=points.map((p,i)=>(i?'L':'M')+p[0]+' '+p[1]).join(' ');return `<svg class="p2kw-line" viewBox="0 0 ${width} ${height+5}" role="img" aria-label="Club performance trend"><path d="${poly}" stroke="var(--gold)" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/><path d="${poly} L${width} ${height+3} L0 ${height+3} Z" fill="var(--gold)" opacity=".09"/></svg>`;}
  let settings={};

  async function clubPulse(){
    const [a,b]=await Promise.allSettled([get(`${PUB}?action=team`),get(`${INSIGHTS}?section=progression`)]);
    const team=a.status==='fulfilled'?(a.value.team||{}):{};
    const actual=b.status==='fulfilled'?(b.value.data?.graphs?.scoreProgression?.actual||[]):[];
    const boards=b.status==='fulfilled'?(b.value.data?.graphs?.dailyBoards||[]):[];
    const n=settings.period==='30d'?30:7,series=actual.slice(-n-1),current=series.at(-1)?.value;
    const prev=series[0]?.value,delta=Number.isFinite(Number(current))&&Number.isFinite(Number(prev))?Number(current)-Number(prev):null;
    const total=team.club_points??current;
    const deltaText=delta===null?'—':(delta>=0?'+':'')+number(delta);
    const recent=boards.slice(-1)[0];
    main.innerHTML=`<div class="p2kw-total-row"><div><div class="p2kw-label">Club Points</div><div class="p2kw-big">${esc(number(total))}</div></div><div class="p2kw-delta${delta!==null&&delta<0?' negative':''}">${esc(deltaText)}<div class="p2kw-small">${n} days</div></div></div>${spark(series)}${seg([['7d','7 days'],['30d','30 days']],settings.period,()=>{})}<div class="p2kw-metric-grid">${kv('Finished matches',number(team.finished_matches))}${kv('Finished boards',number(team.finished_boards))}${kv('Members',settings.showMembers?number(team.current_members):'—')}</div><div class="p2kw-notice">Data: Green Team Points and published Insights. Scores reflect the latest available materialization.</div>`;
    wire(async v=>{settings.period=v;await clubPulse();});setFoot(actual.length?'Green data · '+date(actual.at(-1).date):'Green Team Points');
  }

  function classifyMatch(match){const name=String(match.name||'');const codes=['1WL','TCMAC','KOTML','TMCL','WKCL','PCL','CW','WCGC'];return match.category==='league'||codes.some(c=>new RegExp(`(^|[^A-Z0-9])${c}([^A-Z0-9]|$)`,'i').test(name));}
  async function recruitmentRadar(){
    const payload=await get(`${SHOWCASE}?action=catalog`);
    const all=Array.isArray(payload.matches)?payload.matches:[];
    const now=Date.now(),hours={'24h':24,'48h':48,'72h':72,'7d':168}[settings.window]||48;
    const upcoming=all.filter(m=>Number.isFinite(Number(m.startTime))&&Number(m.startTime)*1000>now).sort((a,b)=>Number(a.startTime)-Number(b.startTime));
    const soon=upcoming.filter(m=>Number(m.startTime)*1000<=now+hours*3600000);
    const league=soon.filter(classifyMatch),friendlies=soon.length-league.length;
    const list=soon.slice(0,Math.min(10,settings.limit));
    main.innerHTML=`<div class="p2kw-total-row"><div><div class="p2kw-label">Starting in ${esc(settings.window)}</div><div class="p2kw-big">${number(soon.length)}</div></div><div class="p2kw-small">${number(upcoming.length)} total scheduled</div></div>${seg([['24h','24h'],['48h','48h'],['72h','72h'],['7d','7d']],settings.window,()=>{})}<div class="p2kw-metric-grid">${kv('League',number(league.length))}${kv('Friendly',number(friendlies))}${kv('Next starts',upcoming.length?date(Number(upcoming[0].startTime)*1000):'—')}</div><div class="p2kw-subhead"><span>Upcoming registration</span><span class="p2kw-small">Earliest first</span></div>${list.map(m=>`<div class="p2kw-entry"><div class="p2kw-entry-main"><strong title="${esc(m.name||'')}">${esc(m.opponentName||m.name||'Match')}</strong><small>${esc(date(Number(m.startTime)*1000))} · ${classifyMatch(m)?'League':'Friendly'}</small></div>${link(m.url)?`<a class="p2kw-action" href="${esc(link(m.url))}" target="_blank" rel="noopener noreferrer">Join</a>`:''}</div>`).join('')||'<div class="p2kw-empty">No matches starting in this window.</div>'}<div class="p2kw-notice">Shows verified upcoming registrations. Player shortfalls are not inferred without live roster data.</div>`;
    wire(async v=>{settings.window=v;await recruitmentRadar();});setFoot('Source: current Green match catalogue');
  }

  async function trophySpotlight(){
    const payload=await get(`${TROPHIES}?action=list`);
    const records=Array.isArray(payload.records)?payload.records:[];
    if(!records.length){main.innerHTML='<div class="p2kw-empty">No published trophies are available yet.</div>';setFoot('Trophy Gallery');return;}
    const limit=Math.max(1,Math.min(20,settings.limit));let selected=records.slice(0,limit);
    if(settings.mode==='random'){
      // Stable daily rotation: different days feature different trophies without
      // making the widget jump to a different item on each page refresh.
      const day=Math.floor(Date.now()/86400000);
      const score=t=>{const txt=String(t.id||t.title||'')+'|'+day;let hash=2166136261;for(let i=0;i<txt.length;i++)hash=Math.imul(hash^txt.charCodeAt(i),16777619);return hash>>>0;};
      selected=[...selected].sort((a,b)=>score(a)-score(b));
    }
    let index=0;
    const draw=()=>{const t=selected[index],img=link(t.vignette_url||t.modal_url);const title=t.title||t.competition||'Trophy';const badge=t.award_date?date(t.award_date+'T12:00:00Z'):'Achievement';
      const safeLink=link(t.award_page||t.competition_page||'');
      main.innerHTML=`<div class="p2kw-label p2kw-center">${esc(t.league||t.competition||'Club achievement')}</div><div class="p2kw-trophy">${img?`<img src="${esc(img)}" alt="${esc(title)}" loading="lazy" referrerpolicy="no-referrer">`:'<span class="p2kw-trophy-fallback" aria-hidden="true">🏆</span>'}</div><div class="p2kw-trophy-name">${esc(title)}</div><div class="p2kw-small p2kw-center">${esc(badge)}</div><div class="p2kw-toolbar"><button type="button" data-prev aria-label="Previous trophy">‹</button><span class="p2kw-small">${index+1} / ${selected.length}</span><button type="button" data-next aria-label="Next trophy">›</button></div>${safeLink?`<div class="p2kw-center" style="margin-top:8px"><a class="p2kw-action" href="${esc(safeLink)}" target="_blank" rel="noopener noreferrer">View achievement</a></div>`:''}`;
      main.querySelector('[data-prev]').onclick=()=>{index=(index-1+selected.length)%selected.length;draw();};main.querySelector('[data-next]').onclick=()=>{index=(index+1)%selected.length;draw();};notifySize();
    };draw();setFoot(`${selected.length} published trophies · P2K gallery`);
  }

  async function rivalrySpotlight(){
    const q=new URL(OPPONENTS);q.searchParams.set('page_size','100');q.searchParams.set('sort','total');
    const p=await get(q);let rows=Array.isArray(p.rows)?p.rows:[];
    const needle=String(settings.opponent||'').trim().toLowerCase();
    if(needle&&!rows.some(r=>String(r.slug||'').toLowerCase()===needle)){
      const query=new URL(OPPONENTS);query.searchParams.set('search',needle);query.searchParams.set('page_size','100');
      try{const result=await get(query);const match=(result.rows||[]).find(r=>String(r.slug||'').toLowerCase()===needle);if(match)rows=[match,...rows];}catch(error){console.warn('Configured rival lookup unavailable',error);}
    }
    if(!rows.length){main.innerHTML='<div class="p2kw-empty">No opponent records are available.</div>';return;}
    const chosen=needle?rows.find(r=>String(r.slug||'').toLowerCase()===needle):null;
    const view=chosen?[chosen,...rows.filter(r=>r!==chosen).slice(0,Math.max(1,settings.limit)-1)]:rows.slice(0,Math.max(1,settings.limit));
    let index=0;
    const draw=()=>{
      const r=view[index],name=r.name||r.slug||'Opponent',total=Number(r.total)||0,w=Number(r.wins)||0,d=Number(r.draws)||0,l=Number(r.losses)||0,covered=w+d+l;
      const winRatio=covered?Math.round(100*w/covered):null;
      main.innerHTML=`<div class="p2kw-label p2kw-center">Recurring opponent</div><div class="p2kw-rival-name">⚔️ ${esc(name)}</div><div class="p2kw-big p2kw-center">${number(total)}</div><div class="p2kw-small p2kw-center">Recorded encounters</div><div class="p2kw-score-row"><div><strong class="p2kw-green">${number(w)}</strong><span>Wins</span></div><div><strong class="p2kw-gold">${number(d)}</strong><span>Draws</span></div><div><strong class="p2kw-red">${number(l)}</strong><span>Losses</span></div></div><div class="p2kw-subhead"><span>Results with known outcomes</span><span>${winRatio===null?'—':winRatio+'% wins'}</span></div><div class="p2kw-progress-track"><div class="p2kw-progress-fill" style="width:${winRatio===null?0:winRatio}%"></div></div><div class="p2kw-metric-grid">${kv('Ongoing',number(r.ongoing))}${kv('Registered',number(r.registered))}${kv('Results covered',number(covered))}</div><div class="p2kw-toolbar"><button type="button" data-prev>‹ Rival</button><span class="p2kw-small">${index+1} / ${view.length}</span><button type="button" data-next>Rival ›</button></div>${link(r.url)?`<div class="p2kw-center"><a class="p2kw-action" href="${esc(link(r.url))}" target="_blank" rel="noopener noreferrer">Opponent club</a></div>`:''}`;
      main.querySelector('[data-prev]').onclick=()=>{index=(index-1+view.length)%view.length;draw();};main.querySelector('[data-next]').onclick=()=>{index=(index+1)%view.length;draw();};notifySize();
    };
    // Configured opponents appear first even when outside the ordinary top-100 ranking.
    draw();setFoot('Green Analytics · historical match outcomes');
  }

  async function clubProgress(){
    const payload=await get(`${INSIGHTS}?section=progression`),graphs=payload.data?.graphs||{};
    const raw=settings.metric==='boards'?(graphs.dailyBoards||[]).map(x=>({date:x.date,value:Number(x.finished)||0})):(graphs.scoreProgression?.actual||[]);
    if(!raw.length){main.innerHTML='<div class="p2kw-empty">No historical progression series is available.</div>';return;}
    const len=settings.period==='12m'?366:30,rows=raw.slice(-len),value=rows.at(-1)?.value||0;
    const first=Number(rows[0]?.value)||0,last=Number(value)||0;
    const change=settings.metric==='boards'?rows.reduce((n,x)=>n+Number(x.value||0),0):last-first;
    const bars=rows.filter((_,i)=>i%Math.max(1,Math.floor(rows.length/24))===0).slice(-24);
    const max=Math.max(1,...bars.map(x=>Number(x.value)||0));
    main.innerHTML=`<div class="p2kw-total-row"><div><div class="p2kw-label">${settings.metric==='boards'?'Finished boards/day':'Club Points'}</div><div class="p2kw-big">${number(settings.metric==='boards'?change:last)}</div></div><div class="p2kw-delta">${settings.metric==='boards'?'Total in range':'+'+number(change)}<div class="p2kw-small">${settings.period}</div></div></div>${seg([['30d','30 days'],['12m','12 months']],settings.period,()=>{})}${settings.metric==='boards'?`<div class="p2kw-bars">${bars.map(x=>`<div class="p2kw-bar" style="height:${Math.max(3,Math.round(100*(Number(x.value)||0)/max))}%" title="${esc(x.date)}: ${number(x.value)}"></div>`).join('')}</div>`:spark(rows)}${seg([['points','Club Points'],['boards','Boards']],settings.metric,()=>{})}<div class="p2kw-subhead"><span>Period</span><span class="p2kw-small">${esc(date(rows[0].date+'T00:00:00Z'))} — ${esc(date(rows.at(-1).date+'T00:00:00Z'))}</span></div><div class="p2kw-notice">Historical, actual data only. Forecast values are excluded.</div>`;
    const buttons=main.querySelectorAll('[data-choice]');buttons.forEach(b=>b.onclick=async()=>{const val=b.dataset.choice;if(val==='points'||val==='boards')settings.metric=val;else settings.period=val;await clubProgress();});
    setFoot('Source: P2K Green Analytics · cumulative progression');
  }

  const coord={US:[56,48],CA:[54,30],MX:[48,67],BR:[89,98],AR:[82,115],GB:[121,34],FR:[124,44],DE:[133,41],ES:[118,53],IT:[136,53],PT:[111,55],NL:[129,39],BE:[126,42],LU:[129,43],CH:[132,46],AT:[138,45],PL:[142,39],CZ:[137,43],UA:[157,45],RU:[188,26],TR:[159,58],EG:[152,72],ZA:[158,111],NG:[135,85],IN:[191,81],PK:[180,75],CN:[213,61],JP:[234,70],KR:[229,69],AU:[235,112],NZ:[251,119],SE:[133,26],NO:[128,27],FI:[141,26],GR:[146,56],RO:[148,49],HU:[143,46],BG:[151,53],RS:[146,52],HR:[142,51],BA:[144,52],IL:[157,69],IR:[174,69],SA:[168,76],ID:[218,94],PH:[232,83],TH:[210,84],VN:[217,85],MY:[215,92],SG:[216,96],CO:[74,89],CL:[76,109],PE:[76,99],VE:[79,83],UY:[89,114],UY:[89,115],DK:[130,35],IE:[115,37],IS:[108,25],MA:[115,66],DZ:[130,70],TN:[135,64],KE:[156,91],ET:[160,85],TZ:[156,97],ZW:[159,105],BY:[152,40],LT:[150,36],LV:[149,33],EE:[149,30],GE:[165,56],AM:[165,58],AZ:[171,59],KZ:[187,46],UZ:[184,57],AF:[181,67],BD:[200,80],NP:[194,76],LK:[198,94],TW:[230,76],HK:[220,78],AE:[174,76],QA:[175,76]};
  const mapPaths=['M0 21L30 16L62 12L92 22L88 42L73 50L64 65L43 68L23 51L2 48Z','M71 69L91 68L106 84L104 104L88 131L74 105L69 82Z','M112 22L142 18L158 28L155 50L144 58L116 57L106 44Z','M128 57L159 58L179 77L166 121L145 127L132 104L122 80Z','M151 23L192 16L253 29L260 61L242 84L219 89L197 77L178 83L160 68L146 48Z','M216 97L247 96L260 117L245 131L221 125Z'];
  async function aroundWorld(){
    const p=await get(`${WIDGET_API}?action=countries`),rows=Array.isArray(p.countries)?p.countries:[];
    const metric=settings.metric==='clubs'?'clubs':'matches';
    const sorted=[...rows].sort((a,b)=>Number(b[metric]||0)-Number(a[metric]||0)),top=sorted.slice(0,Math.max(1,settings.limit));
    const sum=rows.reduce((n,r)=>n+Number(r[metric]||0),0);
    const names=new Intl.DisplayNames(['en'],{type:'region'});
    let selected=sorted[0]||null;
    const dots=rows.filter(x=>coord[x.country]).map(x=>{const [x0,y0]=coord[x.country],v=Math.max(1,Number(x[metric])||0);return `<circle tabindex="0" role="button" aria-label="${esc(names.of(x.country)||x.country)}: ${number(v)} ${metric}" data-country="${esc(x.country)}" class="p2kw-map-dot${selected?.country===x.country?' is-selected':''}" cx="${x0}" cy="${y0}" r="${Math.min(5,1.8+Math.log2(v)/3).toFixed(1)}"><title>${esc(names.of(x.country)||x.country)}: ${number(v)} ${metric}</title></circle>`;}).join('');
    main.innerHTML=`<div class="p2kw-total-row"><div><div class="p2kw-label">Known opponent countries</div><div class="p2kw-big">${number(rows.length)}</div></div><div class="p2kw-small">${number(sum)} ${metric} mapped</div></div>${seg([['clubs','Clubs'],['matches','Matches']],metric,()=>{})}<div class="p2kw-map"><svg class="p2kw-world-svg" viewBox="0 0 270 137" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Simplified global map of known opponent countries">${mapPaths.map(d=>`<path d="${d}"/>`).join('')}${dots}</svg></div><div class="p2kw-subhead" data-country-detail>${selected?esc(names.of(selected.country)||selected.country)+' · '+number(selected[metric])+' '+metric:'No classified countries'}</div><div class="p2kw-world-list">${top.map((r,i)=>`<div class="p2kw-entry"><div class="p2kw-entry-main"><strong>${i+1}. ${esc(names.of(r.country)||r.country)}</strong><small>Verified opponent-country code</small></div><button type="button" data-country="${esc(r.country)}" class="p2kw-chip" aria-label="Show ${esc(names.of(r.country)||r.country)}">${number(r[metric])}</button></div>`).join('')||'<div class="p2kw-empty">No verified opponent country data is available.</div>'}</div><div class="p2kw-notice">Map highlights countries with known stored codes only; unclassified opponents: ${number(p.unclassified)}. Map coordinates are schematic.</div>`;
    const choose=code=>{const current=rows.find(r=>r.country===code);if(!current)return;selected=current;const detail=main.querySelector('[data-country-detail]');if(detail)detail.textContent=(names.of(code)||code)+' · '+number(current[metric])+' '+metric;main.querySelectorAll('.p2kw-map-dot').forEach(dot=>dot.classList.toggle('is-selected',dot.dataset.country===code));};
    main.querySelectorAll('[data-country]').forEach(node=>{
      node.addEventListener('click',()=>choose(node.dataset.country));
      if(node instanceof SVGElement)node.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();choose(node.dataset.country);}});
    });
    wire(async v=>{settings.metric=v;await aroundWorld();});setFoot('Source: canonical P2K opponent metadata');
  }

  const renderers={'club-pulse':clubPulse,'recruitment-radar':recruitmentRadar,'trophy-spotlight':trophySpotlight,'rivalry-spotlight':rivalrySpotlight,'club-progress':clubProgress,'around-the-world':aroundWorld};
  async function init(){
    try{
      const response=await get(`${WIDGET_API}?action=config&widget=${encodeURIComponent(id)}`);
      settings=response.settings||{};
      const title=document.getElementById('p2kwTitle');if(title)title.textContent=settings.title||id;
      if(!settings.enabled && document.body.dataset.preview!=='1')return;
      await renderers[id]();setStatus('LIVE');
    }catch(error){console.warn('P2K widget load failed',id,error);main.innerHTML='<div class="p2kw-empty">Live widget data is temporarily unavailable.</div>';setStatus('Unavailable');setFoot('No illustrative figures displayed.');}
    notifySize();
  }
  void init();
})();
