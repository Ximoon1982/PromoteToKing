/* Shared selector for the two stand-alone Team France match administration pages. */
(()=>{'use strict';
const input=document.getElementById('p2kMatchReference');if(!input)return;
const form=document.getElementById('p2kMatchForm');if(!form)return;
const panel=document.createElement('section');panel.className='tf-upcoming-selector';panel.setAttribute('aria-label','Team France upcoming Daily team matches');
const heading=document.createElement('div');heading.className='tf-upcoming-heading';heading.textContent='Upcoming Team France Daily matches';
const status=document.createElement('div');status.className='tf-upcoming-status';status.setAttribute('role','status');status.textContent='Loading matches open for registration…';
const items=document.createElement('div');items.className='tf-upcoming-options';const actions=document.createElement('div');actions.className='tf-upcoming-actions';
const count=document.createElement('span');count.className='tf-upcoming-status';const refresh=document.createElement('button');refresh.type='button';refresh.textContent='Refresh list';refresh.addEventListener('click',()=>load());actions.append(count,refresh);
panel.append(heading,status,items,actions);form.insertAdjacentElement('afterend',panel);
let chosen=null;function utc(epoch){return Number(epoch)>0?new Date(Number(epoch)*1000).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric',timeZone:'UTC'}):''}
function render(rows){items.replaceChildren();let selectedMatch=null;let index=0;for(const row of rows){if(!row||!/^[0-9]{1,18}$/.test(String(row.id??'')))continue;
 const label=document.createElement('label');label.className='tf-upcoming-option';const radio=document.createElement('input');radio.type='radio';radio.name='tfUpcomingChoice';radio.value=String(row.id);const info=document.createElement('span');const strong=document.createElement('strong');strong.textContent=String(row.name||'Match #'+row.id);const meta=document.createElement('small');meta.textContent='#'+row.id+(row.startTime?' · Start '+utc(row.startTime)+' UTC':'');info.append(strong,meta);label.append(radio,info);items.append(label);index++;
 radio.addEventListener('change',()=>{if(radio.checked){chosen=radio.value;input.value=chosen;input.dispatchEvent(new Event('input',{bubbles:true}));}});
 if(input.value.trim()===radio.value)selectedMatch=radio;
}
if(selectedMatch)selectedMatch.checked=true;
count.textContent=index+' match'+(index===1?'':'es')+' accepting registration';
status.textContent=index?'Select a match to fill the field above.':'No Daily matches currently accepting registration.';
}
input.addEventListener('input',()=>{const match=Array.from(items.querySelectorAll('input[type=radio]')).find(x=>x.value===input.value.trim());for(const x of items.querySelectorAll('input[type=radio]'))x.checked=x===match;chosen=match?.value||null});
async function load(){refresh.disabled=true;status.textContent='Loading matches open for registration…';try{const response=await fetch('/TeamFrance/api/overview.php',{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const payload=await response.json();if(!response.ok||!payload.ok)throw Error(payload.error||'Unable to load Team France club matches.');if(!Array.isArray(payload.matches))throw Error('Match list temporarily unavailable.');render(payload.matches);}catch(error){items.replaceChildren();status.textContent=error.message||'Match list unavailable.';count.textContent='You can still enter a match ID manually.';}finally{refresh.disabled=false}}
load();
})();
