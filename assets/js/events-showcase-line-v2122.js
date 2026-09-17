(() => {
"use strict";
const api = window.P2K_EVENTS_SHOWCASE_CORE;
const rows = document.getElementById("pmWidgetRows");
const arenaRows = document.getElementById("pmArenaRows");
const arenaSeparator = document.getElementById("pmArenaDailySeparator");
const arenaGap = document.getElementById("pmArenaDailyGap");
let matches = []; let arenaConfig = []; let filter = "league"; let arenaTimer = 0; let arenaCleanupPending = false; let hydratedFilters = new Set();
async function loadState(){ const response=await fetch("api.php?action=state",{credentials:"same-origin",cache:"no-store"}); const payload=await response.json().catch(()=>({})); if(!response.ok||payload?.ok===false) throw new Error(payload?.error?.message||`HTTP ${response.status}`); return payload; }
function chooseDefault() {
if (matches.some(m => m.category === "league")) return "league";
if (matches.some(m => m.category === "friendly")) return "friendly";
return "league";
}
function resizeParent() {
try { parent.postMessage({ type:"p2k-priority-matches-height", height:Math.ceil(document.documentElement.scrollHeight) }, "*"); } catch (_) {}
}
function recruitmentSeverity(count) {
const n=Number(count);
if (!Number.isFinite(n) || n <= 0) return "";
if (n >= 8) return "urgent";
if (n >= 4) return "attention";
return "";
}
function fitRows() {
document.querySelectorAll(".pm-widget-row").forEach(row => {
if (row.classList.contains("pm-arena-row")) {
row.classList.remove("pm-hide-arena-time","pm-hide-arena-duration");
const cramped=()=>row.scrollWidth > row.clientWidth + 1;
if (cramped()) row.classList.add("pm-hide-arena-time");
if (cramped()) row.classList.add("pm-hide-arena-duration");
return;
}
row.classList.remove("pm-hide-time","pm-hide-pin","pm-hide-start");
const opponent=row.querySelector(".pm-widget-opponent");
const cramped=()=>row.scrollWidth > row.clientWidth + 1 || (opponent && opponent.clientWidth < Math.min(92, row.clientWidth * .24));
if (cramped()) row.classList.add("pm-hide-time");
if (cramped()) row.classList.add("pm-hide-pin");
if (cramped()) row.classList.add("pm-hide-start");
});
}
function bindRows() {
document.querySelectorAll(".pm-widget-row[data-href]").forEach(row => {
if (row.dataset.pmBound === "1") return;
row.dataset.pmBound = "1";
const open=()=>window.open(row.dataset.href,"_blank","noopener,noreferrer");
row.addEventListener("click",event=>{ if(event.target.closest("a,button")) return; open(); });
row.addEventListener("keydown",event=>{ if(event.target!==row) return; if(event.key==="Enter"||event.key===" "){event.preventDefault();open();} });
});
}
function formatCountdown(ms) {
const total=Math.max(0,Math.ceil(ms/1000));
const h=Math.floor(total/3600);
const m=Math.floor((total%3600)/60);
const sec=total%60;
return `${h}h ${String(m).padStart(2,"0")}m ${String(sec).padStart(2,"0")}s`;
}
function formatStartsIn(ms) {
return `Starts in ${Math.max(1,Math.ceil(ms/60000))} mn`;
}
function arenaTiming(arena, now=Date.now()) {
const start=Date.parse(arena?.startAt || "");
const durationMinutes=Number(arena?.durationMinutes);
if (!Number.isFinite(start) || !Number.isFinite(durationMinutes) || durationMinutes <= 0) return null;
const end=start + durationMinutes*60000;
if (now >= end) return null;
const phase = now < start-3600000 ? "upcoming" : now < start ? "registration" : "ongoing";
return {start,end,phase};
}
function formatArenaStart(start) {
const date=new Date(start);
if (!Number.isFinite(date.getTime())) return "—";
const now=new Date();
const tomorrow=new Date(now); tomorrow.setDate(now.getDate()+1);
const sameDay=(a,b)=>a.getFullYear()===b.getFullYear()&&a.getMonth()===b.getMonth()&&a.getDate()===b.getDate();
const time=new Intl.DateTimeFormat("en-GB",{hour:"2-digit",minute:"2-digit",hour12:false}).format(date);
if (sameDay(date,now)) return `Today ${time}`;
if (sameDay(date,tomorrow)) return `Tomorrow ${time}`;
return new Intl.DateTimeFormat("en-GB",{day:"numeric",month:"short",hour:"2-digit",minute:"2-digit",hour12:false}).format(date).replace(",","");
}
function renderArenas(arenas=arenaConfig) {
arenaConfig=Array.isArray(arenas)?arenas:[];
const now=Date.now();
const visible=arenaConfig
.filter(arena => arena?.active === true && arena?.name && arena?.link)
.map(arena => ({arena,timing:arenaTiming(arena,now)}))
.filter(entry => entry.timing)
.sort((a,b)=>a.timing.start-b.timing.start)
.slice(0,2);
arenaRows.hidden = visible.length === 0;
arenaSeparator.hidden = visible.length !== 0;
arenaGap.hidden = visible.length === 0;
arenaRows.innerHTML = visible.map(({arena,timing}) => {
const duration = arena.duration ? `<span class="pm-widget-chip pm-arena-duration">${api.escapeHTML(arena.duration)}</span>` : "";
const time = arena.timeControl ? `<span class="pm-widget-chip pm-arena-time">${api.escapeHTML(arena.timeControl)}</span>` : "";
const status = timing.phase === "upcoming"
? '<span class="pm-arena-status upcoming">Upcoming</span>'
: timing.phase === "registration"
? '<span class="pm-arena-status registration">Registration</span>'
: '<span class="pm-arena-status">On-going</span>';
const countdown = timing.phase === "ongoing"
? `<span class="pm-widget-chip pm-arena-countdown" data-arena-countdown title="Time remaining">${api.escapeHTML(formatCountdown(timing.end-now))}</span>`
: timing.phase === "registration"
? `<span class="pm-widget-chip pm-arena-countdown registration" data-arena-countdown title="Time until start">${api.escapeHTML(formatStartsIn(timing.start-now))}</span>`
: `<span class="pm-widget-start pm-arena-start" title="${api.escapeHTML(api.formatStart(timing.start/1000))}">${api.escapeHTML(formatArenaStart(timing.start))}</span>`;
return `<div class="dashboard-recommendation pm-widget-row pm-arena-row" role="link" tabindex="0" data-href="${api.escapeHTML(arena.link)}" data-arena-start="${timing.start}" data-arena-end="${timing.end}" data-arena-phase="${timing.phase}" title="${api.escapeHTML(arena.name)}">${status}<span class="pm-arena-name">${api.escapeHTML(arena.name)}</span>${duration}${time}${countdown}</div>`;
}).join('<hr class="pm-widget-separator" aria-hidden="true">');
bindRows();
requestAnimationFrame(fitRows);
}
async function cleanupExpiredArenas() {
if (arenaCleanupPending) return;
arenaCleanupPending=true;
try {
const state=await loadState();
arenaConfig=state.arenas || [];
renderArenas(arenaConfig);
} catch (_) {
renderArenas(arenaConfig);
} finally { arenaCleanupPending=false; }
}
function tickArenaClock() {
const now=Date.now();
let phaseChanged=false, expired=false;
document.querySelectorAll(".pm-arena-row[data-arena-start]").forEach(row=>{
const start=Number(row.dataset.arenaStart), end=Number(row.dataset.arenaEnd);
if (now >= end) { expired=true; return; }
if (row.dataset.arenaPhase === "upcoming" && now >= start-3600000) { phaseChanged=true; return; }
if (row.dataset.arenaPhase === "registration" && now >= start) { phaseChanged=true; return; }
const countdown=row.querySelector("[data-arena-countdown]");
if (countdown) countdown.textContent=row.dataset.arenaPhase === "registration"
? formatStartsIn(start-now)
: formatCountdown(end-now);
});
if (expired) { void cleanupExpiredArenas(); return; }
if (phaseChanged) renderArenas(arenaConfig);
}
function startArenaClock() {
if (arenaTimer) clearInterval(arenaTimer);
arenaTimer=window.setInterval(tickArenaClock,1000);
}
function render() {
document.querySelectorAll("[data-filter]").forEach(button => {
const active=button.dataset.filter===filter;
button.classList.toggle("is-active",active);
button.setAttribute("aria-selected",active?"true":"false");
});
const visible = matches.filter(match => match.joinable !== false && match.category === filter).slice(0, 4);
rows.innerHTML = visible.map(match => {
const badge = match.category === "league" ? ((match.leagueAcronyms||[]).join("/") || "League") : "Friendly";
const rating = match.ratingLabel || "Open";
const urgency = api.startUrgency(match.startTime);
const timeCompact = api.timePerMoveCompactLabel(match.timePerMove);
const timeFull = api.timePerMoveLabel(match.timePerMove);
const recruitment = match.recruitment;
const count = Number.isFinite(Number(recruitment?.count)) ? Number(recruitment.count) : null;
const ready = count === 0;
const severity = recruitmentSeverity(count);
const needText = count === null ? "Need ?" : ready ? "Need ✓" : `Need ${count}`;
const opponent = match.opponentName || "Opponent";
const startMs = Number(match.startTime) * 1000;
const leagueWithin48h = match.category === "league" && Number.isFinite(startMs) && startMs >= Date.now() && startMs - Date.now() <= 48 * 3600000;
const pin = match.urgent ? '<span class="pm-widget-pin" title="Manually marked urgent by a P2K administrator">Priority</span>' : '';
const time = timeCompact ? `<span class="pm-widget-chip pm-widget-time" title="${api.escapeHTML(timeFull)}">${api.escapeHTML(timeCompact)}</span>` : '';
return `<div class="dashboard-recommendation pm-widget-row${match.category==="league"?" is-priority":""}${leagueWithin48h?" pm-league-48h":""}${match.urgent?" is-admin-urgent":""}" role="link" tabindex="0" data-href="${api.escapeHTML(match.url)}" title="${api.escapeHTML(match.name)}"><span class="dashboard-tag ${match.category}">${api.escapeHTML(badge)}</span><span class="pm-widget-opponent">vs ${api.escapeHTML(opponent)}</span>${pin}<span class="pm-widget-start ${api.escapeHTML(urgency.level)}" title="${api.escapeHTML(urgency.title)}">${api.escapeHTML(urgency.label)}</span><span class="pm-widget-chip pm-widget-rating" title="Rating category: ${api.escapeHTML(rating)}">${api.escapeHTML(rating)}</span>${time}<span class="pm-widget-chip pm-widget-recruits${ready?" ready":""}${severity?` ${severity}`:""}">${api.escapeHTML(needText)}</span></div>`;
}).join('<hr class="pm-widget-separator" aria-hidden="true">') || '<div class="pm-widget-empty">No open priority match in this category.</div>';
bindRows();
requestAnimationFrame(()=>{ fitRows(); resizeParent(); });
}
async function hydrateFilter(category) {
if (hydratedFilters.has(category)) return;
hydratedFilters.add(category);
const targets = matches.filter(match => match.category === category).slice(0, 4);
if (!targets.length) return;
const applyUpdate = updated => {
const id=String(updated?.matchId||"");
if(!id)return;
matches=matches.map(match=>String(match.matchId)===id?updated:match);
render();
};
const enriched = await api.enrichMatches(targets,{onUpdate:applyUpdate});
const byId = new Map(enriched.map(match => [String(match.matchId), match]));
matches = matches.map(match => byId.get(String(match.matchId)) || match);
}
async function selectFilter(category) {
filter=category;
render();
await hydrateFilter(category);
render();
}
async function load() {
rows.innerHTML = '<div class="pm-widget-empty">Loading priority matches…</div>';
const selection = await loadState();
arenaConfig=selection.arenas || [];
renderArenas(arenaConfig);
startArenaClock();
hydratedFilters = new Set();
const localCatalog = api.normalizeCatalog(selection.catalog || []);
const byId = new Map(localCatalog.map(match => [String(match.matchId), match]));
matches = (selection.items || [])
.filter(item => item.enabled !== false)
.map(item => {
const match=byId.get(String(item.matchId));
return match ? { ...match, urgent:item.urgent === true } : null;
})
.filter(Boolean);
filter = chooseDefault();
render();
await hydrateFilter(filter);
render();
}
document.querySelectorAll("[data-filter]").forEach(button => button.addEventListener("click", () => { void selectFilter(button.dataset.filter); }));
window.addEventListener("resize",()=>requestAnimationFrame(fitRows));
window.addEventListener("message", event => { if (event.data?.type === "p2k-events-showcase-refresh") load().catch(() => {}); });
load().catch(error => { console.error(error); rows.innerHTML='<div class="pm-widget-empty">Priority matches are temporarily unavailable.</div>'; resizeParent(); });
})();
