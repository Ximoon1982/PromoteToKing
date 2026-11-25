(()=>{'use strict';
const $=id=>document.getElementById(id),kinds=['daily','arena'],titles={daily:'Matchs en différé',arena:'Arènes multiclubs'},state={daily:{id:0,expanded:false,page:1},arena:{id:0,expanded:false,page:1}},fmt=new Intl.NumberFormat('fr-FR',{maximumFractionDigits:2});
let user='',sequence=0;
function el(tag,text,cls){const x=document.createElement(tag);if(text!==undefined)x.textContent=text;if(cls)x.className=cls;return x}
function button(text,fn){const b=el('button',text);b.type='button';b.onclick=fn;return b}
function choose(name){user=name;$('username').value=name;void load()}
function profileLink(name){const a=el('a',name,'name');a.href='https://www.chess.com/member/'+encodeURIComponent(name);a.target='_blank';a.rel='noopener noreferrer';return a}
function toggleExpanded(kind){
 const expanded=!state[kind].expanded;
 for(const k of kinds){state[k].expanded=k===kind&&expanded;state[k].page=1}
 void load()
}
function showRankings(){
 const expanded=kinds.find(k=>state[k].expanded);
 $('widget').classList.toggle('expanded-view',Boolean(expanded));$('profile').hidden=true;
 for(const k of kinds)$(k).hidden=Boolean(expanded&&k!==expanded)
}
function avatar(row,large=false){
 const wrap=el('span',undefined,'avatar'+(large?' avatar-large':''));
 const fallback=el('span',(row.username||'?').slice(0,1).toUpperCase(),'avatar-fallback');fallback.setAttribute('aria-hidden','true');wrap.append(fallback);
 if(typeof row.avatar==='string'&&/^https:\/\/images\.chesscomfiles\.com\/[^\s]+$/i.test(row.avatar)){const image=el('img');image.alt='';image.loading='lazy';image.referrerPolicy='no-referrer';image.addEventListener('error',()=>image.remove(),{once:true});image.src=row.avatar;wrap.append(image)}
 return wrap
}
function eventLabel(kind,n){n=Number(n)||0;return fmt.format(n)+(kind==='daily'?(n===1?' match':' matchs'):(n===1?' arène':' arènes'))}
function renderSection(kind,section){
 const node=$(kind),select=node.querySelector('select'),rank=node.querySelector('.ranking'),pager=node.querySelector('.pager'),s=state[kind];s.id=section.season?.id||0;s.page=section.page;
 select.replaceChildren();for(const season of section.seasons){const o=el('option',season.name+(Number(season.archived)?' · archives':''));o.value=season.id;select.append(o)}if(!section.seasons.length){const o=el('option','Aucune saison');o.value='0';select.append(o)}select.value=String(s.id);select.disabled=!section.seasons.length;
 rank.classList.toggle('expanded',s.expanded);rank.replaceChildren();pager.replaceChildren();
 for(const row of section.rows){
  const r=el('div',undefined,'rank-row');r.append(el('span',String(row.rank),'rank medal'+row.rank));
  const detail=button('',()=>choose(row.username));detail.className='avatar-button';detail.title='Voir les statistiques de '+row.username;detail.setAttribute('aria-label',detail.title);detail.append(avatar(row));
  const info=el('div',undefined,'player-info'),name=button(row.username,()=>choose(row.username));name.className='name';name.title='Voir les statistiques de '+row.username;info.append(name,el('span',eventLabel(kind,row.events),'event-count'));
  r.append(detail,info,el('span',fmt.format(row.points),'points'));rank.append(r)
 }
 if(!section.rows.length)rank.append(el('div',section.season?'Aucun point enregistré.':'Aucune saison disponible.','muted'));
 if(section.total>5||s.expanded)pager.append(button(s.expanded?'Voir moins':'Voir plus',()=>toggleExpanded(kind)));
 if(s.expanded&&section.pages>1){const prev=button('‹',()=>{s.page--;void load()}),next=button('›',()=>{s.page++;void load()});prev.disabled=s.page<=1;next.disabled=s.page>=section.pages;pager.append(prev,el('span',s.page+' / '+section.pages),next)}
}
function renderProfile(p){
 $('widget').classList.toggle('expanded-view',false);const node=$('profile');node.replaceChildren();node.hidden=false;for(const k of kinds)$(k).hidden=true;
 const back=button('← Retour aux classements',()=>{user='';$('username').value='';void load()});back.className='back';node.append(back);
 const profile=p.profile,player=p.player;
 if(!profile||!player){node.append(el('h2',user,'profile-name'),el('p','Joueur introuvable dans les classements.','muted'));return}
 const heading=el('div',undefined,'profile-heading'),name=el('h2',undefined,'profile-name');name.append(profileLink(player.username));heading.append(avatar(player,true),name);node.append(heading);
 for(const k of kinds){const card=el('section',undefined,'panel profile-card');card.append(el('h2',titles[k]),el('div',p.sections[k].season?.name||'Aucune saison','profile-season'));const grid=el('div',undefined,'profile-stat');const row=profile[k];for(const [name,value] of [['Classement',row?.rank?'#'+row.rank:'—'],['Points',row?fmt.format(row.points):'0'],[k==='daily'?'Matchs':'Arènes',fmt.format(row?.events||0)]]){const v=el('div');v.append(el('span',name),el('strong',value));grid.append(v)}card.append(grid);node.append(card)}
}
async function load(){
 const serial=++sequence,q=new URLSearchParams({action:'public',username:user});
 for(const k of kinds){q.set(k,state[k].id);q.set(k+'_page',state[k].page);if(state[k].expanded)q.set(k+'_expanded','1')}
 try{const res=await fetch('api.php?'+q,{cache:'no-store'}),p=await res.json();if(!res.ok||!p.ok)throw Error(p.error||'Classement indisponible.');if(serial!==sequence)return;
 for(const k of kinds)renderSection(k,p.sections[k]);
 if(user)renderProfile(p);else showRankings()
 $('status').textContent='';
 }catch(e){if(serial===sequence)$('status').textContent=e.message}
}
for(const k of kinds)$(k).querySelector('select').onchange=e=>{state[k].id=Number(e.target.value);state[k].page=1;void load()};
$('search').onsubmit=e=>{e.preventDefault();choose($('username').value.trim())};
(async()=>{await load();try{const r=await fetch('api.php?action=public-refresh',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}',cache:'no-store'});const p=await r.json();if(p.ok&&p.processed)await load()}catch{}})();
})();