(() => {
"use strict";
if(window.__P2K_TROPHY_R5FIX3_8)return;
window.__P2K_TROPHY_R5FIX3_8=true;

const ROOT=new URL("../../../",document.currentScript?.src||location.href);
const META_API=new URL("server/trophy-gallery/public/editor-meta.php",ROOT).href;
const TROPHY_API=new URL("server/trophy-gallery/public/api.php",ROOT).href;
const MEDIA=new URL("server/trophy-gallery/public/media.php?id=",ROOT).href;
const $=(s,r=document)=>r?.querySelector(s);
const $$=(s,r=document)=>[...(r?.querySelectorAll(s)||[])];
const esc=v=>String(v??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"}[c]));
const plain=v=>String(v??"").replace(/!\[[^\]]*\]\([^)]+\)/g,"").replace(/\[([^\]]+)\]\([^)]+\)/g,"$1").replace(/[*_`>#~-]+/g," ").replace(/\s+/g," ").trim();

const ART=Object.freeze({
 "owl-2024-swiss4all":{legacy:"owl-2024-swiss4all.jpg",generic:"cup_gold.png"},
 "owl-2024-grand-prix-candidates":{legacy:"owl-2024-grand-prix-candidates.png",generic:"medal_gold.png"},
 "owl-2024-vote-g1":{legacy:"owl-2024-vote-g1.png",generic:"medal_gold.png"},
 "owl-2024-classic-u1700":{legacy:"owl-2024-classic-u1700.png",generic:"cup_gold.png"},
 "pcl-super-bingo-2025":{legacy:"pcl-super-bingo-2025.png",generic:"crystal_gold.png"},
 "tcmac-centurion-s4":{legacy:"tcmac-centurion-s4.png",generic:"medal_gold.png"},
 "club-wars-galactic-conflict":{legacy:"club-wars-galactic-conflict.png",generic:"crystal_gold.png"}
});

const R538_FINAL_STYLE=`
.p2k-trophy-card[data-r538-card]{box-sizing:border-box!important;width:auto!important;max-width:100%!important;min-width:0!important;justify-self:stretch!important;overflow:hidden!important;padding:0!important;margin:0!important}
.p2k-trophy-card[data-r538-card]>button[data-r538-button]{all:unset!important;box-sizing:border-box!important;display:grid!important;grid-template-rows:auto auto!important;width:auto!important;max-width:100%!important;min-width:0!important;justify-self:stretch!important;align-self:stretch!important;padding:0!important;margin:0!important;border:0!important;border-radius:0!important;background:transparent!important;color:inherit!important;cursor:pointer!important}
.p2k-trophy-card[data-r538-card]>button[data-r538-button]>[data-r538-art]{box-sizing:border-box!important;position:relative!important;display:block!important;width:auto!important;max-width:100%!important;min-width:0!important;justify-self:stretch!important;aspect-ratio:1/1!important;overflow:hidden!important;padding:0!important;margin:0!important;border:0!important;border-bottom:1px solid rgba(255,255,255,.06)!important;border-radius:0!important;background:#111!important}
.p2k-trophy-card[data-r538-card]>button[data-r538-button]>[data-r538-art]>img[data-r538-image]{box-sizing:border-box!important;position:absolute!important;inset:0!important;display:block!important;width:100%!important;height:100%!important;max-width:100%!important;max-height:100%!important;min-width:0!important;min-height:0!important;padding:0!important;margin:0!important;border:0!important;object-fit:contain!important;object-position:50% 50%!important;background:transparent!important;transform:none!important}
.p2k-trophy-card[data-r538-card]>button[data-r538-button]>[data-r538-title-wrap]{box-sizing:border-box!important;display:flex!important;align-items:center!important;justify-content:center!important;width:auto!important;max-width:100%!important;min-width:0!important;justify-self:stretch!important;min-height:68px!important;padding:12px 14px!important;margin:0!important;border:0!important;background:transparent!important;text-align:center!important}
.p2k-trophy-card[data-r538-card] [data-r538-title]{box-sizing:border-box!important;width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;padding:0!important;color:#f6b73c!important;font-size:1.08rem!important;line-height:1.32!important;font-weight:800!important;text-align:center!important;overflow-wrap:anywhere!important}
.p2k-r538-modal-overlay{box-sizing:border-box!important;width:100%!important;max-width:100%!important;height:100dvh!important;min-width:0!important;align-items:flex-start!important;justify-items:center!important;overflow-x:hidden!important;overflow-y:auto!important;overscroll-behavior:contain!important;padding:max(8px,env(safe-area-inset-top)) max(8px,env(safe-area-inset-right)) max(8px,env(safe-area-inset-bottom)) max(8px,env(safe-area-inset-left))!important}
.p2k-r538-modal,.p2k-r538-modal *{box-sizing:border-box!important}
.p2k-r538-modal{width:min(1080px,100%)!important;max-width:100%!important;min-width:0!important;max-height:calc(100dvh - 16px)!important;margin:auto!important;overflow:auto!important;overscroll-behavior:contain!important}
.p2k-r538-modal-head,.p2k-r538-modal-body,.p2k-r538-modal-left,.p2k-r538-modal-details,.p2k-r538-modal-art,.p2k-r538-lead,.p2k-r538-detail,.p2k-r538-meta,.p2k-r538-meta>div{max-width:100%!important;min-width:0!important}
.p2k-r538-modal-body{width:100%!important}
.p2k-r538-modal-head>div{min-width:0!important;flex:1 1 auto!important}
.p2k-r538-close{box-sizing:border-box!important;flex:0 0 38px!important;width:38px!important;min-width:38px!important;max-width:38px!important;height:38px!important;min-height:38px!important;max-height:38px!important;aspect-ratio:1/1!important;line-height:1!important}
.p2k-r538-modal-art img[data-r538-view-image]{cursor:zoom-in!important;touch-action:manipulation!important}
@media(max-width:780px){.p2k-r538-modal-body{grid-template-columns:minmax(0,1fr)!important}}
@media(max-width:560px){.p2k-r538-modal-overlay{padding:max(10px,env(safe-area-inset-top)) max(12px,env(safe-area-inset-right)) max(10px,env(safe-area-inset-bottom)) max(12px,env(safe-area-inset-left))!important}.p2k-r538-modal{width:100%!important;max-width:100%!important;max-height:calc(100dvh - 20px)!important;border-radius:10px!important;margin:auto!important}.p2k-r538-modal-head{padding:13px 14px!important}.p2k-r538-modal-body{padding:12px!important}.p2k-r538-modal-art{min-height:0!important}.p2k-r538-meta{grid-template-columns:1fr 1fr!important}.p2k-r538-viewer-overlay{box-sizing:border-box!important;padding:max(8px,env(safe-area-inset-top)) max(8px,env(safe-area-inset-right)) max(8px,env(safe-area-inset-bottom)) max(8px,env(safe-area-inset-left))!important}.p2k-r538-viewer{box-sizing:border-box!important;width:100%!important;max-width:100%!important;height:calc(100dvh - 16px)!important;max-height:calc(100dvh - 16px)!important;padding:10px!important;border-radius:12px!important}}
`;
function ensureFinalStyle(){
 const head=document.head||document.documentElement;
 let node=document.getElementById('p2kTrophyR538FinalStyle');
 if(!node){
  node=document.createElement('style');
  node.id='p2kTrophyR538FinalStyle';
  node.textContent=R538_FINAL_STYLE;
 }
 // r5fix2.2 appends its CSS link dynamically from the canonical Trophy runtime.
 // Keep this style physically last so equal-important legacy rules cannot win by order.
 if(node.parentNode!==head||head.lastElementChild!==node)head.appendChild(node);
}
const r538HeadObserver=new MutationObserver(()=>ensureFinalStyle());
if(document.head)r538HeadObserver.observe(document.head,{childList:true});

let metaCache=null,adminCache=null,publicCache=null,lastPublicId="",normalizeRunning=false,previewRecord=null;

async function getMeta(force=false){
 if(!force&&metaCache)return metaCache;
 const response=await fetch(`${META_API}?action=list`,{cache:"no-store",credentials:"same-origin"});
 const payload=await response.json();
 if(!response.ok||!payload?.ok)throw new Error(payload?.error?.message||`HTTP ${response.status}`);
 metaCache=payload.records||{};
 return metaCache;
}
async function saveMeta(id,mode,tables){
 const client=window.P2K_TEAM_POINTS_CLIENT;
 if(!client?.endpointRequest)throw new Error("Secured P2K administrator client unavailable.");
 const out=await client.endpointRequest(META_API,{action:"save",method:"POST",body:{id,modal_media_mode:mode,result_tables:tables},timeoutMs:60000});
 metaCache=null;
 return out.meta;
}
async function adminList(force=false){
 if(!force&&adminCache)return adminCache;
 const client=window.P2K_TEAM_POINTS_CLIENT;
 if(!client?.endpointRequest)throw new Error("Secured P2K administrator client unavailable.");
 adminCache=await client.endpointRequest(TROPHY_API,{action:"admin-list",method:"GET",timeoutMs:60000});
 return adminCache;
}
async function publicList(force=false){
 if(!force&&publicCache)return publicCache;
 const response=await fetch(`${TROPHY_API}?action=list`,{cache:"no-store",credentials:"omit"});
 const payload=await response.json();
 if(!response.ok||!payload?.ok)throw new Error(payload?.error?.message||`HTTP ${response.status}`);
 publicCache=payload;
 return payload;
}
function setStatus(form,text){const el=$("[data-status]",form);if(el)el.textContent=text}
const recordId=form=>form?.elements?.id?.value||"";
const yearOf=r=>String(r?.award_date||"").slice(0,4)||({"owl-2024-swiss4all":"2024","owl-2024-grand-prix-candidates":"2024","owl-2024-vote-g1":"2024","owl-2024-classic-u1700":"2024","pcl-super-bingo-2025":"2025","tcmac-centurion-s4":"2025","club-wars-galactic-conflict":"2026"}[r?.id]||"Undated");

function legacyUrl(r){
 const a=ART[r?.id];
 return a?new URL(`assets/trophy-gallery/legacy-r4/${a.legacy}`,ROOT).href:"";
}
function genericUrl(r){
 const a=ART[r?.id];
 return a?new URL(`assets/trophy-gallery/engraving/${a.generic}`,ROOT).href:"";
}
function vignetteUrl(r){
 if(r?.__vignetteUrl)return r.__vignetteUrl;
 if(r?.vignette_media_id)return MEDIA+encodeURIComponent(r.vignette_media_id);
 return legacyUrl(r);
}
function modalMode(r,meta){
 return meta?.modal_media_mode||(r?.modal_media_id?"custom":"vignette");
}
function modalUrl(r,meta){
 const mode=modalMode(r,meta);
 if(mode==="none")return"";
 if(mode==="custom"){
  if(r?.__modalUrl)return r.__modalUrl;
  return r?.modal_media_id?MEDIA+encodeURIComponent(r.modal_media_id):"";
 }
 return vignetteUrl(r);
}
function attachImageFallback(img,r){
 if(!img)return;
 const legacy=legacyUrl(r),generic=genericUrl(r);
 img.addEventListener("error",()=>{
  if(img.dataset.r538Fallback==="generic"){img.remove();return}
  if(generic&&img.src!==generic){img.dataset.r538Fallback="generic";img.src=generic}
  else img.remove();
 },{once:false});
 if(!img.src&&legacy)img.src=legacy;
}

function cardSummary(r){
 const full=plain(r?.description_md||r?.description||"");
 return full.length>126?full.slice(0,123)+"…":full;
}
function renderCardButton(button,r){
 if(!button||!r)return;
 const card=button.closest('.p2k-trophy-card');
 if(card)card.setAttribute('data-r538-card','');
 button.setAttribute('data-r538-button','');
 const signature=[r.id,r.title,r.vignette_media_id,r.__vignetteUrl].join('|');
 if(button.dataset.r538Signature===signature&&button.children.length===2)return;

 const stage=document.createElement('div');
 stage.className='p2k-trophy-image p2k-r538-card-image';
 stage.setAttribute('data-r538-art','');
 const url=vignetteUrl(r);
 if(url){
  const img=document.createElement('img');
  img.setAttribute('data-r538-image','');
  img.alt=r.title||'Trophy artwork';
  img.loading='lazy';
  img.decoding='async';
  img.src=url;
  attachImageFallback(img,r);
  stage.appendChild(img);
 }

 const copy=document.createElement('div');
 copy.className='p2k-trophy-copy p2k-r538-card-copy';
 copy.setAttribute('data-r538-title-wrap','');
 const title=document.createElement('h3');
 title.setAttribute('data-r538-title','');
 title.textContent=r.title||'Trophy';
 copy.appendChild(title);

 button.replaceChildren(stage,copy);
 button.dataset.r538Signature=signature;
 if(r.id)button.dataset.open=r.id;
}
async function normalizeGallery(){
 if(normalizeRunning)return;
 const roots=$$(".p2k-trophy-root").filter(root=>root.querySelector("[data-groups]"));
 if(!roots.length)return;
 normalizeRunning=true;
 try{
  const out=await publicList();
  const map=new Map((out.records||[]).map(r=>[r.id,r]));
  roots.forEach(root=>{
   $$(".p2k-trophy-card",root).forEach(card=>{
    const button=$(":scope > button",card);
    const id=card.dataset.trophyId||button?.dataset.open||"";
    const r=map.get(id);
    if(r)renderCardButton(button,r);
    else{
     const imgs=$$("img",button);
     imgs.slice(1).forEach(img=>img.remove());
    }
   });
  });
 }catch(error){
  console.warn("Trophy card normalization failed",error);
  roots.forEach(root=>$$(".p2k-trophy-card",root).forEach(card=>{
   const imgs=$$("img",card);imgs.slice(1).forEach(img=>img.remove());
  }));
 }finally{
  normalizeRunning=false;
 }
}

function currentTables(form){
 return $$("[data-r538-result-row]",form).map(row=>({
  label:$("[data-r538-result-label]",row)?.value.trim()||"Result table",
  url:$("[data-r538-result-url]",row)?.value.trim()||""
 })).filter(row=>row.url);
}
function syncLegacyResult(form){
 const legacy=form?.elements?.result_table_url;
 if(legacy)legacy.value=currentTables(form)[0]?.url||"";
}
function resultRow(label="Result table",url=""){
 const row=document.createElement("div");
 row.className="p2k-r538-result-row";
 row.dataset.r538ResultRow="";
 row.innerHTML=`<label>Label<input data-r538-result-label value="${esc(label)}"></label><label>URL<input data-r538-result-url type="url" value="${esc(url)}"></label><button type="button" data-r538-result-remove>Remove</button>`;
 return row;
}
function bindResultRow(row,form){
 $("[data-r538-result-remove]",row).onclick=()=>{
  const list=row.parentElement;
  row.remove();
  if(list&&!list.children.length){
   const replacement=resultRow();
   list.appendChild(replacement);
   bindResultRow(replacement,form);
  }
  syncLegacyResult(form);
 };
 $$("input",row).forEach(input=>input.addEventListener("input",()=>syncLegacyResult(form)));
}
async function enhanceResults(form){
 if(form.dataset.r538Results==="1")return;
 form.dataset.r538Results="1";
 const legacy=form.elements?.result_table_url;
 if(!legacy)return;
 const legacyLabel=legacy.closest("label");
 legacyLabel?.classList.add("p2k-r538-source-result");

 const section=document.createElement("section");
 section.className="p2k-wide p2k-r538-result-section";
 section.innerHTML='<strong>Result tables</strong><div class="p2k-r538-result-list" data-r538-result-list></div><button type="button" data-r538-result-add>Add result table</button>';
 (legacyLabel||legacy).insertAdjacentElement("afterend",section);

 let rows=[];
 try{
  const [meta,admin]=await Promise.all([getMeta(),adminList()]);
  const id=recordId(form),rec=(admin.records||[]).find(r=>r.id===id),extra=meta[id];
  rows=Array.isArray(extra?.result_tables)?extra.result_tables.filter(x=>x?.url):[];
  if(!rows.length&&rec?.result_table_url)rows=[{label:"Result table",url:rec.result_table_url}];
 }catch(error){console.warn("Trophy result-table preload failed",error)}
 if(!rows.length)rows=[{label:"Result table",url:legacy.value||""}];

 const list=$("[data-r538-result-list]",form);
 rows.forEach(item=>{
  const row=resultRow(item.label||"Result table",item.url||"");
  list.appendChild(row);
  bindResultRow(row,form);
 });
 $("[data-r538-result-add]",form).onclick=()=>{
  const row=resultRow(`Result table ${list.children.length+1}`,"");
  list.appendChild(row);
  bindResultRow(row,form);
 };
 syncLegacyResult(form);
}

async function refreshModalCard(form){
 const id=recordId(form);
 if(!id)return;
 try{
  const [meta,admin]=await Promise.all([getMeta(),adminList(true)]);
  const rec=(admin.records||[]).find(r=>r.id===id);
  if(!rec)return;
  const mode=modalMode(rec,meta[id]);
  form.dataset.r538ModalMode=mode;
  const card=$('[data-upload="modal"]',form)?.closest(".p2k-media-card");
  const preview=$('[data-preview="modal"]',card),note=$("[data-r538-mode-note]",card);
  if(note)note.textContent=mode==="none"?"No modal image":mode==="custom"?"Custom modal image":"Using vignette image";
  if(!preview)return;

  if(mode==="none"){
   preview.replaceChildren(Object.assign(document.createElement("span"),{textContent:"No modal image"}));
   preview.disabled=true;
  }else{
   const url=modalUrl(rec,meta[id]);
   preview.replaceChildren();
   if(url){
    const img=document.createElement("img");
    img.src=url;
    img.alt="";
    attachImageFallback(img,rec);
    preview.appendChild(img);
    preview.disabled=false;
   }else{
    preview.appendChild(Object.assign(document.createElement("span"),{textContent:"No image"}));
    preview.disabled=true;
   }
  }
 }catch(error){console.warn("Trophy modal-image refresh failed",error)}
}
async function enhanceModalControls(form){
 if(form.dataset.r538Modal==="1")return;
 form.dataset.r538Modal="1";
 const card=$('[data-upload="modal"]',form)?.closest(".p2k-media-card");
 if(!card)return;
 const controls=document.createElement("div");
 controls.className="p2k-r538-modal-actions";
 controls.innerHTML='<button type="button" data-r538-mode="vignette">Use same as vignette</button><button type="button" data-r538-mode="none">Remove modal image</button><span class="p2k-r538-mode-note" data-r538-mode-note></span>';
 card.appendChild(controls);
 await refreshModalCard(form);

 $$("[data-r538-mode]",controls).forEach(button=>button.onclick=async()=>{
  const id=recordId(form);
  if(!id)return setStatus(form,"Save the Trophy before changing modal artwork.");
  button.disabled=true;
  try{
   await saveMeta(id,button.dataset.r538Mode,currentTables(form));
   form.dataset.r538ModalMode=button.dataset.r538Mode;
   await refreshModalCard(form);
   setStatus(form,button.dataset.r538Mode==="none"?"Modal image removed.":"Modal will use the vignette image.");
  }catch(error){setStatus(form,error.message)}
  finally{button.disabled=false}
 });

 const upload=$('[data-upload="modal"]',form);
 if(upload&&upload.dataset.r538CustomUpload!=="1"){
  upload.dataset.r538CustomUpload="1";
  const original=upload.onchange;
  upload.onchange=async event=>{
   const id=recordId(form);
   if(id&&upload.files?.[0]){
    try{
     await saveMeta(id,"custom",currentTables(form));
     form.dataset.r538ModalMode="custom";
    }catch(error){
     setStatus(form,error.message);
     return;
    }
   }
   return original?.call(upload,event);
  };
 }
}

function transformMatchResults(form){
 const results=$("[data-match-results]",form);
 if(!results)return;
 const originals=$$(":scope > button[data-link]",results).filter(button=>!button.dataset.r538Wrapped);
 if(!originals.length)return;

 originals.forEach(button=>{
  button.dataset.r538Wrapped="1";
  button.hidden=true;
  const choice=document.createElement("label");
  choice.className="p2k-r538-match-choice";
  choice.innerHTML=`<input type="checkbox" data-r538-match value="${esc(button.dataset.link)}"><span><strong>${esc(button.dataset.name||button.textContent.split(" · ")[0])}</strong><small>${esc(button.textContent)}</small></span>`;
  button.insertAdjacentElement("afterend",choice);
 });

 let actions=results.parentElement.querySelector(":scope > .p2k-r538-match-actions");
 if(actions)return;
 actions=document.createElement("div");
 actions.className="p2k-r538-match-actions";
 actions.innerHTML='<button type="button" data-r538-apply-matches>Add selected matches</button>';
 results.insertAdjacentElement("afterend",actions);

 $("[data-r538-apply-matches]",actions).onclick=()=>{
  const selected=$$("[data-r538-match]:checked",results);
  if(!selected.length)return setStatus(form,"Select at least one match.");
  const originalSubmit=form.requestSubmit.bind(form);
  Object.defineProperty(form,"requestSubmit",{value:()=>{},configurable:true});
  try{
   selected.forEach(box=>results.querySelector(`button[data-link="${CSS.escape(box.value)}"]`)?.click());
  }finally{
   delete form.requestSubmit;
  }
  originalSubmit();
 };
}
function enhanceMatches(form){
 if(form.dataset.r538Matches==="1")return;
 form.dataset.r538Matches="1";
 const results=$("[data-match-results]",form);
 if(!results)return;
 new MutationObserver(()=>transformMatchResults(form)).observe(results,{childList:true});
 transformMatchResults(form);
}

function ensureViewer(){
 let overlay=$("#p2kR538Viewer");
 if(overlay)return overlay;
 overlay=document.createElement("div");
 overlay.id="p2kR538Viewer";
 overlay.className="p2k-r538-viewer-overlay";
 overlay.hidden=true;
 overlay.innerHTML='<div class="p2k-r538-viewer" role="dialog" aria-modal="true"><button type="button" class="p2k-r538-viewer-close" data-r538-viewer-close aria-label="Close">×</button><img alt="Trophy artwork"></div>';
 overlay.addEventListener("click",event=>{
  if(event.target===overlay||event.target.closest("[data-r538-viewer-close]"))overlay.hidden=true;
 });
 document.body.appendChild(overlay);
 return overlay;
}
function openViewer(url,alt="Trophy artwork"){
 if(!url)return;
 const overlay=ensureViewer(),img=$("img",overlay);
 img.src=url;
 img.alt=alt;
 overlay.hidden=false;
}

function detailLinks(r,meta){
 const rows=[];
 const seen=new Set();
 const add=(label,url)=>{
  url=String(url||"").trim();
  if(!/^https?:\/\//i.test(url)||seen.has(url))return;
  seen.add(url);rows.push({label,url});
 };
 const tables=Array.isArray(meta?.result_tables)?meta.result_tables:[];
 tables.forEach((row,i)=>add(row?.label||`Result table ${i+1}`,row?.url));
 add("Result table",r?.result_table_url);
 add("Award page",r?.award_page);
 add("Competition page",r?.competition_page);
 return rows;
}
function renderShowcaseModal(r,meta={}){
 let overlay=$("#p2kR538Modal");
 if(!overlay){
  overlay=document.createElement("div");
  overlay.id="p2kR538Modal";
  overlay.className="p2k-r538-modal-overlay";
  overlay.hidden=true;
  document.body.appendChild(overlay);
  overlay.addEventListener("click",event=>{
   if(event.target===overlay||event.target.closest("[data-r538-close]"))overlay.hidden=true;
  });
 }
 const artUrl=modalUrl(r,meta),matches=Array.isArray(r?.matches)?r.matches:[],links=detailLinks(r,meta);
 const season=yearOf(r);
 const competition=r?.competition||"—",award=r?.award||"Award",league=r?.league||"—",date=r?.award_date||season||"—";
 overlay.innerHTML=`<section class="p2k-r538-modal ${artUrl?"":"is-no-art"}" role="dialog" aria-modal="true">
  <header class="p2k-r538-modal-head">
   <div><small>${esc(league)} · ${esc(season)}</small><h2>${esc(r?.title||"Trophy")}</h2></div>
   <button type="button" class="p2k-r538-close" data-r538-close aria-label="Close">×</button>
  </header>
  <div class="p2k-r538-modal-body">
   <div class="p2k-r538-modal-left">
    <div class="p2k-r538-modal-art">${artUrl?`<img data-r538-view-image src="${esc(artUrl)}" alt="${esc(r?.title||"Trophy artwork")}" tabindex="0" role="button" aria-label="View ${esc(r?.title||"Trophy")} image full screen">`:""}</div>
    ${artUrl?'<button type="button" class="p2k-r538-view-art">View image larger</button>':""}
   </div>
   <div class="p2k-r538-modal-details">
    <section class="p2k-r538-lead"><h3>${esc(award)}</h3><p>${esc(r?.description_md||r?.description||"")}</p></section>
    <dl class="p2k-r538-meta">
     <div><dt>League</dt><dd>${esc(league)}</dd></div>
     <div><dt>Competition</dt><dd>${esc(competition)}</dd></div>
     <div><dt>Award date</dt><dd>${esc(date)}</dd></div>
     <div><dt>Season</dt><dd>${esc(season)}</dd></div>
    </dl>
    <section class="p2k-r538-detail"><h3>Matches involved</h3>${matches.length?`<ul>${matches.map(m=>`<li>${esc(m?.name||m?.match_name||`Match ${m?.match_id||""}`)}</li>`).join("")}</ul>`:"<p>No linked matches.</p>"}</section>
    <section class="p2k-r538-detail"><h3>Result tables & references</h3>${links.length?`<div class="p2k-r538-links">${links.map(x=>`<a href="${esc(x.url)}" target="_blank" rel="noopener noreferrer">${esc(x.label)}</a>`).join("")}</div>`:"<p>No external references.</p>"}</section>
   </div>
  </div>
 </section>`;
 const artImg=$(".p2k-r538-modal-art img",overlay);
 if(artImg){
  attachImageFallback(artImg,r);
  const enlarge=()=>openViewer(artImg.src||artUrl,r?.title||"Trophy artwork");
  artImg.addEventListener("click",enlarge);
  artImg.addEventListener("keydown",event=>{
   if(event.key==="Enter"||event.key===" "){event.preventDefault();enlarge()}
  });
 }
 $(".p2k-r538-view-art",overlay)?.addEventListener("click",()=>openViewer(artImg?.src||artUrl,r?.title||"Trophy artwork"));
 overlay.hidden=false;
}

async function openPublicEntry(id){
 try{
  const [publicData,meta]=await Promise.all([publicList(),getMeta()]);
  const r=(publicData.records||[]).find(x=>x.id===id);
  if(r)renderShowcaseModal(r,meta[id]||{});
 }catch(error){
  console.warn("Trophy modal failed",error);
 }
}

function ensurePreviewOverlay(){
 let overlay=$("#p2kR538EntryPreview");
 if(overlay)return overlay;
 overlay=document.createElement("div");
 overlay.id="p2kR538EntryPreview";
 overlay.className="p2k-r538-preview-overlay";
 overlay.hidden=true;
 overlay.innerHTML='<section class="p2k-r538-preview-panel" role="dialog" aria-modal="true"><header class="p2k-r538-preview-head"><h3>Gallery entry preview</h3><button type="button" class="p2k-r538-close" data-r538-preview-close aria-label="Close">×</button></header><p style="color:#aaa198;margin:0 0 12px">This is the same card renderer used in the Trophy Gallery. Click the card to open the same Gallery modal.</p><div class="p2k-r538-preview-card-wrap"><article class="p2k-trophy-card"><button type="button" data-r538-preview-card></button></article></div></section>';
 overlay.addEventListener("click",event=>{
  if(event.target===overlay||event.target.closest("[data-r538-preview-close]"))overlay.hidden=true;
 });
 $("[data-r538-preview-card]",overlay).addEventListener("click",()=>{
  if(previewRecord)renderShowcaseModal(previewRecord,previewRecord.__meta||{});
 });
 document.body.appendChild(overlay);
 return overlay;
}
async function gatherPreviewRecord(form){
 let base={};
 const id=recordId(form);
 try{
  const admin=await adminList(true);
  base=(admin.records||[]).find(r=>r.id===id)||{};
 }catch{}
 const vignette=$('[data-preview="vignette"] img',form)?.src||"";
 const modal=$('[data-preview="modal"] img',form)?.src||"";
 const r={...base,
  id:id||"preview-entry",
  title:form.elements?.title?.value||base.title||"Trophy",
  league:form.elements?.league?.value||base.league||"",
  competition:form.elements?.competition?.value||base.competition||"",
  award:form.elements?.award?.value||base.award||"",
  award_date:form.elements?.award_date?.value||base.award_date||"",
  description_md:form.elements?.description_md?.value||base.description_md||"",
  award_page:form.elements?.award_page?.value||base.award_page||"",
  competition_page:form.elements?.competition_page?.value||base.competition_page||"",
  result_table_url:form.elements?.result_table_url?.value||base.result_table_url||"",
  __vignetteUrl:vignette,
  __modalUrl:modal
 };
 let meta={modal_media_mode:form.dataset.r538ModalMode||(base.modal_media_id?"custom":"vignette"),result_tables:currentTables(form)};
 try{
  const all=await getMeta();
  meta={...(all[id]||{}),...meta,result_tables:currentTables(form)};
 }catch{}
 r.__meta=meta;
 return r;
}
async function previewEntry(form){
 previewRecord=await gatherPreviewRecord(form);
 const overlay=ensurePreviewOverlay(),button=$("[data-r538-preview-card]",overlay);
 renderCardButton(button,previewRecord);
 overlay.hidden=false;
}
function enhancePreviewButton(form){
 if(form.dataset.r538PreviewButton==="1")return;
 form.dataset.r538PreviewButton="1";
 const actions=$(".p2k-trophy-actions",form);
 if(!actions)return;
 const button=document.createElement("button");
 button.type="button";
 button.className="p2k-r538-preview-entry";
 button.textContent="Preview entry";
 button.addEventListener("click",()=>previewEntry(form));
 actions.appendChild(button);
}

function ensureEngraverModal(form){
 const frame=$("iframe.p2k-engraver",form);
 if(!frame)return null;
 frame.setAttribute("data-engraver","");
 let modal=frame.closest(".p2k-engraver-modal");
 if(modal)return modal;
 modal=document.createElement("div");
 modal.className="p2k-engraver-modal";
 modal.hidden=true;
 modal.innerHTML='<section class="p2k-engraver-modal-card" role="dialog" aria-modal="true"><header class="p2k-engraver-modal-head"><strong>Trophy engraving editor</strong><button type="button" data-engraver-close aria-label="Close">×</button></header><div data-engraver-body></div></section>';
 $("[data-engraver-body]",modal).appendChild(frame);
 form.appendChild(modal);
 modal.addEventListener("click",event=>{
  if(event.target===modal||event.target.closest("[data-engraver-close]")){
   modal.hidden=true;
   frame.hidden=true;
   document.body.classList.remove("p2k-engraver-open");
  }
 });
 return modal;
}
function defaultTeam(frame){
 const doc=frame.contentDocument;
 ["middleText","cupPlaque","crystalTop"].forEach(id=>{
  const field=doc?.getElementById(id);
  if(field&&field.value!=="Promote to King"){
   field.value="Promote to King";
   field.dispatchEvent(new Event("input",{bubbles:true}));
  }
 });
}
function downloadCanvas(frame){
 const doc=frame.contentDocument,canvas=doc?.querySelector("canvas");
 if(!canvas)return;
 canvas.toBlob(blob=>{
  if(!blob)return;
  const link=doc.createElement("a");
  link.href=URL.createObjectURL(blob);
  link.download="p2k-trophy-prize.png";
  doc.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(()=>URL.revokeObjectURL(link.href),1000);
 },"image/png");
}
async function usePrize(frame){
 const canvas=frame.contentDocument?.querySelector("canvas");
 if(!canvas)return;
 const form=frame.closest("form"),id=recordId(form),slot=frame.dataset.slot||"vignette";
 canvas.toBlob(async blob=>{
  if(!blob)return;
  if(slot==="modal"&&id){
   try{
    await saveMeta(id,"custom",currentTables(form));
    form.dataset.r538ModalMode="custom";
   }catch(error){
    setStatus(form,error.message);
    return;
   }
  }
  // Keep the originating frame visible until the canonical Trophy message
  // handler has synchronously captured the form/slot. The message listener
  // below closes the modal immediately after dispatch; hiding it here would
  // make the canonical [data-engraver]:not([hidden]) lookup fail.
  window.postMessage({type:"p2k-trophy-engraving",name:"p2k-trophy-prize.png",blob},location.origin);
 },"image/png");
}
function installEngraverControls(frame){
 const doc=frame.contentDocument;
 if(!doc)return;
 defaultTeam(frame);
 const sample=doc.getElementById("sampleBtn");
 let use=doc.getElementById("p2kR538UsePrize");
 if(!use){
  use=doc.createElement("button");
  use.type="button";
  use.id="p2kR538UsePrize";
  use.textContent="Use prize";
  (sample||doc.getElementById("downloadBtn"))?.insertAdjacentElement("beforebegin",use);
  use.addEventListener("click",event=>{
   event.preventDefault();
   event.stopImmediatePropagation();
   usePrize(frame);
  },true);
 }
 sample?.remove();
 const download=doc.getElementById("downloadBtn");
 if(download&&download.dataset.r538Download!=="1"){
  download.dataset.r538Download="1";
  download.addEventListener("click",event=>{
   event.preventDefault();
   event.stopImmediatePropagation();
   downloadCanvas(frame);
  },true);
 }
}
function enhanceEngraver(frame){
 if(!frame)return;
 frame.setAttribute("data-engraver","");
 try{
  if(frame.contentDocument?.readyState==="complete")installEngraverControls(frame);
  else frame.addEventListener("load",()=>installEngraverControls(frame),{once:true});
 }catch{}
}

function installMetaSubmit(form){
 if(form.dataset.r538Submit==="1")return;
 form.dataset.r538Submit="1";
 form.addEventListener("submit",async event=>{
  if(form.dataset.r538Bypass==="1"){
   delete form.dataset.r538Bypass;
   return;
  }
  const id=recordId(form);
  if(!id)return;
  event.preventDefault();
  event.stopImmediatePropagation();
  try{
   await saveMeta(id,form.dataset.r538ModalMode||"vignette",currentTables(form));
   form.dataset.r538Bypass="1";
   form.requestSubmit();
  }catch(error){
   setStatus(form,error.message);
  }
 },true);
}
function enhanceForm(form){
 if(!form||form.dataset.r538Enhanced==="1")return;
 form.dataset.r538Enhanced="1";
 enhanceResults(form);
 enhanceModalControls(form);
 enhanceMatches(form);
 enhancePreviewButton(form);
 installMetaSubmit(form);
 const frame=$("iframe.p2k-engraver",form);
 if(frame){
  ensureEngraverModal(form);
  enhanceEngraver(frame);
 }
}

function unlockScroll(){
 if(!document.querySelector(".p2k-engraver-modal:not([hidden])")){
  document.body.classList.remove("p2k-engraver-open");
 }
}
function scan(){
 ensureFinalStyle();
 $$("form.p2k-trophy-form").forEach(enhanceForm);
 $$("iframe.p2k-engraver").forEach(enhanceEngraver);
 unlockScroll();
 normalizeGallery();
}

let queued=false;
new MutationObserver(()=>{
 if(queued)return;
 queued=true;
 requestAnimationFrame(()=>{
  queued=false;
  scan();
 });
}).observe(document.body||document.documentElement,{childList:true,subtree:true});

document.addEventListener("click",event=>{
 const mediaPreview=event.target.closest?.(".p2k-media-preview");
 if(mediaPreview){
  const img=$("img",mediaPreview);
  if(img?.src){
   event.preventDefault();
   event.stopImmediatePropagation();
   openViewer(img.src,"Trophy artwork");
   return;
  }
 }

 const engrave=event.target.closest?.("[data-engrave]");
 if(engrave){
  const form=engrave.closest("form"),frame=$("iframe.p2k-engraver",form);
  if(!form||!frame)return;
  event.preventDefault();
  event.stopImmediatePropagation();
  if(!recordId(form)){
   setStatus(form,"Save the Trophy before creating artwork.");
   return;
  }
  const modal=ensureEngraverModal(form);
  frame.dataset.slot=engrave.dataset.engrave||"vignette";
  frame.hidden=false;
  modal.hidden=false;
  document.body.classList.add("p2k-engraver-open");
  setTimeout(()=>{
   enhanceEngraver(frame);
   defaultTeam(frame);
  },0);
  return;
 }

 const publicButton=event.target.closest?.(".p2k-trophy-root [data-groups] .p2k-trophy-card [data-open]");
 if(publicButton){
  event.preventDefault();
  event.stopImmediatePropagation();
  lastPublicId=publicButton.dataset.open||"";
  openPublicEntry(lastPublicId);
  return;
 }
},true);

document.addEventListener("keydown",event=>{
 if(event.key!=="Escape")return;
 const viewer=$("#p2kR538Viewer");
 if(viewer&&!viewer.hidden){viewer.hidden=true;return}
 const entry=$("#p2kR538EntryPreview");
 if(entry&&!entry.hidden){entry.hidden=true;return}
 const modal=$("#p2kR538Modal");
 if(modal&&!modal.hidden){modal.hidden=true;return}
 const engraver=document.querySelector(".p2k-engraver-modal:not([hidden])");
 if(engraver){
  engraver.hidden=true;
  $("iframe.p2k-engraver",engraver).hidden=true;
  document.body.classList.remove("p2k-engraver-open");
 }
},true);

window.addEventListener("message",event=>{
 if(event?.data?.type==="p2k-trophy-engraving"){
  // The canonical listener is registered before this companion and has now
  // captured the visible engraver/form/slot. It is safe to close the UI.
  const visible=document.querySelector(".p2k-engraver-modal:not([hidden])");
  if(visible){
   const frame=$("iframe.p2k-engraver",visible);
   if(frame)frame.hidden=true;
   visible.hidden=true;
  }
  document.body.classList.remove("p2k-engraver-open");
  adminCache=null;
  publicCache=null;
  setTimeout(()=>{
   unlockScroll();
   normalizeGallery();
  },100);
 }
});

ensureFinalStyle();
scan();
})();