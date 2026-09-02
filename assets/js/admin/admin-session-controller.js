/* Existing Admin authorization, priority health and throughput behavior. */
(() => {
"use strict";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.adminSession = Object.freeze({create(context) {
const { state, byId, escapeHTML, number, setText, showToast, config, clubSlug, clubProfileAPI, loadJSON, setAdmin, renderView, writeNavigationState, adminShellOpenDetail, adminShellHref } = context;
function adminEntryUsername(entry) {
if (entry && typeof entry === "object") return adminEntryUsername(entry.username || entry.name || entry.url || entry["@id"] || "");
const value = String(entry || "").trim();
if (!value) return "";
try {
const url = new URL(value, window.location.href);
const parts = url.pathname.split("/").filter(Boolean);
const playerIndex = parts.findIndex(part => ["player", "member"].includes(part.toLowerCase()));
return decodeURIComponent(playerIndex >= 0 ? parts[playerIndex + 1] || "" : parts.at(-1) || "").toLowerCase();
} catch (_) { return value.replace(/^@/, "").toLowerCase(); }
}
function clubAdminUsernames(profile) {
const values = [profile?.admin, profile?.admins, profile?.super_admin, profile?.super_admins];
const flattened = [];
const collect = value => {
if (Array.isArray(value)) return value.forEach(collect);
if (value && typeof value === "object") {
const direct = value.username || value.name || value.url || value["@id"];
if (direct) flattened.push(direct);
for (const nested of Object.values(value)) {
if (nested !== direct) collect(nested);
}
return;
}
if (value) flattened.push(value);
};
values.forEach(collect);
return new Set(flattened.map(adminEntryUsername).filter(Boolean));
}
function ensureAdminPriorityCard() {
if (!state.admin) return null;
let card = byId("dashboardAdminPriorityCard");
if (card) {
renderAdminPriorityCard();
return card;
}
const primaryGrid = document.querySelector("#publicDashboardPanel .dashboard-primary-grid");
if (!primaryGrid) return null;
card = document.createElement("section");
card.id = "dashboardAdminPriorityCard";
card.className = "dashboard-card dashboard-admin-priority-card";
card.setAttribute("aria-labelledby", "dashboardAdminPriorityTitle");
card.innerHTML = `
      <header class="dashboard-admin-priority-head">
        <div>
          <p class="dashboard-admin-priority-eyebrow">Administrator</p>
          <h3 id="dashboardAdminPriorityTitle">Action queue</h3>
          <p>Only issues that may require an administrator decision or intervention.</p>
        </div>
        <div class="dashboard-admin-priority-head-side">
          <span class="dashboard-admin-priority-status is-loading" id="dashboardAdminPriorityStatus">Preparing queue…</span>
          <span class="dashboard-admin-priority-updated" id="dashboardAdminPriorityUpdated">OAuth/local admin</span>
        </div>
      </header>
      <div class="dashboard-admin-priority-body">
        <div class="dashboard-admin-priority-metrics">
          <button type="button" class="is-bad" data-admin-metric="underfilled"><span>Below minimum</span><strong id="adminPriorityUnderfilled">—</strong><small>Registration is below the match minimum and starts within 7 days.</small></button>
          <button type="button" class="is-warn" data-admin-metric="starts48"><span>Start within 48 h</span><strong id="adminPriorityStarts48">—</strong><small>All imminent match starts, filled or not.</small></button>
          <button type="button" class="is-warn" data-admin-metric="league"><span>League recruitment</span><strong id="adminPriorityLeague">—</strong><small id="adminPriorityLeagueDetail">Recommended competitive recruitment.</small></button>
          <button type="button" class="is-info" data-admin-metric="exceptions"><span>Operational exceptions</span><strong id="adminPriorityExceptions">—</strong><small>Failed, partial or overdue scheduled tasks.</small></button>
          <button type="button" class="is-info" data-admin-metric="new24"><span>New matches · 24 h</span><strong id="adminPriorityNew24">—</strong><small>First discovered in the last 24 hours.</small></button>
        </div>
        <div class="dashboard-admin-priority-columns">
          <section>
            <div class="dashboard-admin-priority-panel-title"><h4>Highest-priority actions</h4><small>Urgency · competition · shortfall</small></div>
            <div class="dashboard-admin-priority-queue" id="dashboardAdminPriorityQueue"></div>
          </section>
          <aside>
            <div class="dashboard-admin-priority-panel-title"><h4>System health</h4><span class="dashboard-admin-overall-health is-loading" id="adminOverallHealth">Checking…</span></div>
            <div class="dashboard-admin-priority-health" id="dashboardAdminPriorityHealth"></div>
          </aside>
        </div>
      </div>
      <footer class="dashboard-admin-priority-footer">
        <span>Recruitment and start-time risks come from the existing Match Assistant scan.</span>
        <div>
          <a class="dashboard-admin-priority-action is-secondary" data-admin-priority-link="upcoming">Upcoming matches</a>
          <a class="dashboard-admin-priority-action is-secondary" data-admin-priority-link="logs">Task logs</a>
          <button class="dashboard-admin-priority-action" data-admin-priority-open-admin type="button">Administration</button>
        </div>
      </footer>`;
primaryGrid.insertAdjacentElement("afterend", card);
const upcoming = card.querySelector('[data-admin-priority-link="upcoming"]');
if (upcoming) upcoming.href = integratedAdminHref("upcoming");
const logs = card.querySelector('[data-admin-priority-link="logs"]');
if (logs) logs.href = integratedAdminHref("logs");
card.querySelector("[data-admin-priority-open-admin]")?.addEventListener("click", () => {
if (!state.admin) return;
state.view = "admin";
ensureAdminInterface();
renderView();
writeNavigationState();
});
card.querySelectorAll("[data-admin-metric]").forEach(button => button.addEventListener("click", () => openAdminMetricModal(button.dataset.adminMetric)));
renderAdminPriorityCard();
return card;
}
function removeAdminPriorityCard() {
byId("dashboardAdminPriorityCard")?.remove();
}
function integratedAdminHref(tool, extra = {}) {
const url=new URL("ui-v2.html",location.href);url.searchParams.set("ui","v2");url.searchParams.set("page", "administration");url.searchParams.set("adminTool",tool);
Object.entries(extra).forEach(([key,value])=>{if(value!=null&&String(value)!=="")url.searchParams.set(key,String(value))});return url.href;
}
function adminPriorityActionHref(item) {
if (String(item?.action || "").toLowerCase() === "analyze") {
const reference = String(item?.apiUrl || item?.url || "").trim();
return integratedAdminHref("open", reference ? { match: reference, club: "promote-to-king", scope: "promote-to-king" } : { club: "promote-to-king", scope: "promote-to-king" });
}
return integratedAdminHref("upcoming");
}
function adminPriorityHealthRow(entry) {
const row = document.createElement(entry.url ? "a" : "article");
row.className = "dashboard-admin-priority-health-row";
if (entry.url) {
row.href = preservedURL(entry.url).href;
row.title = `Open ${entry.name} maintenance`;
}
const copy = document.createElement("div");
const name = document.createElement("strong");
name.textContent = entry.name;
const detail = document.createElement("small");
detail.textContent = entry.detail;
const mark = document.createElement("b");
mark.className = `is-${entry.tone}`;
mark.textContent = entry.mark;
copy.append(name, detail);
row.append(copy, mark);
return row;
}
function adminMatchListHtml(rows) {
const items = Array.isArray(rows) ? rows : [];
if (!items.length) return '<p class="p2k-table-status">No matching items.</p>';
return `<div class="p2k-compact-list">${items.map(item => `<button type="button" data-admin-match-detail="${Number(item.match_id || item.matchId || 0)}"><span>${escapeHTML(item.status || (item.league ? "League" : "Match"))}</span><strong>${escapeHTML(item.name || item.title || "Team match")}</strong><small>${item.first_discovered_at ? `Discovered ${escapeHTML(formatRelative(`${String(item.first_discovered_at).replace(" ","T")}Z`))}` : item.detail ? escapeHTML(item.detail) : ""}</small></button>`).join("")}</div>`;
}
async function openAdminFreshMatchDetail(matchId) {
const id=Number(matchId)||0;if(!id)return;
showInsightsModal({eyebrow:"Administrator dashboard",title:`Match ${id}`,subtitle:"Refreshing from Chess.com…",html:'<p class="p2k-table-status">Fetching the latest match payload before opening the profile…</p>'});
try{
const client=window.P2K_TEAM_POINTS_CLIENT;
if(!client?.endpointRequest)throw new Error("The secured Team Points client is not available on this page.");
await client.endpointRequest("server/team-points/public/match-detail-refresh.php",{method:"POST",body:{match_id:id},requestKind:"match-detail-refresh",timeoutMs:45000});
await openMatchDetail(id,{replaceInitial:true});
}catch(error){
showInsightsModal({replace:true,eyebrow:"Administrator dashboard",title:`Match ${id}`,subtitle:"Immediate refresh failed",html:`<p class="p2k-table-status is-error">${escapeHTML(error.message||error)}</p><div class="p2k-profile-actions"><button type="button" class="dashboard-button" data-open-stored-match>Open stored profile</button></div>`});
byId("insightsDetailBody")?.querySelector("[data-open-stored-match]")?.addEventListener("click",()=>openMatchDetail(id));
}
}
function openAdminMetricModal(kind) {
const rows = Array.isArray(state.adminPriorityData?.rows) ? state.adminPriorityData.rows : [];
const health = Array.isArray(state.adminPriorityHealth) ? state.adminPriorityHealth : [];
let title="Admin metric", subtitle="", html="";
if (kind === "underfilled") { title="Matches below minimum"; html=adminMatchListHtml(rows.filter(r=>r.belowMinimumWarning)); }
else if (kind === "starts48") { title="Matches starting within 48 h"; html=adminMatchListHtml(rows.filter(r=>r.startsWithin48)); }
else if (kind === "league") { title="League recruitment"; html=adminMatchListHtml(rows.filter(r=>r.league&&r.needsRecruitment)); }
else if (kind === "new24") { title="New matches in the last 24 hours"; subtitle="Newest first · based on the durable first-discovered timestamp"; html=adminMatchListHtml(state.adminRecentMatches); }
else { title="Operational exceptions"; const bad=health.filter(r=>r.tone!=="good"); html=bad.length?`<div class="p2k-compact-list">${bad.map(r=>`<a href="${escapeHTML(r.url||"#")}"><span>${escapeHTML(r.name)}</span><strong>${escapeHTML(r.detail)}</strong></a>`).join("")}</div>`:'<p class="p2k-table-status">No current scheduled-task or storage exceptions.</p>'; }
showInsightsModal({eyebrow:"Administrator dashboard",title,subtitle,html});
byId("insightsDetailBody")?.querySelectorAll("[data-admin-match-detail]").forEach(button=>button.addEventListener("click",()=>kind==="new24"?openAdminFreshMatchDetail(Number(button.dataset.adminMatchDetail)):openMatchDetail(Number(button.dataset.adminMatchDetail))));
}
function renderAdminPriorityCard() {
const card = byId("dashboardAdminPriorityCard");
if (!card || !state.admin) return;
const payload = state.adminPriorityData;
const metrics = payload?.metrics || {};
const health = Array.isArray(state.adminPriorityHealth) ? state.adminPriorityHealth : [];
const healthExceptions = health.filter(item => item.tone !== "good").length;
const queue = Array.isArray(payload?.queue) ? payload.queue : [];
const attentionCount = queue.length + healthExceptions + Math.max(0, Number(state.adminPriorityFailures) || 0);
setText("adminPriorityUnderfilled", payload ? number(metrics.underfilled || 0) : "—");
setText("adminPriorityStarts48", payload ? number(metrics.starts48 || 0) : "—");
setText("adminPriorityLeague", payload ? number(metrics.leagueRecruitment || 0) : "—");
setText("adminPriorityLeagueDetail", payload ? `${number(metrics.recruitsAdvised || 0)} total recruit${Number(metrics.recruitsAdvised || 0) === 1 ? "" : "s"} currently advised.` : "Recommended competitive recruitment.");
setText("adminPriorityExceptions", state.adminPriorityHealth ? number(healthExceptions + Math.max(0, Number(state.adminPriorityFailures) || 0)) : "—");
setText("adminPriorityNew24", state.adminRecentMatches ? number(state.adminRecentMatches.length) : "—");
const status = byId("dashboardAdminPriorityStatus");
const hasBad = queue.some(item => item.tone === "bad") || health.some(item => item.tone === "bad");
const hasWarn = health.some(item => item.tone === "warn");
const healthIndicator = byId("adminOverallHealth");
if (healthIndicator) {
healthIndicator.className = `dashboard-admin-overall-health ${!health.length ? "is-loading" : hasBad ? "is-bad" : hasWarn ? "is-warn" : "is-good"}`;
healthIndicator.textContent = !health.length ? "Checking…" : hasBad ? "Attention" : hasWarn ? "Degraded" : "Healthy";
}
const hasData = Boolean(payload) || Boolean(state.adminPriorityError);
status.className = `dashboard-admin-priority-status ${!hasData ? "is-loading" : hasBad ? "is-bad" : attentionCount ? "is-warn" : "is-good"}`;
status.textContent = !hasData
? "Preparing queue…"
: state.adminPriorityError
? "Queue unavailable"
: attentionCount
? `${number(attentionCount)} item${attentionCount === 1 ? "" : "s"} need attention`
: "No urgent action";
card.classList.toggle("is-critical", hasBad);
const newest = [payload?.generatedAt, state.adminPriorityHealthLoadedAt ? new Date(state.adminPriorityHealthLoadedAt).toISOString() : ""].filter(Boolean).sort().at(-1);
setText("dashboardAdminPriorityUpdated", newest ? `Updated ${formatRelative(newest)}` : "OAuth/local admin");
const queueHost = byId("dashboardAdminPriorityQueue");
queueHost.replaceChildren();
if (state.adminPriorityError) {
const empty = document.createElement("p");
empty.className = "dashboard-admin-priority-empty is-error";
empty.textContent = state.adminPriorityError;
queueHost.appendChild(empty);
} else if (!payload) {
const empty = document.createElement("p");
empty.className = "dashboard-admin-priority-empty";
empty.textContent = "Waiting for the current Match Assistant analysis…";
queueHost.appendChild(empty);
} else if (!queue.length) {
const empty = document.createElement("p");
empty.className = "dashboard-admin-priority-empty is-good";
empty.textContent = "No registration or start-time intervention is currently required.";
queueHost.appendChild(empty);
} else {
for (const item of queue) {
const row = document.createElement("article");
row.className = `dashboard-admin-priority-item is-${["bad", "warn", "info"].includes(item.tone) ? item.tone : "info"}`;
const dot = document.createElement("i");
dot.className = "dashboard-admin-priority-dot";
dot.setAttribute("aria-hidden", "true");
const copy = document.createElement("div");
const title = document.createElement("strong");
title.textContent = item.title || "Team match";
const detail = document.createElement("small");
detail.textContent = item.detail || "Administrator review recommended";
copy.append(title, detail);
const probability = document.createElement("span");
const probabilityValue = Number(item.winProbability);
const probabilityTone = !Number.isFinite(probabilityValue) ? "unknown" : probabilityValue >= 65 ? "good" : probabilityValue >= 45 ? "warn" : "bad";
probability.className = `dashboard-admin-priority-probability is-${probabilityTone}`;
probability.textContent = item.winProbability === null || item.winProbability === undefined
? "Win —"
: `Win ${probabilityValue.toFixed(1)}%`;
const action = document.createElement("a");
action.className = "dashboard-admin-priority-action";
action.href = adminPriorityActionHref(item);
action.textContent = item.action || "Review";
if (/^https:\/\/www\.chess\.com\//i.test(action.href)) {
action.target = "_blank";
action.rel = "noopener noreferrer";
}
const actions = document.createElement("div"); actions.className="dashboard-admin-priority-item-actions"; actions.append(probability,action);
row.append(dot, copy, actions);
queueHost.appendChild(row);
}
}
const healthHost = byId("dashboardAdminPriorityHealth");
healthHost.replaceChildren();
if (state.adminPriorityHealthLoading && !health.length) {
const empty = document.createElement("p");
empty.className = "dashboard-admin-priority-empty";
empty.textContent = "Loading scheduled-task health…";
healthHost.appendChild(empty);
} else if (!health.length) {
const empty = document.createElement("p");
empty.className = "dashboard-admin-priority-empty";
empty.textContent = "Scheduled-task health is not available.";
healthHost.appendChild(empty);
} else {
health.forEach(item => healthHost.appendChild(adminPriorityHealthRow(item)));
}
}
async function loadAdminPriorityHealth({ force = false } = {}) {
if (!state.admin || state.adminPriorityHealthLoading) return;
if (!force && state.adminPriorityHealthLoadedAt && Date.now() - state.adminPriorityHealthLoadedAt < 60 * 1000) {
renderAdminPriorityCard();
return;
}
state.adminPriorityHealthLoading = true;
renderAdminPriorityCard();
try {
if (!window.P2K_TEAM_POINTS_CLIENT?.endpointRequest) throw new Error("Unified task control is unavailable.");
const payload = await window.P2K_TEAM_POINTS_CLIENT.endpointRequest("server/control/public/api.php", {
action: "status",
username: state.session?.username || window.P2K_AUTH?.getSession?.()?.username || ""
});
let greenRuntime = null;
try {
greenRuntime = await window.P2K_TEAM_POINTS_CLIENT.endpointRequest("server/team-points-green/public/api.php", { action: "runtime-health" });
} catch (_) { greenRuntime = null; }
const effectiveTeamPointsSource = String(greenRuntime?.effective_public_source || "blue").toLowerCase();
const allTasks = Array.isArray(payload?.tasks) ? payload.tasks : [];
const tasks = effectiveTeamPointsSource === "green"
? allTasks.filter(task => !["team-points-club", "team-points-player", "team-points"].includes(String(task?.task_key || "")))
: allTasks;
const tones = { healthy: "good", warning: "warn", paused: "warn", critical: "bad", failed: "bad" };
const marks = { healthy: "●", warning: "●", paused: "●", critical: "●", failed: "●" };
state.adminPriorityHealth = tasks.map(task => {
const key = String(task.task_key || "");
const last = task.last_success_at ? formatRelative(`${String(task.last_success_at).replace(" ", "T")}Z`) : "never";
const expected = Number(task.expected_interval_seconds || 0);
const expectedText = expected === 300 ? "expected every 5 min" : expected === 3600 ? "expected hourly" : expected === 86400 ? "expected daily" : "scheduled maintenance";
const teamPointsMode = key === "team-points"
? ` · ${task.work?.summary?.algorithm || "incremental mode"} · seed ${task.work?.summary?.seed_status || "not imported"}`
: "";
return {
name: task.label || key,
detail: `${task.health_message || "Status available"} · last success ${last} · ${expectedText}${teamPointsMode}`,
tone: tones[task.health] || "warn",
mark: marks[task.health] || "!",
url: integratedAdminHref("tasks", { task: key })
};
});
if (effectiveTeamPointsSource === "green") {
const gst = greenRuntime?.state || {}, lastInvocation = greenRuntime?.last_invocation || {};
const workerAt = lastInvocation.completed_at || lastInvocation.started_at || gst.last_worker_finish || gst.last_worker_start || null;
const workerLast = workerAt ? formatRelative(`${String(workerAt).replace(" ", "T")}Z`) : "never";
const analyticsAt = gst.compat_analytics_rebuilt_at || null;
const analyticsLast = analyticsAt ? formatRelative(`${String(analyticsAt).replace(" ", "T")}Z`) : "pending";
const runtimeHealthy = greenRuntime?.healthy === true;
state.adminPriorityHealth.unshift({
name: "Green Team Points",
detail: `Cycle #${Number(gst.cycle_no || 0)} · ${String(gst.mode || "—")} / ${String(gst.stage || "—")} · last worker ${workerLast} · public analytics ${analyticsLast}`,
tone: runtimeHealthy ? "good" : "warn",
mark: "●",
url: integratedAdminHref("tasks")
});
}
const gateway = payload?.gateway || {};
if (["degraded", "unavailable"].includes(String(gateway.health_status || ""))) {
state.adminPriorityHealth.unshift({
name: "Chess.com gateway",
detail: gateway.health_message || "Shared API gateway needs attention.",
tone: gateway.health_status === "unavailable" ? "bad" : "warn",
mark: "●",
url: integratedAdminHref("tasks")
});
}
try {
const storagePayload = await loadJSON("server/team-points/public/storage-metrics.php", { credentials: "same-origin" });
const storage = storagePayload?.storage || {}, core = storage?.databases?.core || {}, analytics = storage?.databases?.analytics || {}, fs = storage?.filesystem || {};
const ratios = [core.ratio, analytics.ratio, fs.ratio].filter(value => Number.isFinite(Number(value))).map(Number);
const worst = ratios.length ? Math.max(...ratios) : null;
const tone = worst === null ? "warn" : worst >= .8 ? "bad" : worst >= .7 ? "warn" : "good";
const pct = value => Number.isFinite(Number(value)) ? `${Number(value).toFixed(1)}%` : "unknown";
state.adminPriorityHealth.unshift({ name: "Storage capacity", detail: `Core ${pct(core.percent)} · Analytics ${pct(analytics.percent)} · filesystem ${pct(fs.percent)}`, tone, mark: "●", url: integratedAdminHref("storage") });
} catch (storageError) {
state.adminPriorityHealth.unshift({ name: "Storage capacity", detail: `Storage metrics unavailable: ${storageError?.message || storageError}`, tone: "warn", mark: "●", url: integratedAdminHref("storage") });
}
try {
const live = await loadJSON("server/team-points/public/live-ranks.php", { credentials: "same-origin" });
const finished = live?.processing?.finished_at || null;
state.adminPriorityHealth.unshift({ name: "MCA data import", detail: finished ? `Last completed import ${formatRelative(`${String(finished).replace(" ","T")}Z`)}` : "No MCA import has completed yet", tone: finished ? "good" : "warn", mark: "●", url: integratedAdminHref("live-ranks") });
} catch (mcaError) { state.adminPriorityHealth.unshift({ name:"MCA data import", detail:"Import status unavailable", tone:"warn", mark:"●", url:integratedAdminHref("live-ranks") }); }
try { const recent = await loadJSON("server/team-points/public/recent-matches.php?hours=24", {credentials:"same-origin"}); state.adminRecentMatches = Array.isArray(recent?.matches) ? recent.matches : []; } catch (_) { state.adminRecentMatches = null; }
state.adminPriorityHealthLoadedAt = Date.now();
} catch (error) {
state.adminPriorityHealth = [{ name: "Scheduled tasks", detail: error?.message || "Unable to read unified task health", tone: "warn", mark: "●", url: integratedAdminHref("tasks") }];
state.adminPriorityHealthLoadedAt = Date.now();
} finally {
state.adminPriorityHealthLoading = false;
renderAdminPriorityCard();
}
}
function renderAdminApiThroughput(){
const host=byId("adminApiThroughputChart"),avg=byId("adminApiThroughputAverage"),peak=byId("adminApiThroughputPeak"),status=byId("adminApiThroughputStatus"),rows=Array.isArray(state.adminThroughputRows)?state.adminThroughputRows:[];
if(!host)return;host.replaceChildren();const latest=rows.at(-1),peak10=rows.reduce((m,r)=>Math.max(m,+r.peak_rps||0),0);if(avg)avg.textContent=latest?`${(+latest.average_rps||0).toFixed(2)} req/s`:"—";if(peak)peak.textContent=rows.length?`${peak10.toFixed(0)} req/s`:"—";
if(!rows.length){const e=document.createElement("div");e.className="dashboard-admin-priority-empty";e.textContent=state.adminThroughputLoading?"Loading throughput telemetry…":"No OAuth Bearer requests recorded in the last 10 minutes.";host.append(e);if(status)status.textContent=state.adminThroughputLoading?"Refreshing…":"No recent authenticated gateway activity";return;}
const N="http://www.w3.org/2000/svg",W=720,H=190,m={l:42,r:14,t:14,b:27},iw=W-m.l-m.r,ih=H-m.t-m.b,max=Math.max(1,...rows.map(r=>+r.peak_rps||0)),svg=document.createElementNS(N,"svg"),node=(tag,a={})=>{const e=document.createElementNS(N,tag);Object.entries(a).forEach(([k,v])=>e.setAttribute(k,String(v)));return e;};svg.setAttribute("viewBox",`0 0 ${W} ${H}`);svg.setAttribute("preserveAspectRatio","none");
for(let i=0;i<=4;i++){const y=m.t+ih-i*ih/4,l=node("line",{x1:m.l,y1:y,x2:W-m.r,y2:y,class:"p2k-admin-throughput-grid"}),t=node("text",{x:m.l-7,y:y+3,"text-anchor":"end",class:"p2k-admin-throughput-axis"});t.textContent=(max*i/4).toFixed(max<10?1:0);svg.append(l,t)}
const x=i=>m.l+(rows.length===1?iw/2:iw*i/(rows.length-1)),y=v=>m.t+ih-(Math.max(0,+v||0)/max)*ih,path=k=>rows.map((r,i)=>`${i?"L":"M"}${x(i).toFixed(2)},${y(r[k]).toFixed(2)}`).join(" ");svg.append(node("path",{d:path("average_rps"),class:"p2k-admin-throughput-average"}),node("path",{d:path("peak_rps"),class:"p2k-admin-throughput-peak"}));
rows.forEach((r,i)=>{if(i&&i!==rows.length-1&&i%Math.max(1,Math.ceil(rows.length/5)))return;const t=node("text",{x:x(i),y:H-8,"text-anchor":i===0?"start":i===rows.length-1?"end":"middle",class:"p2k-admin-throughput-axis"}),d=new Date(r.minute);t.textContent=Number.isNaN(d.getTime())?"":d.toLocaleTimeString("en-GB",{timeZone:"UTC",hour:"2-digit",minute:"2-digit",hour12:false});svg.append(t)});host.append(svg);if(status)status.textContent=`${rows.reduce((n,r)=>n+(+r.calls||0),0).toLocaleString()} gateway completions · refreshed ${new Date(state.adminThroughputLoadedAt||Date.now()).toLocaleTimeString("en-GB",{timeZone:"UTC",hour12:false})}`;
}
function stopAdminApiThroughput(){if(state.adminThroughputTimer){clearTimeout(state.adminThroughputTimer);state.adminThroughputTimer=0}}
function scheduleAdminApiThroughput(){stopAdminApiThroughput();state.adminThroughputTimer=setTimeout(()=>loadAdminApiThroughput({force:true}),5000)}
async function loadAdminApiThroughput({force=false}={}){
if(!state.admin||state.adminThroughputLoading)return;if(!force&&state.adminThroughputLoadedAt&&Date.now()-state.adminThroughputLoadedAt<4500){scheduleAdminApiThroughput();return}state.adminThroughputLoading=true;renderAdminApiThroughput();try{const p=await loadJSON("server/team-points/public/oauth.php?action=throughput&minutes=10",{credentials:"same-origin"});state.adminThroughputRows=Array.isArray(p?.rows)?p.rows:[];state.adminThroughputLoadedAt=Date.now()}catch(e){console.warn("Unable to load OAuth API throughput telemetry.",e);state.adminThroughputRows=[]}finally{state.adminThroughputLoading=false;renderAdminApiThroughput();if(state.admin&&state.publicPage==="administration"&&["tasks","logs","diagnostics","storage","intelligence","reconciliation"].includes(state.adminSubtab))scheduleAdminApiThroughput()}}
function configuredAdminUsernames() {
const values = config.auth?.adminUsernames;
return new Set((Array.isArray(values) ? values : []).map(adminEntryUsername).filter(Boolean));
}
function oauthSessionClaimsAdmin(session) {
if (!session || typeof session !== "object") return false;
if (session.isAdmin === true || session.admin === true || session.superAdmin === true || session.super_admin === true) return true;
const roles = [session.roles, session.permissions, session.authorization?.roles, session.authorization?.permissions]
.flatMap(value => Array.isArray(value) ? value : value ? [value] : [])
.map(value => String(value || "").trim().toLowerCase());
return roles.some(value => ["admin", "administrator", "super_admin", "super-admin", "superadmin"].includes(value));
}
function validLocalAdminMarker(username) {
try {
const marker = JSON.parse(sessionStorage.getItem(`club-tools-admin:${clubSlug}`) || "null");
return Boolean(marker && marker.username === username && Number(marker.expiresAt) > Date.now());
} catch (_) {
return false;
}
}
async function verifyAdmin(session){const run=++state.authRun;if(window.P2K_AUTH?.enabled!==true)return setAdmin(false,run);const qp=new URLSearchParams(location.search),displayOverride=/^[A-Za-z0-9_-]{1,80}$/.test(String(qp.get("name")||"").trim()),simulatedOverride=qp.get("oauth")==="1"||qp.get("simulatedOAuth")==="1";if(!displayOverride&&!simulatedOverride&&window.P2K_TEAM_POINTS_CLIENT?.connect){try{const secured=await window.P2K_TEAM_POINTS_CLIENT.connect();if(run!==state.authRun)return;if(adminEntryUsername(secured?.username))return setAdmin(true,run)}catch(error){if(run!==state.authRun)return;if(["ADMIN_AUTH_FAILED","OAUTH_SESSION_REQUIRED"].includes(String(error?.code||"")))return setAdmin(false,run)}}if(!session)return setAdmin(false,run);const username=adminEntryUsername(session.username);if(!username)return setAdmin(false,run);if(session.displayOnly!==true&&oauthSessionClaimsAdmin(session))return setAdmin(true,run);if(configuredAdminUsernames().has(username)||(session.displayOnly!==true&&validLocalAdminMarker(username)))return setAdmin(true,run);try{const profile=await loadJSON(clubProfileAPI,{cache:"no-store"});if(run!==state.authRun)return;const admins=clubAdminUsernames(profile),hasAdminFields=[profile?.admin,profile?.admins,profile?.super_admin,profile?.super_admins].some(value=>value!==undefined&&value!==null);setAdmin(hasAdminFields&&admins.has(username),run)}catch(_){if(run===state.authRun)setAdmin(false,run)}}

return Object.freeze({ adminEntryUsername, clubAdminUsernames, ensureAdminPriorityCard, removeAdminPriorityCard, integratedAdminHref, adminPriorityActionHref, adminPriorityHealthRow, adminMatchListHtml, openAdminFreshMatchDetail, openAdminMetricModal, renderAdminPriorityCard, loadAdminPriorityHealth, renderAdminApiThroughput, stopAdminApiThroughput, scheduleAdminApiThroughput, loadAdminApiThroughput, configuredAdminUsernames, oauthSessionClaimsAdmin, validLocalAdminMarker, verifyAdmin });
}});
})();
