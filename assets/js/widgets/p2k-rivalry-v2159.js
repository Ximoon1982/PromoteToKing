/* Promote to King v2.15.9 Rivalry Spotlight: all opponents, Green-only reads. */
(() => {
  'use strict';
  if(document.body.dataset.widget!=='rivalry-spotlight')return;
  const ROOT=new URL('../../../',document.currentScript.src);
  const API=new URL('server/widgets/public/api.php',ROOT);
  const main=document.getElementById('p2kwContent');
  const status=document.getElementById('p2kwStatus');
  const foot=document.getElementById('p2kwFoot');
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number=n=>Math.max(0,Number(n)||0).toLocaleString('en-GB');
  const state={
    page:1,expanded:false,pageData:null,mode:'directory',
    opponent:null,scope:'total',draft:'',suggestions:[],suggestPending:false,
    searchSeq:0,searchTimer:null,error:'',loadSeq:0,
  };
  const endpoint=(action,params={})=>{
    const url=new URL(API);url.searchParams.set('action',action);
    for(const [key,value] of Object.entries(params))url.searchParams.set(key,String(value));
    return url;
  };
  async function get(url){
    const controller=new AbortController();
    const timer=setTimeout(()=>controller.abort(),15000);
    try{
      const res=await fetch(String(url),{signal:controller.signal,credentials:'same-origin',
        headers:{Accept:'application/json'}});
      const data=await res.json().catch(()=>({}));
      if(!res.ok||data.ok===false)throw new Error(data?.error?.message||'HTTP '+res.status);
      return data;
    }finally{clearTimeout(timer);}
  }
  const img=opponent=>{
    const avatar=String(opponent?.avatar||'');
    return avatar.startsWith('https://')
      ?'<span class="p2kw-rival-avatar"><img src="'+esc(avatar)+'" loading="lazy" alt=""></span>'
      :'<span class="p2kw-rival-avatar is-fallback" aria-hidden="true">♜</span>';
  };
  function resultsLink(opponent){
    const raw=String(opponent?.url||'');
    try{
      const u=new URL(raw);
      if(u.protocol==='https:' && (u.hostname==='www.chess.com'||u.hostname==='chess.com')
        &&u.pathname.startsWith('/club/')){
        return '<a class="p2kw-action" href="'+esc(u.href)+'" target="_blank" rel="noopener noreferrer">Chess.com club</a>';
      }
    }catch{}
    return '';
  }
  function directoryHtml(){
    const data=state.pageData,rows=data?.rows||[],summary=data?.summary||{};
    const pagination=data?.pagination||{},totalPages=Math.max(1,Number(pagination.total_pages)||1);
    const compact=!state.expanded;
    return (compact?'<div class="p2kw-rival-overview">'+
      '<div class="p2kw-rival-stat"><strong>'+number(summary.different_opponents)+'</strong><span>Total opponents</span></div>'+
      '<div class="p2kw-rival-stat"><strong>'+number(summary.currently_playing)+'</strong><span>Currently playing</span></div></div>':'')+
      '<div class="p2kw-subhead p2kw-rival-subhead"><strong>'+(compact?'Most frequent opponents':'All opponents')+'</strong><span>Matches</span></div>'+
      '<div class="p2kw-rival-directory'+(state.expanded?' is-expanded':'')+'">'+
        (rows.length?rows.slice(0,compact?5:25).map((r,i)=>
           '<button type="button" class="p2kw-rival-row" data-rival-slug="'+esc(r.slug)+'">'+
           '<span class="p2kw-rival-position">'+number((state.page-1)*25+i+1)+'</span>'+
           img(r)+'<span class="p2kw-rival-text"><strong>'+esc(r.name||r.slug)+'</strong>'+
           '<small>'+esc(r.slug)+'</small></span>'+
           '<span class="p2kw-rival-count">'+number(r.total)+'</span></button>'
         ).join(''):'<p class="p2kw-empty">No recorded opponents.</p>')+
      '</div>'+
      (compact?'<button type="button" class="p2kw-rival-more" data-rival-expand>show more</button>':
        '<div class="p2kw-rival-pages">'+
        '<button type="button" data-rival-prev '+(state.page<=1?'disabled':'')+'>Previous</button>'+
        '<span>Page '+number(state.page)+' / '+number(totalPages)+'</span>'+
        '<button type="button" data-rival-next '+(state.page>=totalPages?'disabled':'')+'>Next</button></div>'+
        '<button type="button" class="p2kw-rival-more" data-rival-collapse>Back to top five</button>');
  }
  function detailHtml(){
    const payload=state.opponent||{},r=payload.opponent||{},sections=payload.results||{};
    const t=sections[state.scope]||{matches:0,wins:0,draws:0,losses:0,covered:0};
    const w=Math.max(0,Number(t.wins)||0),d=Math.max(0,Number(t.draws)||0),l=Math.max(0,Number(t.losses)||0);
    const n=w+d+l,pct=v=>n?Math.max(0,100*v/n):0,rate=n?Math.round(100*w/n):null;
    const scopeName={total:'Total',friendly:'Friendly',league:'League'}[state.scope];
    return '<button type="button" class="p2kw-rival-back" data-rival-back>‹ Back to opponents</button>'+
      '<div class="p2kw-rival-detailhead">'+img(r)+'<div><strong>'+esc(r.name||r.slug)+'</strong>'+
       '<small>'+esc(r.slug||'')+'</small></div></div>'+
      '<div class="p2kw-rival-total"><strong>'+number(r.total)+'</strong><span>Recorded encounters</span></div>'+
      '<nav class="p2kw-seg p2kw-rival-scopes" aria-label="Rivalry result category">'+
         ['total','friendly','league'].map(x=>'<button type="button" data-rival-scope="'+x+
           '" aria-pressed="'+(x===state.scope)+'">'+({total:'Total',friendly:'Friendly',league:'League'}[x])+'</button>').join('')+'</nav>'+
      '<div class="p2kw-score-row p2kw-rival-scores">'+
      '<div><strong class="p2kw-green">'+number(w)+'</strong><span>Wins</span></div>'+
      '<div><strong class="p2kw-gold">'+number(d)+'</strong><span>Draws</span></div>'+
      '<div><strong class="p2kw-red">'+number(l)+'</strong><span>Losses</span></div></div>'+
      '<div class="p2kw-subhead"><span>'+scopeName+' results</span><span>'+(rate===null?'—':rate+'% wins')+'</span></div>'+
      '<div class="p2kw-rival-resultbar" role="img" aria-label="'+esc(scopeName+': '+w+' wins, '+d+' draws, '+l+' losses')+'">'+
      '<span class="win" style="width:'+pct(w)+'%"></span>'+
      '<span class="draw" style="width:'+pct(d)+'%"></span>'+
      '<span class="loss" style="width:'+pct(l)+'%"></span></div>'+
      '<div class="p2kw-rival-coverage">'+number(n)+' / '+number(t.matches)+' finished matches with known results</div>'+
      '<div class="p2kw-metric-grid">'+
      '<div class="p2kw-metric"><strong>'+number(r.ongoing)+'</strong><span>Ongoing</span></div>'+
      '<div class="p2kw-metric"><strong>'+number(r.registered)+'</strong><span>Registered</span></div>'+
      '<div class="p2kw-metric"><strong>'+number(r.finished)+'</strong><span>Finished</span></div></div>'+
      (state.scope==='total'&&Number(payload.coverage?.category_gap)>0?
        '<p class="p2kw-rival-note">'+number(payload.coverage.category_gap)+' historical results have incomplete category classification.</p>':'')+
      '<div class="p2kw-rival-clublink">'+resultsLink(r)+'</div>';
  }
  function drawContent(){
    const holder=main.querySelector('#p2kwRivalContents');
    if(!holder)return;
    holder.innerHTML=state.mode==='detail'?detailHtml():directoryHtml();
    holder.querySelectorAll('[data-rival-slug]').forEach(b=>b.onclick=()=>void openDetail(b.dataset.rivalSlug));
    holder.querySelector('[data-rival-expand]')?.addEventListener('click',()=>{
      state.expanded=true;state.page=1;void loadPage(1);
    });
    holder.querySelector('[data-rival-collapse]')?.addEventListener('click',()=>{
      state.expanded=false;state.page=1;void loadPage(1);
    });
    holder.querySelector('[data-rival-prev]')?.addEventListener('click',()=>void loadPage(state.page-1));
    holder.querySelector('[data-rival-next]')?.addEventListener('click',()=>void loadPage(state.page+1));
    holder.querySelector('[data-rival-back]')?.addEventListener('click',()=>{
      state.mode='directory';state.opponent=null;drawContent();main.scrollTop=0;
    });
    holder.querySelectorAll('[data-rival-scope]').forEach(b=>b.onclick=()=>{
      state.scope=b.dataset.rivalScope;drawContent();
    });
    foot.textContent='';
  }
  function drawSuggestions(){
    const container=main.querySelector('#p2kwRivalSuggest');
    if(!container)return;
    const rows=state.suggestions;
    container.innerHTML=rows.length?
      '<div class="p2kw-rival-suggesttitle">Closest clubs</div>'+
      rows.map(r=>'<button type="button" class="p2kw-rival-suggestion" data-rival-suggestion="'+esc(r.slug)+'">'+
       img(r)+'<span><strong>'+esc(r.name||r.slug)+'</strong><small>'+esc(r.slug)+'</small></span>'+
       '<em>'+number(r.total)+'</em></button>').join(''):
       (state.draft.trim().length>=2&&!state.suggestPending?'<div class="p2kw-rival-suggestempty">No matching clubs.</div>':'');
    container.querySelectorAll('[data-rival-suggestion]').forEach(b=>b.onclick=()=>void openDetail(b.dataset.rivalSuggestion));
  }
  async function search(term){
    const epoch=++state.searchSeq;
    if(term.trim().length<2){state.suggestions=[];state.suggestPending=false;drawSuggestions();return;}
    state.suggestPending=true;
    try{
      const response=await get(endpoint('rivalry-search',{query:term.trim()}));
      if(epoch!==state.searchSeq)return;
      state.suggestions=response.results||[];state.error='';
    }catch(error){
      if(epoch!==state.searchSeq)return;
      state.suggestions=[];state.error=error.message||String(error);
    }
    state.suggestPending=false;drawSuggestions();
  }
  async function openDetail(slug){
    const epoch=++state.loadSeq;status.textContent='Loading…';
    try{
      const response=await get(endpoint('rivalry-detail',{slug}));
      if(epoch!==state.loadSeq)return;
      state.opponent=response;state.mode='detail';state.scope='total';
      state.draft='';state.suggestions=[];
      const input=main.querySelector('#p2kwRivalSearch');
      if(input)input.value='';
      drawSuggestions();drawContent();main.scrollTop=0;status.textContent='LIVE';
    }catch(error){
      if(epoch!==state.loadSeq)return;
      status.textContent='Unavailable';
      const holder=main.querySelector('#p2kwRivalContents');
      if(holder)holder.innerHTML='<p class="p2kw-empty">'+esc(error.message||error)+'</p>';
    }
  }
  async function loadPage(page){
    const epoch=++state.loadSeq;status.textContent='Loading…';
    try{
      const data=await get(endpoint('rivalry-page',{page}));
      if(epoch!==state.loadSeq)return;
      state.pageData=data;state.page=Number(data.pagination?.page)||1;
      state.mode='directory';drawContent();main.scrollTop=0;status.textContent='LIVE';
    }catch(error){
      if(epoch!==state.loadSeq)return;
      const holder=main.querySelector('#p2kwRivalContents');
      if(holder)holder.innerHTML='<p class="p2kw-empty">'+esc(error.message||error)+'</p>';
      status.textContent='Unavailable';
    }
  }
  function shell(){
    main.innerHTML='<form class="p2kw-search p2kw-rival-search" id="p2kwRivalForm" role="search">'+
       '<input id="p2kwRivalSearch" type="search" placeholder="Club name, slug or URL…" maxlength="250" spellcheck="false" autocomplete="off" aria-label="Find opponent club" value="'+esc(state.draft)+'">'+
       '<button type="submit">Find</button></form>'+
       '<div class="p2kw-rival-suggestions" id="p2kwRivalSuggest" aria-live="polite"></div>'+
       '<div id="p2kwRivalContents"></div>';
    const input=main.querySelector('#p2kwRivalSearch');
    input.addEventListener('input',()=>{
      state.draft=input.value;
      clearTimeout(state.searchTimer);
      state.searchTimer=setTimeout(()=>void search(input.value),240);
    });
    main.querySelector('#p2kwRivalForm').onsubmit=async event=>{
      event.preventDefault();
      const term=input.value.trim();
      if(term.length<2)return;
      await search(term);
      const extract=term.match(/(?:https?:\/\/(?:www\.)?chess\.com\/club\/|https?:\/\/api\.chess\.com\/pub\/club\/|\/?club\/)([a-z0-9_-]+)/i);
      const exact=extract?extract[1].toLowerCase():term.toLowerCase();
      const chosen=state.suggestions.find(r=>(r.slug||'').toLowerCase()===exact
        ||(r.name||'').toLowerCase()===exact);
      if(chosen)void openDetail(chosen.slug);
      else drawSuggestions(); // Ambiguous names: choose from several candidates.
    };
    drawContent();
  }
  (async()=>{
    try{
      const config=await get(endpoint('config',{widget:'rivalry-spotlight'}));
      if(config.settings?.enabled===false&&document.body.dataset.preview!=='1'){
        main.innerHTML='<p class="p2kw-empty">Widget not published.</p>';return;
      }
      shell();await loadPage(1);
    }catch(error){
      main.innerHTML='<p class="p2kw-empty">Opponent directory unavailable: '+esc(error.message||error)+'</p>';
      status.textContent='Unavailable';
    }
  })();
})();
