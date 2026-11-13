/* P2K v2.15.9 — Competition: three existing, independently maintained widgets.
 * No data fetches or duplicate renderers. An inactive pane stays mounted, so
 * returning to Radar/Rivals preserves the user's search/selection and scroll.
 */
(() => {
  'use strict';
  if(document.body.dataset.widget !== 'competition')return;
  const main=document.getElementById('p2kwContent');
  const status=document.getElementById('p2kwStatus');
  const foot=document.getElementById('p2kwFoot');
  if(!main)return;

  const root=new URL('../../../',document.currentScript.src);
  const matchAssistant='https://www.promotetoking.org/';
  const explicitTheme=(document.documentElement.className.match(/(?:^|\s)p2kw-theme-(auto|light|dark)(?:\s|$)/)||[])[1]||'auto';
  const preview=document.body.dataset.preview==='1';
  const modes=[
    {id:'showcase',label:'Showcase',title:'Current P2K Match Showcase',
      path:'server/events-showcase/public/embed-card.php'},
    {id:'match-calls',label:'Match calls',title:'P2K Recruitment Radar',
      path:'server/widgets/public/embed.php',child:'recruitment-radar'},
    {id:'rivals',label:'Rivals',title:'P2K Rivalry Spotlight',
      path:'server/widgets/public/embed.php',child:'rivalry-spotlight'}
  ];
  const views=new Map();
  const loadStates=new Map();
  const viewportHeight=565;
  let active='showcase';

  // Use the Hall of Fame navigation surface, outside the scrollable view.
  const nav=document.createElement('nav');
  nav.className='p2kw-hof-tabs p2kw-competition-tabs';
  nav.setAttribute('role','tablist');
  nav.setAttribute('aria-label','Competition widget views');
  nav.innerHTML=modes.map((mode,index)=>
    '<button type="button" role="tab" id="p2kwCompetitionTab-'+mode.id+'"'+
    ' aria-controls="p2kwCompetitionPanel-'+mode.id+'"'+
    ' data-competition-tab="'+mode.id+'" aria-selected="'+(index===0)+'"'+
    ' tabindex="'+(index===0?'0':'-1')+'">'+mode.label+'</button>'
  ).join('');
  // Only the heading and selector use an opaque panel; the cards remain transparent.
  const chrome=document.createElement('div');
  chrome.className='p2kw-competition-chrome';
  const header=main.parentNode.querySelector('.p2kw-head');
  main.parentNode.insertBefore(chrome,main);
  if(header)chrome.append(header);
  chrome.append(nav);
  // One anchored footer in both Showcase and Match calls; none in Rivals.
  if(foot){
    foot.className='p2kw-foot p2kw-competition-assistant';
    const link=document.createElement('a');
    link.href=matchAssistant;
    link.target='_blank';
    link.rel='noopener noreferrer';
    link.textContent='Open P2K match selection assistant';
    foot.append(link);
  }
  function setStatus(id,value){
    const normalized=value==='LIVE'?'LIVE':value==='PARTIAL'?'PARTIAL':
      value==='Unavailable'?'Unavailable':'Loading…';
    loadStates.set(id,normalized);
    if(active===id&&status)status.textContent=normalized;
  }
  function observeChild(mode,frame){
    try{
      const doc=frame.contentDocument;
      const target=mode.id==='showcase'?
        doc?.documentElement:doc?.getElementById('p2kwStatus');
      if(!target){setStatus(mode.id,'Unavailable');return;}
      const refresh=()=>{
        const value=mode.id==='showcase'?
          target.getAttribute('data-p2kw-load-state'):target.textContent;
        const normalized=String(value||'').trim().toLowerCase();
        setStatus(mode.id,normalized==='live'?'LIVE':
          normalized==='partial'?'PARTIAL':
          normalized==='unavailable'?'Unavailable':'Loading…');
      };
      if(typeof MutationObserver!=='undefined'){
        new MutationObserver(refresh).observe(target,{
          attributes:true,childList:true,characterData:true,subtree:true,
          attributeFilter:['data-p2kw-load-state']
        });
      }
      refresh();
    }catch{
      // Do not claim a successful load if the child cannot be inspected.
      setStatus(mode.id,'Unavailable');
    }
  }
  main.innerHTML=
    '<div class="p2kw-competition">'+
      '<div class="p2kw-competition-views">'+
        modes.map((mode,index)=>
          '<section id="p2kwCompetitionPanel-'+mode.id+'" class="p2kw-competition-view"'+
          ' role="tabpanel" aria-labelledby="p2kwCompetitionTab-'+mode.id+'"'+
          (index===0?'':' hidden')+'></section>'
        ).join('')+
      '</div>'+
    '</div>';

  function mount(mode){
    if(views.has(mode.id))return views.get(mode.id);
    const pane=main.querySelector('#p2kwCompetitionPanel-'+mode.id);
    const childUrl=new URL(mode.path,root);
    childUrl.searchParams.set('theme',explicitTheme);
    if(mode.id==='showcase')childUrl.searchParams.set('via','competition');
    if(mode.child){
      childUrl.searchParams.set('widget',mode.child);
      childUrl.searchParams.set('via','competition');
      if(preview)childUrl.searchParams.set('preview','1');
    }
    const frame=document.createElement('iframe');
    frame.className='p2kw-competition-frame p2kw-competition-frame-'+mode.id;
    frame.title=mode.title;
    frame.width='260';
    frame.height=String(viewportHeight);
    frame.style.height='100%';
    frame.style.width='260px';
    frame.style.maxWidth='100%';
    frame.setAttribute('loading','eager');
    frame.setAttribute('scrolling','auto');
    frame.setAttribute('referrerpolicy','same-origin');
    frame.src=childUrl.href;
    pane.append(frame);

    frame.addEventListener('load',()=>observeChild(mode,frame));
    frame.addEventListener('error',()=>setStatus(mode.id,'Unavailable'));

    views.set(mode.id,pane);
    return pane;
  }

  function select(id,{focus=false}={}){
    const mode=modes.find(m=>m.id===id);
    if(!mode)return;
    active=mode.id;
    const pane=mount(mode);
    modes.forEach(item=>{
      const selected=item.id===mode.id;
      const tab=nav.querySelector('#p2kwCompetitionTab-'+item.id);
      const panel=main.querySelector('#p2kwCompetitionPanel-'+item.id);
      if(tab){tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;}
      if(panel)panel.hidden=!selected;
    });
    if(foot)foot.hidden=mode.id==='rivals';
    if(status)status.textContent=loadStates.get(mode.id)||'Loading…';
    main.scrollTop=0;
    if(focus)nav.querySelector('#p2kwCompetitionTab-'+mode.id)?.focus();
    return pane;
  }

  modes.forEach((mode,index)=>{
    const tab=nav.querySelector('#p2kwCompetitionTab-'+mode.id);
    if(!tab)return;
    tab.addEventListener('click',()=>select(mode.id));
    tab.addEventListener('keydown',event=>{
      let offset=0;
      if(event.key==='ArrowRight')offset=1;
      else if(event.key==='ArrowLeft')offset=-1;
      else if(event.key==='Home')offset=-index;
      else if(event.key==='End')offset=modes.length-1-index;
      else return;
      event.preventDefault();
      select(modes[(index+offset+modes.length)%modes.length].id,{focus:true});
    });
  });
  select(active);
})();
