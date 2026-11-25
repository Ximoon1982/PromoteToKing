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
function seasonExport(s){const a=el('a','Download CSV');a.href='../api.php?'+new URLSearchParams({action:'export-csv',kind:s.kind,season:String(s.id)});a.download='';return a}
function seasonEdit(s){const form=$('season-form');for(const k of ['id','kind','name','start_date','end_date'])form.elements[k].value=s[k]??'';form.elements.archived.checked=!!Number(s.archived);form.elements.is_default.checked=!!Number(s.is_default);form.elements.kind.disabled=true;form.scrollIntoView({block:'nearest'})}
function showProgress(p){
 const j=p.job,counts=p.counts||[],area=$('progress');area.replaceChildren();
 $('start').disabled=j&&['running','paused'].includes(j.state);$('include-archived').disabled=$('start').disabled;$('resume').disabled=!j||!['running','paused'].includes(j.state);$('pause').disabled=!j||j.state!=='running';$('stop').disabled=!j||!['running','paused'].includes(j.state);
 if(!j){area.append(el('p','No refresh has been started.','muted'));return}
 area.append(el('strong',j.state.replaceAll('_',' ')+' · '+j.phase+' · '+date(j.updated)),el('p',p.include_archived?'Scope: active and archived seasons':'Scope: active seasons only','muted'));if(p.archive_publication==='retained_due_to_errors')area.append(el('p','Archived standings retained: acquisition errors prevented republication.','row-error'));
 for(const [k,label] of [['daily','Daily matches'],['arena','Arenas'],['player','Players']]){const a=counts.filter(x=>x.kind===k),total=a.reduce((n,x)=>n+Number(x.n),0),done=a.filter(x=>x.state!=='pending').reduce((n,x)=>n+Number(x.n),0),errors=a.filter(x=>x.state==='error').reduce((n,x)=>n+Number(x.n),0);const row=el('div',undefined,'progress-row'),pr=el('progress');pr.max=Math.max(1,total);pr.value=done;row.append(el('span',label),pr,el('span',done+' / '+total+(errors?' · '+errors+' errors':'')));area.append(row)}
 if(p.transport&&p.transport.peak_in_flight!==undefined)area.append(el('p','OAuth peak in-flight: '+p.transport.peak_in_flight+' · controller '+Number(p.transport.rate_cps||0).toFixed(1)+' req/s','muted'));
 if(p.errors?.length){const d=el('details');d.append(el('summary','Acquisition errors (latest list)'));for(const x of p.errors)d.append(el('p',x.kind+' '+x.item+': '+x.error,'row-error'));area.append(d)}
}
function render(){
 const items=data.seasons||[];$('counts').textContent=items.length+' seasons · '+data.counts.events+' events · '+data.counts.players+' players · '+data.counts.excluded+' Fair Play exclusions';showProgress(data.progress);
 $('season-panel').hidden=tab!=='seasons';$('events-panel').hidden=!['daily','arena'].includes(tab);$('list-panel').hidden=tab==='seasons';
 for(const b of document.querySelectorAll('[data-tab]'))b.classList.toggle('active',b.dataset.tab===tab);
 $('seasons').replaceChildren(table(['Season','Category','Dates','Events','Ongoing','Status','CSV',''],items.map(s=>[s.name,s.kind,(s.start_date||'—')+' – '+(s.end_date||'—'),s.event_count,s.kind==='daily'?Number(s.ongoing_count||0):'—',Number(s.archived)?'Archived':Number(s.is_default)?'Default':'Active',seasonExport(s),btn('Edit',()=>seasonEdit(s))])));
 if(tab==='seasons')return;
 const player=tab==='players',seasons=items.filter(s=>s.kind===tab);$('event-title').textContent=tab==='daily'?'Add Daily matches':'Add arenas';$('list-title').textContent=player?'Player account verification':tab==='daily'?'Assigned Daily matches':'Assigned arenas';
 options($('assign-form').elements.season_id,seasons.filter(s=>!Number(s.archived)),null);options($('season-filter'),seasons,'All seasons');$('season-filter').hidden=player;$('index-button').hidden=tab!=='daily';$('arena-discovery-options').hidden=tab!=='arena';if(chooser&&(chooser.season!==String(selectedSeason()?.id)||chooser.kind!==tab||chooser.range!==seasonRange(selectedSeason())))closeChooser();updateArenaRange();
 const rows=data.rows||[];const records=$('records');records.replaceChildren();
 if(player){records.append(table(['Player','Account status','Verified','Error',''],rows.map(x=>[x.display+(x.display.toLowerCase()!==x.username_key?' ('+x.username_key+')':''),x.account_status,date(x.verified),el('span',x.error,'row-error'),btn('Verify',()=>mutate('verify-player',{username:x.username_key}))])))}
 else{records.append(table(['Event','Season','Status','Players / points','Last check','Actions'],rows.map(x=>{
  const name=el('div'),link=el('a',x.name||(tab==='daily'?'Match':'Arena')+' #'+x.event_id+' (awaiting name)');link.href=tab==='daily'?'https://www.chess.com/club/matches/'+encodeURIComponent(x.event_id):x.url;link.target='_blank';link.rel='noopener noreferrer';name.append(link);if(x.error)name.append(el('div',x.error,'row-error'));
  const controls=el('div'),move=el('select');if(seasons.some(s=>Number(s.id)===Number(x.season_id)&&Number(s.archived)))return [name,x.season,x.status,x.participants+' / '+f.format(x.points),date(x.checked),el('span','Frozen · unarchive to edit','muted')];options(move,seasons.filter(s=>!Number(s.archived)),null);move.value=String(x.season_id);controls.append(btn('Refresh',()=>mutate('refresh-event',{event:x.id})),move,btn('Move',()=>mutate('move',{event:x.id,season:Number(move.value)})),btn('Remove',()=>{if(confirm('Remove this event and its contribution?'))void mutate('remove',{event:x.id})}));
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
async function control(action){try{if(action==='pause'||action==='stop'){epoch++;while(running)await new Promise(resolve=>setTimeout(resolve,100))}const p=await call(action,action==='start'?{include_archived:$('include-archived').checked}:{});if(action==='start')$('include-archived').checked=false;showProgress(p);msg(action==='start'?'Refresh started.':action==='resume'?'Refresh resumed.':'Refresh '+action+' requested.');if(action==='start'||action==='resume'){if(!running)void drive()}else await load()}catch(e){msg(e.message,true)}}
for(const id of ['start','pause','resume','stop'])$(id).onclick=()=>void control(id);
$('recalc').onclick=()=>void mutate('recalculate',{});
for(const b of document.querySelectorAll('[data-tab]'))b.onclick=()=>{tab=b.dataset.tab;page=1;$('query').value='';$('season-filter').value='0';closeChooser();void load()};
$('query').onchange=()=>{page=1;void load()};$('season-filter').onchange=()=>{page=1;void load()};$('reload').onclick=()=>void load();
$('season-form').onreset=()=>{setTimeout(()=>{$('season-form').elements.id.value='';$('season-form').elements.kind.disabled=false},0)};
$('season-form').onsubmit=async e=>{e.preventDefault();const form=e.currentTarget,p=Object.fromEntries(new FormData(form));p.kind=form.elements.kind.value;p.archived=form.elements.archived.checked;p.is_default=form.elements.is_default.checked;if(await mutate('save-season',p))form.reset()};
$('assign-form').onsubmit=async e=>{e.preventDefault();const form=e.currentTarget;const p=Object.fromEntries(new FormData(form));p.name_hints=namesBySeason.get(String(p.season_id))||{};if(await mutate('assign',p)){namesBySeason.delete(String(p.season_id));form.elements.references.value='';closeChooser()}};
// Both event choosers share a 25-row filtered, multi-page selection model.
let chooser=null,discoveryEpoch=0,scanning=false;const namesBySeason=new Map();
function selectedSeason(){return data?.seasons?.find(s=>String(s.id)===$('assign-form').elements.season_id.value)}
function seasonRange(s){return (s?.start_date||'')+'|'+(s?.end_date||'')}
function updateArenaRange(){const s=selectedSeason(),valid=tab==='arena'&&s?.start_date&&s?.end_date;$('arena-season-range').textContent=valid?'Season dates: '+s.start_date+' to '+s.end_date+' (inclusive)':'Set both season dates before searching arenas.';$('arena-scan').disabled=!valid||scanning;$('arena-stop').disabled=!scanning}
function status(text){$('discovery-status').hidden=!text;$('discovery-status').textContent=text}
function closeChooser(){discoveryEpoch++;scanning=false;chooser=null;$('index-results').hidden=true;status('');updateArenaRange()}
$('assign-form').elements.season_id.onchange=()=>{closeChooser();updateArenaRange()};
function createChooser(kind,season){
 const box=$('index-results');box.hidden=false;box.replaceChildren();
 const find=el('input');find.placeholder=kind==='arena'?'Filter arena name, date or ID':'Filter match name or ID';find.setAttribute('aria-label',find.placeholder);
 const controls=el('div',undefined,'actions'),list=el('div'),nav=el('div',undefined,'actions'),summary=el('p',undefined,'hint');
 const c={kind,season:String(season.id),range:seasonRange(season),rows:new Map(),selected:new Set(),page:1,find,render:null};
 const filtered=()=>{const q=find.value.trim().toLocaleLowerCase();return [...c.rows.values()].filter(x=>(x.name+' '+x.id+' '+(x.date||'')+' '+(x.status||'')).toLocaleLowerCase().includes(q)).sort((a,b)=>kind==='arena'?String(b.date).localeCompare(String(a.date))||String(a.name).localeCompare(String(b.name)):0)};
 const available=x=>!x.assigned;
 const refreshSummary=()=>{const rows=filtered();summary.textContent=c.selected.size+' selected · '+rows.filter(available).length+' available in current filter';select.disabled=!rows.some(x=>available(x)&&!c.selected.has(x.id));clear.disabled=!rows.some(x=>c.selected.has(x.id));reset.disabled=use.disabled=!c.selected.size};
 const select=btn('Select all filtered',()=>{for(const x of filtered())if(available(x))c.selected.add(x.id);c.render()});
 const clear=btn('Deselect all filtered',()=>{for(const x of filtered())c.selected.delete(x.id);c.render()});
 const reset=btn('Clear selection',()=>{c.selected.clear();c.render()});
 const use=btn('Use selected '+(kind==='arena'?'arenas':'matches'),()=>{if(c!==chooser||c.season!==String(selectedSeason()?.id)||c.range!==seasonRange(selectedSeason()))return;const field=$('assign-form').elements.references,refs=[...c.selected].map(id=>c.rows.get(id)).filter(x=>x&&available(x)).map(x=>x.url||x.id);field.value=[...new Set([...field.value.split(/[\r\n,]+/).map(x=>x.trim()).filter(Boolean),...refs])].join('\n');const hints=namesBySeason.get(c.season)||{};for(const id of c.selected){const x=c.rows.get(id);if(x&&available(x))hints[id]=x.name}namesBySeason.set(c.season,hints);c.selected.clear();c.render();msg(refs.length+' events copied to the input. Click Add events to assign them.');});
 controls.append(select,clear,reset,use);box.append(find,controls,summary,list,nav);
 c.render=()=>{
  const rows=filtered(),pages=Math.max(1,Math.ceil(rows.length/25));c.page=Math.max(1,Math.min(c.page,pages));list.replaceChildren();nav.replaceChildren();
  if(!rows.length)list.append(el('p','No matching events.','muted'));
  for(const x of rows.slice((c.page-1)*25,c.page*25)){
   const l=el('label',undefined,'check'),check=el('input');check.type='checkbox';check.disabled=!available(x);check.checked=check.disabled||c.selected.has(x.id);
   check.onchange=()=>{if(check.checked)c.selected.add(x.id);else c.selected.delete(x.id);refreshSummary()};
   const text=el('span'),link=el('a',x.name||'#'+x.id);link.href=x.url;link.target='_blank';link.rel='noopener noreferrer';text.append(link,document.createTextNode((x.date?' · '+x.date:'')+(x.status?' · '+x.status:'')+(x.assigned?' · Assigned: '+x.assigned.season:'')));l.append(check,text);list.append(l);
  }
  const prev=btn('Previous',()=>{c.page--;c.render()}),next=btn('Next',()=>{c.page++;c.render()});prev.disabled=c.page<=1;next.disabled=c.page>=pages;nav.append(prev,el('span','Page '+c.page+' / '+pages+' · '+rows.length+' events · 25 per page'),next);
  refreshSummary();
 };
 find.oninput=()=>{c.page=1;c.render()};chooser=c;c.render();return c
}
function addChoices(c,rows){for(const x of rows){const id=String(x.id);c.rows.set(id,{...x,id});if(x.assigned)c.selected.delete(id)}c.render()}
$('index-button').onclick=async()=>{
 const season=selectedSeason();if(!season){msg('Create and select a Daily season first.',true);return}
 closeChooser();const ticket=discoveryEpoch,c=createChooser('daily',season);status('Loading club match index…');
 try{const j=await call('club-index');if(ticket!==discoveryEpoch||c!==chooser)return;addChoices(c,j.matches);status(j.matches.length+' Daily matches found.')}catch(e){if(ticket===discoveryEpoch)status(e.message)}
};
$('arena-stop').onclick=()=>{discoveryEpoch++;scanning=false;status('Search stopped. Previously found arenas remain selectable.');updateArenaRange()};
$('arena-scan').onclick=async()=>{
 const season=selectedSeason();if(!season?.start_date||!season?.end_date){msg('Set both arena season dates first.',true);return}
 const organizer=$('arena-organizer').value.trim();if(!organizer){msg('Enter an organizer club URL or slug.',true);return}
 const c=chooser?.kind==='arena'&&chooser.season===String(season.id)?chooser:createChooser('arena',season);
 const ticket=++discoveryEpoch;scanning=true;updateArenaRange();let scanned=0,unknown=0,outside=0,pages=0;const seen=new Set();
 try{
  for(let p=1;p<=250;p++){
   if(ticket!==discoveryEpoch)return;
   status('Searching '+organizer+' · page '+p+' · '+c.rows.size+' arenas found');
   const j=await call('arena-index',{season_id:Number(season.id),organizer,page:p,start_date:season.start_date,end_date:season.end_date});
   if(ticket!==discoveryEpoch||chooser!==c)return;
   if(seen.has(j.fingerprint)){status('Repeated index page detected; search incomplete. '+c.rows.size+' arenas retained.');return}
   seen.add(j.fingerprint);pages++;scanned+=Number(j.scanned||0);unknown+=Number(j.unknown_dates||0);outside+=Number(j.outside_range||0);addChoices(c,j.events);
   if(!j.has_next){status('Search complete · '+pages+' pages · '+scanned+' index rows · '+c.rows.size+' unique arenas available in this season’s search · '+outside+' outside dates · '+unknown+' undated skipped.');return}
   if(p===Number(j.limit||250)){status('Page limit reached; search incomplete. '+c.rows.size+' arenas retained.');return}
  }
 }catch(e){if(ticket===discoveryEpoch)status('Search incomplete: '+e.message+' · '+c.rows.size+' arenas retained.')}
 finally{if(ticket===discoveryEpoch){scanning=false;updateArenaRange()}}
};

$('csv-form').onsubmit=async e=>{e.preventDefault();if(await mutate('import-csv',new FormData(e.currentTarget)))$('csv-dialog').close()};$('close-csv').onclick=()=>$('csv-dialog').close();
$('embed').value='<iframe src="'+new URL('../',location.href).href+'" width="260" height="590" style="border:0" loading="lazy" title="Team France – Classements"></iframe>';
$('copy').onclick=async()=>{try{await navigator.clipboard.writeText($('embed').value);msg('Embed copied.')}catch{$('embed').select();msg('Select and copy the embed HTML.')}};
void load();
})();