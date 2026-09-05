(() => {
"use strict";

const STORAGE_KEY = "p2k-trophy-gallery-poc-v1";
const SOURCE_PAGE = "https://www.chess.com/clubs/forum/view/promote-to-kings-trophy-gallery";
const SEED = [
  {
    id: "owl-2024-swiss4all",
    status: "published",
    league: "One World League",
    year: 2024,
    competition: "Swiss4All",
    title: "One World League 2024 Swiss4All",
    award: "Team award",
    image: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/Tournamentix/phpdo51fU.jpg",
    sourceImage: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/Tournamentix/phpdo51fU.jpg",
    link: "https://www.chess.com/clubs/forum/view/tournaments-2024?quote_id=104193679&page=1#comment-104193679",
    summary: "Imported from the existing Promote to King trophy-gallery source snapshot.",
    matches: ["POC match sample A", "POC match sample B", "POC match sample C"],
    players: ["POC player sample 1", "POC player sample 2", "POC player sample 3"],
    notes: "Match and player details are simulated in this proof of concept."
  },
  {
    id: "owl-2024-grand-prix-candidates",
    status: "published",
    league: "One World League",
    year: 2024,
    competition: "Grand Prix",
    title: "One World League 2024 Grand Prix — Candidates",
    award: "Candidates section",
    image: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/Ximoon/php169n9ussq2pv6QXsaHt.png",
    sourceImage: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/Ximoon/php169n9ussq2pv6QXsaHt.png",
    link: "https://www.chess.com/clubs/forum/view/tournaments-2024?page=2#comment-106058233",
    summary: "Grand Prix award imported from the existing gallery material.",
    matches: ["POC Candidates round 1", "POC Candidates round 2", "POC Candidates round 3"],
    players: ["POC lineup member A", "POC lineup member B", "POC lineup member C"],
    notes: "Detailed participation is simulated for the POC modal."
  },
  {
    id: "owl-2024-vote-g1",
    status: "published",
    league: "One World League",
    year: 2024,
    competition: "Vote Chess",
    title: "One World League 2024 Vote — 6th vote G1",
    award: "Vote Chess prize",
    image: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/Ximoon/phpfohilvskj8gl3yGprSv.png",
    sourceImage: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/Ximoon/phpfohilvskj8gl3yGprSv.png",
    link: "https://www.chess.com/clubs/forum/view/vote-chess-2024-2?page=1#comment-103089411",
    summary: "Vote Chess award imported from the fetched trophy-gallery source.",
    matches: ["POC vote match sample"],
    players: ["POC voters / players sample"],
    notes: "POC details only."
  },
  {
    id: "owl-2024-classic-u1700",
    status: "published",
    league: "One World League",
    year: 2024,
    competition: "Classic",
    title: "One World League 2024 Classic — U1700",
    award: "Classic prize",
    image: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/Ximoon/phpf16kms15soaq7HtNiLy.png",
    sourceImage: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/Ximoon/phpf16kms15soaq7HtNiLy.png",
    link: "https://www.chess.com/clubs/forum/view/tournaments-2024?page=1#comment-101798733",
    summary: "One of the Classic competition awards already present in the fetched gallery.",
    matches: ["POC U1700 match 1", "POC U1700 match 2"],
    players: ["POC U1700 player A", "POC U1700 player B"],
    notes: "POC details only."
  },
  {
    id: "pcl-super-bingo-2025",
    status: "published",
    league: "Phoenix Chess League",
    year: 2025,
    competition: "Super Bingo",
    title: "Phoenix Chess League Super Bingo 2025",
    award: "Super Bingo award",
    image: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/Ximoon/phpbdkgpmqj53bj0Fpy5XH.png",
    sourceImage: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/Ximoon/phpbdkgpmqj53bj0Fpy5XH.png",
    link: "https://www.chess.com/announcements/view/phoenix-chess-league-super-bingo-results-2025",
    summary: "PCL Super Bingo trophy imported from the existing Hall of Fame source snapshot.",
    matches: ["POC bingo match sample 1", "POC bingo match sample 2", "POC bingo match sample 3"],
    players: ["POC contributor A", "POC contributor B", "POC contributor C"],
    notes: "The future production version can connect these details to authoritative P2K match/player records."
  },
  {
    id: "tcmac-centurion-s4",
    status: "published",
    league: "TCMAC",
    year: 2025,
    competition: "Centurion S4 2024-2025",
    title: "TCMAC Centurion S4 2024-2025",
    award: "Centurion award",
    image: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/Ximoon/php693lr5tfd7f59LBxG9p.png",
    sourceImage: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/Ximoon/php693lr5tfd7f59LBxG9p.png",
    link: "https://www.chess.com/clubs/forum/view/awards-centurion-s4-2024-2025",
    summary: "TCMAC award imported from the fetched trophy-gallery material.",
    matches: ["POC Centurion match sample A", "POC Centurion match sample B"],
    players: ["POC legion member A", "POC legion member B", "POC legion member C"],
    notes: "POC details only."
  },
  {
    id: "club-wars-galactic-conflict",
    status: "draft",
    league: "Club Wars",
    year: 2026,
    competition: "Galactic Conflict",
    title: "Club Wars — Galactic Conflict",
    award: "Galactic Conflict prize",
    image: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/BlunderingKnight101/php5vt7kffnmide1GmnI0E.png",
    sourceImage: "https://images.chesscomfiles.com/uploads/v1/images_users/tiny_mce/BlunderingKnight101/php5vt7kffnmide1GmnI0E.png",
    link: `${SOURCE_PAGE}#CWGalacticConflict`,
    summary: "Imported source visual kept as a draft to demonstrate draft/publish workflow.",
    matches: ["POC campaign match sample"],
    players: ["POC campaign player sample"],
    notes: "Draft record: intentionally excluded from the Hall view until published."
  }
];

const css = `
.p2k-trophy-poc-tab[hidden],.p2k-trophy-poc-panel[hidden],.p2k-trophy-modal[hidden]{display:none!important}
.p2k-trophy-poc-panel{display:grid;gap:16px}
.p2k-trophy-hero{display:flex;gap:18px;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;padding:18px;border:1px solid rgba(220,183,107,.3);border-radius:16px;background:linear-gradient(135deg,rgba(244,178,62,.08),rgba(255,255,255,.02))}
.p2k-trophy-hero h2,.p2k-trophy-admin h2{margin:0 0 6px}.p2k-trophy-hero p,.p2k-trophy-admin p{margin:0;color:#bdb7ae}
.p2k-trophy-stats{display:grid;grid-template-columns:repeat(4,minmax(110px,1fr));gap:9px;min-width:min(100%,520px)}
.p2k-trophy-stat{border:1px solid #4e4437;border-radius:12px;padding:10px 12px;background:#1d1b18}.p2k-trophy-stat span{display:block;color:#bdb7ae;font-size:.78rem}.p2k-trophy-stat strong{display:block;font-size:1.25rem;color:#f4b23e;margin-top:2px}
.p2k-trophy-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.p2k-trophy-filters label{display:grid;gap:5px;color:#cfc8be;font-size:.82rem}.p2k-trophy-filters input,.p2k-trophy-filters select,.p2k-trophy-admin input,.p2k-trophy-admin select,.p2k-trophy-admin textarea{background:#171513;color:#eee8df;border:1px solid #5a4936;border-radius:9px;padding:8px 10px}
.p2k-trophy-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}.p2k-trophy-card{border:1px solid #4e4437;border-radius:15px;background:#1d1b18;overflow:hidden;cursor:pointer;transition:transform .14s ease,border-color .14s ease}.p2k-trophy-card:hover{transform:translateY(-2px);border-color:#9c7741}.p2k-trophy-card button{all:unset;display:block;cursor:pointer;width:100%;height:100%}.p2k-trophy-image{aspect-ratio:4/3;background:#111;display:flex;align-items:center;justify-content:center;overflow:hidden}.p2k-trophy-image img{width:100%;height:100%;object-fit:contain}.p2k-trophy-copy{padding:12px}.p2k-trophy-copy small{color:#c39d5e}.p2k-trophy-copy h3{font-size:1rem;margin:4px 0 6px}.p2k-trophy-copy p{font-size:.82rem;color:#bdb7ae;margin:0}.p2k-trophy-badge{display:inline-flex;padding:3px 7px;border-radius:999px;background:#45351f;color:#f4c873;font-size:.7rem}.p2k-trophy-badge.is-draft{background:#373737;color:#c8c8c8}
.p2k-trophy-modal{position:fixed;z-index:10000;inset:0;background:rgba(0,0,0,.72);display:grid;place-items:center;padding:18px}.p2k-trophy-modal-card{width:min(920px,96vw);max-height:92vh;overflow:auto;background:#171513;border:1px solid #715b3a;border-radius:18px;box-shadow:0 24px 70px rgba(0,0,0,.55)}.p2k-trophy-modal-head{display:flex;justify-content:space-between;gap:12px;padding:15px 18px;border-bottom:1px solid #3d3327}.p2k-trophy-modal-head button{font-size:1.6rem;background:none;color:#eee;border:0;cursor:pointer}.p2k-trophy-modal-body{display:grid;grid-template-columns:minmax(260px,.9fr) minmax(300px,1.1fr);gap:18px;padding:18px}.p2k-trophy-modal-body img{width:100%;max-height:520px;object-fit:contain;background:#0d0c0b;border-radius:12px}.p2k-trophy-detail-list{display:grid;gap:12px}.p2k-trophy-detail-list h4{margin:0 0 5px;color:#f4b23e}.p2k-trophy-detail-list ul{margin:0;padding-left:18px}.p2k-trophy-source{display:inline-flex;margin-top:8px}
.p2k-trophy-admin{display:grid;gap:14px;margin:16px 0;padding:16px;border:1px solid #6b573c;border-radius:14px;background:#191714}.p2k-trophy-admin-head{display:flex;justify-content:space-between;gap:12px;align-items:start;flex-wrap:wrap}.p2k-trophy-admin-layout{display:grid;grid-template-columns:minmax(220px,.65fr) minmax(360px,1.35fr);gap:14px}.p2k-trophy-admin-list{display:grid;gap:7px;align-content:start;max-height:560px;overflow:auto}.p2k-trophy-admin-row{display:flex;gap:8px;align-items:center;justify-content:space-between;text-align:left;background:#211e1a;color:#eee;border:1px solid #4b4033;border-radius:9px;padding:9px;cursor:pointer}.p2k-trophy-admin-row.is-selected{border-color:#d3a34f;background:#2a241c}.p2k-trophy-admin-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.p2k-trophy-admin-form label{display:grid;gap:4px;font-size:.78rem;color:#cfc8be}.p2k-trophy-admin-form label.is-wide{grid-column:1/-1}.p2k-trophy-admin-actions{display:flex;gap:8px;flex-wrap:wrap;grid-column:1/-1}.p2k-trophy-admin-actions button,.p2k-trophy-card-open{border:1px solid #6b573c;background:#2b241b;color:#f0e8dc;border-radius:9px;padding:8px 11px;cursor:pointer}.p2k-trophy-admin-actions button.is-primary{background:#76551f;border-color:#9b7130}.p2k-trophy-image-editor{grid-column:1/-1;display:grid;gap:8px;padding:10px;border:1px solid #3f362c;border-radius:10px;background:#131210}.p2k-trophy-image-editor canvas{max-width:100%;width:360px;aspect-ratio:3/2;background:#080808;border-radius:8px}.p2k-trophy-editor-controls{display:flex;gap:9px;flex-wrap:wrap}.p2k-trophy-editor-controls label{min-width:120px}.p2k-trophy-admin-note{font-size:.78rem;color:#aaa}.p2k-trophy-empty{padding:24px;text-align:center;border:1px dashed #554838;border-radius:12px;color:#aaa}
@media(max-width:760px){.p2k-trophy-stats{grid-template-columns:repeat(2,1fr)}.p2k-trophy-modal-body,.p2k-trophy-admin-layout{grid-template-columns:1fr}.p2k-trophy-admin-form{grid-template-columns:1fr}.p2k-trophy-admin-form label.is-wide{grid-column:auto}}
`;

const state = { mounted:false, records:[], selectedId:"", crop:{zoom:1,x:0,y:0,threshold:0}, cropImage:null, context:null };
const $ = (selector, root=document) => root.querySelector(selector);
const $$ = (selector, root=document) => [...root.querySelectorAll(selector)];
const esc = value => String(value ?? "").replace(/[&<>"']/g, ch => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[ch]));
const clone = value => JSON.parse(JSON.stringify(value));

function loadRecords(){
  try { const parsed=JSON.parse(localStorage.getItem(STORAGE_KEY)||"null"); if(Array.isArray(parsed)&&parsed.length)return parsed; } catch(_){}
  return clone(SEED);
}
function saveRecords(){ try{localStorage.setItem(STORAGE_KEY,JSON.stringify(state.records));}catch(_){} }
function isAdminVisible(){ const tab=document.getElementById("dashboardAdministrationTab"); return !!tab && !tab.hidden; }
function ensureStyle(){ if(document.getElementById("p2kTrophyPocStyle"))return; const style=document.createElement("style");style.id="p2kTrophyPocStyle";style.textContent=css;document.head.appendChild(style); }
function slug(){ return `poc-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,7)}`; }
function selected(){ return state.records.find(r=>r.id===state.selectedId)||null; }
function leagues(){ return [...new Set(state.records.map(r=>r.league).filter(Boolean))].sort(); }
function years(){ return [...new Set(state.records.map(r=>Number(r.year)).filter(Boolean))].sort((a,b)=>b-a); }

function ensureHall(){
  if(!isAdminVisible())return;
  const page=document.getElementById("hallOfFamePage"); if(!page)return;
  const tabs=$(".dashboard-hall-subtabs",page); if(!tabs)return;
  let tab=document.getElementById("p2kTrophyHallTab");
  if(!tab){
    tab=document.createElement("button");tab.id="p2kTrophyHallTab";tab.className="p2k-trophy-poc-tab";tab.type="button";tab.setAttribute("role","tab");tab.setAttribute("aria-selected","false");tab.dataset.hallSubtab="trophies";tab.textContent="Trophies";tabs.appendChild(tab);
    tab.addEventListener("click",event=>{event.preventDefault();activateTrophyHall();});
    tabs.addEventListener("click",event=>{const target=event.target.closest("[data-hall-subtab]");if(target&&target!==tab)hideTrophyHall();},true);
  }
  let panel=document.getElementById("p2kTrophyHallPanel");
  if(!panel){
    panel=document.createElement("div");panel.id="p2kTrophyHallPanel";panel.className="dashboard-hall-panel-stack p2k-trophy-poc-panel";panel.dataset.hallPanel="trophies";panel.setAttribute("role","tabpanel");panel.hidden=true;
    page.appendChild(panel);
  }
  renderHall();
}
function activateTrophyHall(){
  const page=document.getElementById("hallOfFamePage");if(!page)return;
  $$("[data-hall-subtab]",page).forEach(button=>{const active=button.dataset.hallSubtab==="trophies";button.classList.toggle("is-active",active);button.setAttribute("aria-selected",active?"true":"false");});
  $$("[data-hall-panel]",page).forEach(panel=>{panel.hidden=panel.dataset.hallPanel!=="trophies";});
  renderHall();
}
function hideTrophyHall(){ const panel=document.getElementById("p2kTrophyHallPanel");if(panel)panel.hidden=true;const tab=document.getElementById("p2kTrophyHallTab");if(tab){tab.classList.remove("is-active");tab.setAttribute("aria-selected","false");} }
function hallFilters(){ const panel=document.getElementById("p2kTrophyHallPanel");return {q:$("[data-trophy-q]",panel)?.value.trim().toLowerCase()||"",league:$("[data-trophy-league]",panel)?.value||"all",year:$("[data-trophy-year]",panel)?.value||"all"}; }
function renderHall(){
  const panel=document.getElementById("p2kTrophyHallPanel");if(!panel)return;
  const current=hallFilters();const published=state.records.filter(r=>r.status==="published");
  const filtered=published.filter(r=>(current.league==="all"||r.league===current.league)&&(current.year==="all"||String(r.year)===current.year)&&(!current.q||`${r.title} ${r.league} ${r.competition} ${r.award}`.toLowerCase().includes(current.q)));
  const leagueCount=new Set(published.map(r=>r.league)).size, yearCount=new Set(published.map(r=>r.year)).size, latest=Math.max(0,...published.map(r=>Number(r.year)||0));
  panel.innerHTML=`
    <section class="p2k-trophy-hero"><div><p class="dashboard-eyebrow">Admin-only proof of concept</p><h2>Promote to King Trophy Gallery</h2><p>League trophies, medals and prizes using visuals imported from the existing Chess.com gallery source.</p></div><div class="p2k-trophy-stats"><div class="p2k-trophy-stat"><span>Published awards</span><strong>${published.length}</strong></div><div class="p2k-trophy-stat"><span>Leagues</span><strong>${leagueCount}</strong></div><div class="p2k-trophy-stat"><span>Years</span><strong>${yearCount}</strong></div><div class="p2k-trophy-stat"><span>Latest</span><strong>${latest||"—"}</strong></div></div></section>
    <div class="p2k-trophy-filters"><label>Search<input data-trophy-q type="search" value="${esc(current.q)}" placeholder="League, competition, prize…"></label><label>League<select data-trophy-league><option value="all">All leagues</option>${leagues().map(v=>`<option ${current.league===v?"selected":""}>${esc(v)}</option>`).join("")}</select></label><label>Year<select data-trophy-year><option value="all">All years</option>${years().map(v=>`<option value="${v}" ${String(v)===current.year?"selected":""}>${v}</option>`).join("")}</select></label></div>
    <div class="p2k-trophy-grid">${filtered.map(cardMarkup).join("")||'<div class="p2k-trophy-empty">No published trophy matches the current filters.</div>'}</div>`;
  $("[data-trophy-q]",panel)?.addEventListener("input",renderHall);$("[data-trophy-league]",panel)?.addEventListener("change",renderHall);$("[data-trophy-year]",panel)?.addEventListener("change",renderHall);
  $$('[data-trophy-open]',panel).forEach(button=>button.addEventListener("click",()=>openModal(button.dataset.trophyOpen)));
}
function cardMarkup(record){ return `<article class="p2k-trophy-card"><button type="button" data-trophy-open="${esc(record.id)}"><div class="p2k-trophy-image"><img src="${esc(record.image)}" alt="${esc(record.title)} trophy visual" loading="lazy"></div><div class="p2k-trophy-copy"><span class="p2k-trophy-badge">${esc(record.year)}</span><h3>${esc(record.title)}</h3><p>${esc(record.league)} · ${esc(record.award)}</p></div></button></article>`; }

function ensureModal(){
  let modal=document.getElementById("p2kTrophyModal");if(modal)return modal;
  modal=document.createElement("div");modal.id="p2kTrophyModal";modal.className="p2k-trophy-modal";modal.hidden=true;modal.setAttribute("role","dialog");modal.setAttribute("aria-modal","true");document.body.appendChild(modal);
  modal.addEventListener("click",event=>{if(event.target===modal||event.target.closest("[data-trophy-close]"))modal.hidden=true;});
  document.addEventListener("keydown",event=>{if(event.key==="Escape"&&!modal.hidden)modal.hidden=true;});return modal;
}
function openModal(id){
  const record=state.records.find(r=>r.id===id);if(!record)return;const modal=ensureModal();
  modal.innerHTML=`<div class="p2k-trophy-modal-card"><div class="p2k-trophy-modal-head"><div><small>${esc(record.league)} · ${esc(record.year)}</small><h2>${esc(record.title)}</h2></div><button type="button" data-trophy-close aria-label="Close">×</button></div><div class="p2k-trophy-modal-body"><div><img src="${esc(record.image)}" alt="${esc(record.title)}"><a class="dashboard-button p2k-trophy-source" href="${esc(record.link)}" target="_blank" rel="noopener noreferrer">Relevant page ↗</a></div><div class="p2k-trophy-detail-list"><div><h4>${esc(record.award)}</h4><p>${esc(record.summary)}</p></div><div><h4>Matches involved</h4><ul>${(record.matches||[]).map(v=>`<li>${esc(v)}</li>`).join("")}</ul></div><div><h4>Players involved</h4><ul>${(record.players||[]).map(v=>`<li>${esc(v)}</li>`).join("")}</ul></div><div><h4>POC note</h4><p>${esc(record.notes||"")}</p></div></div></div></div>`;modal.hidden=false;
}

function ensureAdminPanel(){
  if(!isAdminVisible())return;const admin=document.getElementById("administrationPage");if(!admin)return;
  let toolsPanel=$("[data-admin-group-panel='tools']",admin);if(!toolsPanel)toolsPanel=admin;
  let panel=document.getElementById("p2kTrophyAdminPanel");
  if(!panel){panel=document.createElement("article");panel.id="p2kTrophyAdminPanel";panel.className="p2k-trophy-admin";toolsPanel.insertBefore(panel,toolsPanel.firstChild);}
  renderAdmin();ensureAdminCard();
}
function ensureAdminCard(){
  const host=document.getElementById("adminToolGrid");if(!host||host.querySelector("[data-trophy-admin-card]"))return;
  const card=document.createElement("article");card.className="dashboard-tool-card";card.dataset.trophyAdminCard="1";
  card.innerHTML='<div class="dashboard-tool-head"><span class="dashboard-tool-icon">🏆</span><span class="dashboard-tool-status">POC · admin only</span></div><h3>Trophy Gallery</h3><p>Draft, publish and edit league trophies, preview imported artwork and test offline crop/detour controls.</p><div class="dashboard-tool-foot"><span class="dashboard-tool-category">Team</span><button class="dashboard-button" type="button">Open</button></div>';
  card.querySelector("button").addEventListener("click",openAdminPanel);host.appendChild(card);
}
function openAdminPanel(){
  const adminTab=document.getElementById("dashboardAdministrationTab");adminTab?.click();
  setTimeout(()=>{const admin=document.getElementById("administrationPage");const toolsButton=admin?.querySelector("[data-admin-group='tools']");toolsButton?.click();setTimeout(()=>document.getElementById("p2kTrophyAdminPanel")?.scrollIntoView({behavior:"smooth",block:"start"}),50);},50);
}
function renderAdmin(){
  const panel=document.getElementById("p2kTrophyAdminPanel");if(!panel)return;if(!state.selectedId||!selected())state.selectedId=state.records[0]?.id||"";const record=selected();
  panel.innerHTML=`<div class="p2k-trophy-admin-head"><div><p class="dashboard-eyebrow">Admin-only proof of concept</p><h2>Trophy Gallery administration</h2><p>POC state is browser-local. Production persistence/API is intentionally not introduced on this branch.</p></div><div class="p2k-trophy-admin-actions"><button type="button" data-trophy-add class="is-primary">Add trophy</button><button type="button" data-trophy-reset>Reset POC data</button><button type="button" data-trophy-open-hall>Open Hall gallery</button></div></div><div class="p2k-trophy-admin-layout"><div class="p2k-trophy-admin-list">${state.records.map(r=>`<button type="button" class="p2k-trophy-admin-row ${r.id===state.selectedId?"is-selected":""}" data-trophy-select="${esc(r.id)}"><span><strong>${esc(r.title)}</strong><br><small>${esc(r.league)} · ${esc(r.year)}</small></span><span class="p2k-trophy-badge ${r.status==='draft'?'is-draft':''}">${esc(r.status)}</span></button>`).join("")}</div>${record?editorMarkup(record):'<div class="p2k-trophy-empty">Add the first trophy.</div>'}</div>`;
  $$('[data-trophy-select]',panel).forEach(button=>button.addEventListener("click",()=>{state.selectedId=button.dataset.trophySelect;state.crop={zoom:1,x:0,y:0,threshold:0};state.cropImage=null;renderAdmin();}));
  $("[data-trophy-add]",panel)?.addEventListener("click",addRecord);$("[data-trophy-reset]",panel)?.addEventListener("click",resetData);$("[data-trophy-open-hall]",panel)?.addEventListener("click",openHallFromAdmin);
  if(record)bindEditor(record,panel);
}
function editorMarkup(r){ return `<form class="p2k-trophy-admin-form" data-trophy-form><label>League<input name="league" value="${esc(r.league)}"></label><label>Year<input name="year" type="number" min="2000" max="2100" value="${esc(r.year)}"></label><label>Competition<input name="competition" value="${esc(r.competition)}"></label><label>Award / prize<input name="award" value="${esc(r.award)}"></label><label class="is-wide">Title<input name="title" value="${esc(r.title)}"></label><label class="is-wide">Relevant page<input name="link" value="${esc(r.link)}"></label><label class="is-wide">Image URL<input name="image" value="${esc(r.image)}"></label><label>Status<select name="status"><option value="draft" ${r.status==='draft'?'selected':''}>Draft</option><option value="published" ${r.status==='published'?'selected':''}>Published</option></select></label><label>Summary<input name="summary" value="${esc(r.summary)}"></label><label class="is-wide">Matches (one per line)<textarea name="matches" rows="3">${esc((r.matches||[]).join("\n"))}</textarea></label><label class="is-wide">Players (one per line)<textarea name="players" rows="3">${esc((r.players||[]).join("\n"))}</textarea></label><section class="p2k-trophy-image-editor"><strong>Offline image crop / detour POC</strong><span class="p2k-trophy-admin-note">Load an image locally, crop it with zoom/offset and optionally remove a corner-colour background by threshold. Processing stays in the browser.</span><input type="file" accept="image/*" data-trophy-file><canvas width="720" height="480" data-trophy-canvas></canvas><div class="p2k-trophy-editor-controls"><label>Zoom<input data-crop-zoom type="range" min="1" max="3" step="0.05" value="${state.crop.zoom}"></label><label>Horizontal<input data-crop-x type="range" min="-100" max="100" value="${state.crop.x}"></label><label>Vertical<input data-crop-y type="range" min="-100" max="100" value="${state.crop.y}"></label><label>Detour threshold<input data-crop-threshold type="range" min="0" max="150" value="${state.crop.threshold}"></label><button type="button" data-crop-apply>Use processed PNG</button><button type="button" data-crop-reset>Restore imported image</button></div></section><div class="p2k-trophy-admin-actions"><button type="submit" class="is-primary">Save changes</button><button type="button" data-trophy-toggle>${r.status==='published'?'Move to draft':'Publish'}</button><button type="button" data-trophy-duplicate>Duplicate</button><button type="button" data-trophy-delete>Delete</button></div></form>`; }
function bindEditor(record,panel){
  const form=$("[data-trophy-form]",panel);form.addEventListener("submit",event=>{event.preventDefault();const data=new FormData(form);Object.assign(record,{league:String(data.get("league")||"").trim(),year:Number(data.get("year")||0),competition:String(data.get("competition")||"").trim(),award:String(data.get("award")||"").trim(),title:String(data.get("title")||"").trim(),link:String(data.get("link")||"").trim(),image:String(data.get("image")||"").trim(),status:data.get("status")==="published"?"published":"draft",summary:String(data.get("summary")||"").trim(),matches:String(data.get("matches")||"").split(/\r?\n/).map(v=>v.trim()).filter(Boolean),players:String(data.get("players")||"").split(/\r?\n/).map(v=>v.trim()).filter(Boolean)});saveRecords();renderAdmin();renderHall();});
  $("[data-trophy-toggle]",panel).addEventListener("click",()=>{record.status=record.status==="published"?"draft":"published";saveRecords();renderAdmin();renderHall();});
  $("[data-trophy-duplicate]",panel).addEventListener("click",()=>{const copy=clone(record);copy.id=slug();copy.title=`${copy.title} copy`;copy.status="draft";state.records.push(copy);state.selectedId=copy.id;saveRecords();renderAdmin();});
  $("[data-trophy-delete]",panel).addEventListener("click",()=>{if(!confirm(`Delete ${record.title}?`))return;state.records=state.records.filter(r=>r.id!==record.id);state.selectedId=state.records[0]?.id||"";saveRecords();renderAdmin();renderHall();});
  const file=$("[data-trophy-file]",panel);file.addEventListener("change",()=>{const f=file.files?.[0];if(!f)return;const reader=new FileReader();reader.onload=()=>loadCropImage(String(reader.result||""),panel);reader.readAsDataURL(f);});
  $$('[data-crop-zoom],[data-crop-x],[data-crop-y],[data-crop-threshold]',panel).forEach(input=>input.addEventListener("input",()=>{state.crop.zoom=Number($("[data-crop-zoom]",panel).value);state.crop.x=Number($("[data-crop-x]",panel).value);state.crop.y=Number($("[data-crop-y]",panel).value);state.crop.threshold=Number($("[data-crop-threshold]",panel).value);drawCrop(panel);}));
  $("[data-crop-apply]",panel).addEventListener("click",()=>{const canvas=$("[data-trophy-canvas]",panel);if(!state.cropImage)return;record.image=canvas.toDataURL("image/png");form.elements.image.value=record.image;saveRecords();renderHall();});
  $("[data-crop-reset]",panel).addEventListener("click",()=>{record.image=record.sourceImage||record.image;form.elements.image.value=record.image;state.cropImage=null;saveRecords();drawCrop(panel);renderHall();});
  loadCropImage(record.image,panel,true);
}
function loadCropImage(src,panel,silent=false){ const image=new Image();image.crossOrigin="anonymous";image.onload=()=>{state.cropImage=image;drawCrop(panel);};image.onerror=()=>{if(!silent)alert("The selected image could not be loaded for canvas processing.");};image.src=src; }
function drawCrop(panel){ const canvas=$("[data-trophy-canvas]",panel);if(!canvas)return;const ctx=canvas.getContext("2d",{willReadFrequently:true});ctx.clearRect(0,0,canvas.width,canvas.height);ctx.fillStyle="#080808";ctx.fillRect(0,0,canvas.width,canvas.height);const image=state.cropImage;if(!image)return;const base=Math.max(canvas.width/image.naturalWidth,canvas.height/image.naturalHeight),scale=base*state.crop.zoom,w=image.naturalWidth*scale,h=image.naturalHeight*scale,x=(canvas.width-w)/2+(state.crop.x/100)*(canvas.width/2),y=(canvas.height-h)/2+(state.crop.y/100)*(canvas.height/2);ctx.drawImage(image,x,y,w,h);if(state.crop.threshold>0){try{const data=ctx.getImageData(0,0,canvas.width,canvas.height),p=data.data,corner=[p[0],p[1],p[2]],threshold=state.crop.threshold;for(let i=0;i<p.length;i+=4){const d=Math.hypot(p[i]-corner[0],p[i+1]-corner[1],p[i+2]-corner[2]);if(d<threshold)p[i+3]=0;}ctx.putImageData(data,0,0);}catch(_){/* Cross-origin source: crop still works visually; detour requires a locally selected image. */}} }
function addRecord(){ const r={id:slug(),status:"draft",league:"New league",year:new Date().getUTCFullYear(),competition:"New competition",title:"New trophy",award:"Prize",image:"",sourceImage:"",link:SOURCE_PAGE,summary:"Draft POC record",matches:[],players:[],notes:"POC details only."};state.records.push(r);state.selectedId=r.id;saveRecords();renderAdmin(); }
function resetData(){ if(!confirm("Reset the Trophy Gallery POC to its imported seed data?"))return;state.records=clone(SEED);state.selectedId=state.records[0].id;saveRecords();renderAdmin();renderHall(); }
function openHallFromAdmin(){ const hall=document.querySelector("[data-public-page='hall']");hall?.click();setTimeout(()=>{ensureHall();activateTrophyHall();document.getElementById("p2kTrophyHallPanel")?.scrollIntoView({behavior:"smooth",block:"start"});},60); }

function sync(){ if(!isAdminVisible())return;ensureStyle();ensureHall();ensureAdminPanel();ensureModal(); }
function mount(context={}){
  state.context=context;state.records=loadRecords();if(!state.selectedId)state.selectedId=state.records[0]?.id||"";
  const adminTab=document.getElementById("dashboardAdministrationTab");
  if(adminTab){const observer=new MutationObserver(sync);observer.observe(adminTab,{attributes:true,attributeFilter:["hidden"]});}
  const grid=document.getElementById("adminToolGrid");if(grid){const observer=new MutationObserver(()=>{if(isAdminVisible())ensureAdminCard();});observer.observe(grid,{childList:true});}
  sync();state.mounted=true;
}
window.P2K_TROPHY_GALLERY_POC=Object.freeze({mount,openAdminPanel,openHall:openHallFromAdmin,get records(){return clone(state.records);}});
})();
