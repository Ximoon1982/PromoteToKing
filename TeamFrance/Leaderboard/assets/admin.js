(()=>{'use strict';
const $=id=>document.getElementById(id),tabs=['seasons','daily','arena','players'];let csrf='',tab='seasons',page=1,data=null,running=false,epoch=0,serial=0;
const f=new Intl.NumberFormat('en-GB',{maximumFractionDigits:2}),date=t=>Number(t)>0?new Date(Number(t)*1000).toLocaleString():'—';
function el(tag,text,cls){const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n}
function btn(text,fn,cls='secondary'){const b=el('button',text,cls);b.type='button';b.onclick=fn;return b}
function msg(text,error=false){$('message').textContent=text;$('message').className=error?'error':'ok'}
async function call(action,p,query={}){
 const q=new URLSearchParams({action,...query}),opt={cache:'no-store',credentials:'same-origin'};
 if(p!==undefined){opt.method='POST';opt.headers={'X-TF-CSRF':csrf};if(p instanceof FormData)opt.body=p;else{opt.headers['Content-Type']='application/json';opt.body=JSON.stringify(p)}}
 const res=await fetch('../api.php?'+q,opt);const j=await res.json();if(!res.ok||!j.ok)throw Error(j.error||'Request failed');if(j.busy)throw Error('Acquisition busy; retry shortly.');return j
}
function options(select,items,first){const previous=select.value;select.replaceChildren();if(first!==null){const o=el('option',first);o.value='0';select.append(o)}for(const s of items){const o=el('option',s.name+(Number(s.archived)?' · archived':''));o.value=s.id;select.append(o)}if([...select.options].some(x=>x.value===previous))select.value=previous}
function table(headers,rows){const t=el('table'),head=el('thead'),hr=el('tr');for(const h of headers)hr.append(el('th',h));head.append(hr);t.append(head);const body=el('tbody');for(const cells of rows){const tr=el('tr');for(const c of cells){const td=el('td');if(c instanceof Node)td.append(c);else td.textContent=String(c??'—');tr.append(td)}body.append(tr)}t.append(body);return t}
function seasonEdit(s){const form=$('season-form');for(const k of ['id','kind','name','start_date','end_date'])form.elements[k].value=s[k]??'';form.elements.archived.checked=!!Number(s.archived);form.elements.is_default.checked=!!Number(s.is_default);form.elements.kind.disabled=true;form.scrollIntoView({block:'nearest'})}
function showProgress(p){
 const j=p.job,counts=p.counts||[],area=$('progress');area.replaceChildren();
 $('start').disabled=j&&['running','paused'].includes(j.state);$('resume').disabled=!j||!['running','paused'].includes(j.state);$('pause').disabled=!j||j.state!=='running';$('stop').disabled=!j||!['running','paused'].includes(j.state);
 if(!j){area.append(el('p','No refresh has been started.','muted'));return}
 area.append(el('strong',j.state.replaceAll('_',' ')+' · '+j.phase+' · '+date(j.updated)));
 for(const [k,label] of [['daily','Daily matches'],['arena','Arenas'],['player','Players']]){const a=counts.filter(x=>x.kind===k),total=a.reduce((n,x)=>n+Number(x.n),0),done=a.filter(x=>x.state!=='pending').reduce((n,x)=>n+Number(x.n),0),errors=a.filter(x=>x.state==='error').reduce((n,x)=>n+Number(x.n),0);const row=el('div',undefined,'progress-row'),pr=el('progress');pr.max=Math.max(1,total);pr.value=done;row.append(el('span',label),pr,el('span',done+' / '+total+(errors?' · '+errors+' errors':'')));area.append(row)}
 if(p.transport&&p.transport.peak_in_flight!==undefined)area.append(el('p','OAuth peak in-flight: '+p.transport.peak_in_flight+' · controller '+Number(p.transport.rate_cps||0).toFixed(1)+' req/s','muted'));
 if(p.errors?.length){const d=el('details');d.append(el('summary','Acquisition errors (latest list)'));for(const x of p.errors)d.append(el('p',x.kind+' '+x.item+': '+x.error,'row-error'));area.append(d)}
}
function render(){
 const items=data.seasons||[];$('counts').textContent=items.length+' seasons · '+data.counts.events+' events · '+data.counts.players+' players · '+data.counts.excluded+' Fair Play exclusions';showProgress(data.progress);
 $('season-panel').hidden=tab!=='seasons';$('events-panel').hidden=!['daily','arena'].includes(tab);$('list-panel').hidden=tab==='seasons';
 for(const b of document.querySelectorAll('[data-tab]'))b.classList.toggle('active',b.dataset.tab===tab);
 $('seasons').replaceChildren(table(['Season','Category','Dates','Events','Status',''],items.map(s=>[s.name,s.kind,(s.start_date||'—')+' – '+(s.end_date||'—'),s.event_count,Number(s.archived)?'Archived':Number(s.is_default)?'Default':'Active',btn('Edit',()=>seasonEdit(s))])));
 if(tab==='seasons')return;
 const player=tab==='players',seasons=items.filter(s=>s.kind===tab);$('event-title').textContent=tab==='daily'?'Add Daily matches':'Add arenas';$('list-title').textContent=player?'Player account verification':tab==='daily'?'Assigned Daily matches':'Assigned arenas';
 options($('assign-form').elements.season_id,seasons,null);options($('season-filter'),seasons,'All seasons');$('season-filter').hidden=player;$('index-button').hidden=tab!=='daily';
 const rows=data.rows||[];const records=$('records');records.replaceChildren();
 if(player){records.append(table(['Player','Account status','Verified','Error',''],rows.map(x=>[x.display+(x.display.toLowerCase()!==x.username_key?' ('+x.username_key+')':''),x.account_status,date(x.verified),el('span',x.error,'row-error'),btn('Verify',()=>mutate('verify-player',{username:x.username_key}))])))}
 else{records.append(table(['Event','Season','Status','Players / points','Last check','Actions'],rows.map(x=>{
  const name=el('div'),link=el('a',x.name||(tab==='daily'?'Match':'Arena')+' #'+x.event_id+' (awaiting name)');link.href=tab==='daily'?'https://www.chess.com/club/matches/'+encodeURIComponent(x.event_id):x.url;link.target='_blank';link.rel='noopener noreferrer';name.append(link);if(x.error)name.append(el('div',x.error,'row-error'));
  const controls=el('div'),move=el('select');options(move,seasons,null);move.value=String(x.season_id);controls.append(btn('Refresh',()=>mutate('refresh-event',{event:x.id})),move,btn('Move',()=>mutate('move',{event:x.id,season:Number(move.value)})),btn('Remove',()=>{if(confirm('Remove this event and its contribution?'))void mutate('remove',{event:x.id})}));
  if(tab==='arena')controls.append(btn('Upload CSV',()=>{$('csv-form').reset();$('csv-form').elements.event.value=x.id;$('csv-dialog').showModal()}));
  return [name,x.season,x.status,x.participants+' / '+f.format(x.points),date(x.checked),controls]
 })))}
 const nav=$('pagination');nav.replaceChildren();const prev=btn('Previous',()=>{page--;void load()}),next=btn('Next',()=>{page++;void load()});prev.disabled=page<=1;next.disabled=page>=data.pages;nav.append(prev,el('span','Page '+page+' / '+data.pages+' · '+data.total+' records · 25 per page'),next)
}
async function load(){const ticket=++serial;try{const x=await call('admin',undefined,{kind:tab==='players'?'players':tab==='arena'?'arena':'daily',season:$('season-filter').value||0,q:$('query').value,page});if(ticket!==serial)return;data=x;page=x.page;csrf=x.csrf;render()}catch(e){if(ticket===serial)msg(e.message,true)}}
async function mutate(action,p){try{await call(action,p);msg('Saved.');await load();return true}catch(e){msg(e.message,true);return false}}
async function drive(){
 if(running)return;running=true;const ticket=++epoch;
 try{while(ticket===epoch){const p=await call('step',{});showProgress(p);if(p.job?.state!=='running')break;await new Promise(resolve=>setTimeout(resolve,p.wait?2000:350))}}
 catch(e){msg(e.message,true)}finally{running=false;await load()}
}
async function control(action){try{if(action==='pause'||action==='stop'){epoch++;while(running)await new Promise(resolve=>setTimeout(resolve,100))}const p=await call(action,{});showProgress(p);msg(action==='start'?'Refresh started.':action==='resume'?'Refresh resumed.':'Refresh '+action+' requested.');if(action==='start'||action==='resume'){if(!running)void drive()}else await load()}catch(e){msg(e.message,true)}}
for(const id of ['start','pause','resume','stop'])$(id).onclick=()=>void control(id);
$('recalc').onclick=()=>void mutate('recalculate',{});
for(const b of document.querySelectorAll('[data-tab]'))b.onclick=()=>{tab=b.dataset.tab;page=1;$('query').value='';$('season-filter').value='0';$('index-results').hidden=true;void load()};
$('query').onchange=()=>{page=1;void load()};$('season-filter').onchange=()=>{page=1;void load()};$('reload').onclick=()=>void load();
$('season-form').onreset=()=>{setTimeout(()=>{$('season-form').elements.id.value='';$('season-form').elements.kind.disabled=false},0)};
$('season-form').onsubmit=async e=>{e.preventDefault();const form=e.currentTarget,p=Object.fromEntries(new FormData(form));p.kind=form.elements.kind.value;p.archived=form.elements.archived.checked;p.is_default=form.elements.is_default.checked;if(await mutate('save-season',p))form.reset()};
$('assign-form').onsubmit=async e=>{e.preventDefault();const form=e.currentTarget;if(await mutate('assign',Object.fromEntries(new FormData(form))))form.elements.references.value=''};
$('index-button').onclick=async()=>{try{const j=await call('club-index');const box=$('index-results');box.hidden=false;box.replaceChildren();const find=el('input');find.placeholder='Filter match name or ID';box.append(find);const list=el('div'),selected=new Set(),nav=el('div',undefined,'actions');let indexPage=1;box.append(list,nav);const render=()=>{list.replaceChildren();nav.replaceChildren();const q=find.value.toLowerCase(),filtered=j.matches.filter(x=>(x.name+' '+x.id).toLowerCase().includes(q)),pages=Math.max(1,Math.ceil(filtered.length/25));indexPage=Math.min(indexPage,pages);for(const x of filtered.slice((indexPage-1)*25,indexPage*25)){const l=el('label',undefined,'check'),c=el('input');c.type='checkbox';c.checked=selected.has(x.id);c.onchange=()=>{if(c.checked)selected.add(x.id);else selected.delete(x.id);selection.textContent=selected.size+' selected'};l.append(c,el('span',x.name+' · '+x.status));list.append(l)}const prev=btn('Previous',()=>{indexPage--;render()}),next=btn('Next',()=>{indexPage++;render()});prev.disabled=indexPage<=1;next.disabled=indexPage>=pages;nav.append(prev,el('span','Page '+indexPage+' / '+pages+' · '+filtered.length+' matches · 25 per page'),next)};find.oninput=()=>{indexPage=1;render()};const selection=el('span','0 selected','muted');box.append(selection,btn('Use selected matches',()=>{const field=$('assign-form').elements.references;field.value=[field.value,...selected].filter(Boolean).join('\n');box.hidden=true}));render()}catch(e){msg(e.message,true)}};
$('csv-form').onsubmit=async e=>{e.preventDefault();if(await mutate('import-csv',new FormData(e.currentTarget)))$('csv-dialog').close()};$('close-csv').onclick=()=>$('csv-dialog').close();
$('embed').value='<iframe src="'+new URL('../',location.href).href+'" width="260" height="590" style="border:0" loading="lazy" title="Team France – Classements"></iframe>';
$('copy').onclick=async()=>{try{await navigator.clipboard.writeText($('embed').value);msg('Embed copied.')}catch{$('embed').select();msg('Select and copy the embed HTML.')}};
void load();
})();