(() => {
"use strict";
const config = window.P2K_SITE_CONFIG || {};
const clubSlug = config.clubSlug || "promote-to-king";
const clubURL = config.clubUrl || `https://www.chess.com/club/${encodeURIComponent(clubSlug)}`;
const clubProfileAPI = `https://api.chess.com/pub/club/${encodeURIComponent(clubSlug)}`;
const clubMatchesAPI = `${clubProfileAPI}/matches`;
const query = new URLSearchParams(window.location.search);
const tableStateFromURL = (prefix, defaults) => {
const params = new URLSearchParams(window.location.search);
return {
query: String(params.get(`${prefix}Q`) || defaults.query || ""),
filter: String(params.get(`${prefix}Filter`) || defaults.filter || "all"),
sort: String(params.get(`${prefix}Sort`) || defaults.sort || ""),
direction: params.get(`${prefix}Dir`) === "asc" ? "asc" : defaults.direction || "desc",
page: Math.max(1, Number(params.get(`${prefix}Page`)) || defaults.page || 1)
};
};
const navigationFromURL = () => {
const params = new URLSearchParams(window.location.search);
const requestedPage = String(params.get("page") || "").toLowerCase();
const requestedView = String(params.get("view") || "").toLowerCase() === "admin" ? "admin" : "public";
const requestedInsights = String(params.get("insights") || "").toLowerCase();
const requestedHall = String(params.get("hall") || "").toLowerCase();
let publicPage = ["dashboard", "insights", "hall", "administration"].includes(requestedPage) ? requestedPage : "dashboard";
let insightsSubtab = ["team", "members", "matches", "arenas", "opponents"].includes(requestedInsights) ? requestedInsights : "team";
let hallSubtab = ["achievements", "daily", "live", "tournaments"].includes(requestedHall) ? requestedHall : "achievements";
if (requestedPage === "team-insights") { publicPage = "insights"; insightsSubtab = "team"; }
if (requestedPage === "tournaments") { publicPage = "hall"; hallSubtab = "tournaments"; }
return {
view: requestedView,
publicPage,
adminSubtab: ["upcoming","creation","challenge","open","live-ranks","tasks","logs","diagnostics","storage","intelligence","reconciliation","members","migration"].includes(String(params.get("adminTool")||"")) ? String(params.get("adminTool")) : "upcoming",
adminContext: String(params.get("adminContext") || ""),
insightsSubtab,
hallSubtab,
category: ["competitions", "members", "team", "opponents", "maintenance", "misc"].includes(String(params.get("adminCategory") || "")) ? String(params.get("adminCategory")) : "competitions",
adminDetail: String(params.get("adminDetail") || "").toLowerCase(),
adminDetailTab: String(params.get("adminDetailTab") || "").toLowerCase(),
adminToolTab: String(params.get("adminToolTab") || "").toLowerCase(),
hallSearch: String(params.get("hallSearch") || ""),
hallRank: String(params.get("hallRank") || ""),
liveRank: String(params.get("liveRank") || ""),
teamStart: String(params.get("teamStart") || ""),
teamEnd: String(params.get("teamEnd") || ""),
assistantOpen: params.get("assistant") === "1",
assistantFilter: ["next7","priority"].includes(String(params.get("assistantFilter") || "")) ? String(params.get("assistantFilter")) : "",
personalMode: params.get("playerMode") === "live" ? "live" : "daily",
opponentsTable: tableStateFromURL("opp", { sort: "total", direction: "desc", filter: "all", page: 1 }),
matchesTable: tableStateFromURL("match", { sort: "start_time", direction: "desc", filter: "all", page: 1 }),
membersTable: tableStateFromURL("member", { sort: "points", direction: "desc", filter: "current", page: 1 }),
arenasTable: tableStateFromURL("arena", { sort: "event_date", direction: "desc", filter: "all", page: 1 })
};
};
const initialNavigation = navigationFromURL();
const state = {
session: null,
admin: false,
adminResolved: false,
view: initialNavigation.view,
publicPage: initialNavigation.publicPage,
insightsSubtab: initialNavigation.insightsSubtab,
hallSubtab: initialNavigation.hallSubtab,
adminSubtab: initialNavigation.adminSubtab || "upcoming",
adminContext: initialNavigation.adminContext || "",
category: initialNavigation.category || "competitions",
adminDetail: initialNavigation.adminDetail || "",
adminDetailTab: initialNavigation.adminDetailTab || "",
adminToolTab: initialNavigation.adminToolTab || "",
hallSearch: initialNavigation.hallSearch || "",
hallRank: initialNavigation.hallRank || "",
liveRank: initialNavigation.liveRank || "",
teamStart: initialNavigation.teamStart || "",
teamEnd: initialNavigation.teamEnd || "",
assistantOpen: Boolean(initialNavigation.assistantOpen),
personalMode: initialNavigation.personalMode || "daily",
opponentsTableState: initialNavigation.opponentsTable,
matchesTableState: initialNavigation.matchesTable,
membersTableState: initialNavigation.membersTable,
arenasTableState: initialNavigation.arenasTable,
opponentsTable: null,
matchesTable: null,
membersTable: null,
arenasTable: null,
arenasLeadersTable: null,
opponentsLoaded: false,
matchesLoaded: false,
membersLoaded: false,
arenasLoaded: false,
arenasParticipationMetric: "players",
liveRanksLoaded: false,
liveRanksPayload: null,
teamData: null,
liveTeamData: null,
authRun: 0,
recommendationRun: 0,
recommendationFrame: null,
assistantFrame: null,
recommendationTimer: 0,
assistantTimer: 0,
recommendationReady: false,
recommendationFallbackVisible: false,
assistantReady: false,
assistantFullReady: false,
assistantDedicated: false,
recommendationObserver: null,
playerPoints: null,
playerLive: null,
playerActivity: null,
hallData: null,
hallRequest: 0,
hallSearchActive: false,
hallHighlight: "",
pendingLiveFocus: "",
membersPayload: null,
recommendationReturnScroll: null,
recommendationCardHeight: 0,
adminPriorityData: null,
adminPriorityError: "",
adminPriorityFailures: 0,
adminPriorityHealth: null,
adminPriorityHealthLoading: false,
adminPriorityHealthLoadedAt: 0,
adminRecentMatches: null,
adminThroughputRows: [],
adminThroughputLoadedAt: 0,
adminThroughputLoading: false,
adminThroughputTimer: 0,
pendingAssistantFilter: initialNavigation.assistantFilter || ""
};
let toastTimer = 0;
let recommendationInfoSequence = 0;
const unrankedRank = { minimum: 0, maximum: 10, key: "unranked", name: "Unranked", image: "../p2k-logo.jpg", framed_image: "../p2k-logo.jpg" };
const ranks = [
{ minimum: 10, key: "pawn", name: "Pawn", image: "01_Pawn.png", framed_image: "01_Pawn_10_points.png" },
{ minimum: 20, key: "knight", name: "Knight", image: "02_Knight.png", framed_image: "02_Knight_20_points.png" },
{ minimum: 50, key: "bishop", name: "Bishop", image: "03_Bishop.png", framed_image: "03_Bishop_50_points.png" },
{ minimum: 100, key: "rook", name: "Rook", image: "04_Rook.png", framed_image: "04_Rook_100_points.png" },
{ minimum: 150, key: "queen", name: "Queen", image: "05_Queen.png", framed_image: "05_Queen_150_points.png" },
{ minimum: 250, key: "king", name: "King", image: "06_King.png", framed_image: "06_King_250_points.png" },
{ minimum: 500, key: "bronze-king", name: "Bronze King", image: "07_Bronze_King.png", framed_image: "07_Bronze_King_500_points.png" },
{ minimum: 1000, key: "silver-king", name: "Silver King", image: "08_Silver_King.png", framed_image: "08_Silver_King_1000_points.png" },
{ minimum: 1500, key: "gold-king", name: "Gold King", image: "09_Gold_King.png", framed_image: "09_Gold_King_1500_points.png" },
{ minimum: 2000, key: "platinum-king", name: "Platinum King", image: "10_Platinum_King.png", framed_image: "10_Platinum_King_2000_points.png" },
{ minimum: 3000, key: "amethyst-king", name: "Amethyst King", image: "11_Amethyst_King.png", framed_image: "11_Amethyst_King_3000_points.png" },
{ minimum: 4000, key: "topaz-king", name: "Topaz King", image: "12_Topaz_King.png", framed_image: "12_Topaz_King_4000_points.png" },
{ minimum: 5500, key: "emerald-king", name: "Emerald King", image: "13_Emerald_King.png", framed_image: "13_Emerald_King_5500_points.png" },
{ minimum: 7000, key: "sapphire-king", name: "Sapphire King", image: "14_Sapphire_King.png", framed_image: "14_Sapphire_King_7000_points.png" },
{ minimum: 8500, key: "ruby-king", name: "Ruby King", image: "15_Ruby_King.png", framed_image: "15_Ruby_King_8500_points.png" },
{ minimum: 10000, key: "diamond-king", name: "Diamond King", image: "16_Diamond_King.png", framed_image: "16_Diamond_King_10000_points.png" }
];
const byId = id => document.getElementById(id);
const setText = (id, value) => { const element = byId(id); if (element) element.textContent = value; };
const originalRankAsset = filename => {
const value = String(filename || "");
return value.startsWith("../") ? `assets/images/${value.slice(3)}` : `assets/images/ranks/${value}`;
};
const rankThumbnailAsset = (filename, size) => {
const value = String(filename || "");
if (!value || value.startsWith("../") || !/\.png$/i.test(value)) return originalRankAsset(value);
return `assets/images/ranks/thumbs/${size}/${value.replace(/\.png$/i, ".webp")}`;
};
function setRankImage(image, filename, size, { lazy = false } = {}) {
const fallback = originalRankAsset(filename);
image.onerror = () => {
image.onerror = null;
image.removeAttribute("srcset");
image.src = fallback;
};
image.src = rankThumbnailAsset(filename, size);
image.width = size;
image.height = size;
image.decoding = "async";
image.loading = lazy ? "lazy" : "eager";
}
const number = value => {
if (value === null || value === undefined || String(value).trim() === "") return "—";
const numeric = Number(value);
return Number.isFinite(numeric) ? new Intl.NumberFormat("en-GB").format(numeric) : "—";
};
const matchRulesLabel = value => {
const raw = String(value || "").trim();
const key = raw.toLowerCase();
if (key === "chess" || key === "standard") return "Standard";
if (key === "chess960" || key === "960") return "Chess960";
return raw || "Unknown";
};
const matchTimeControlLabel = value => {
const raw = String(value || "").trim();
if (!raw) return "Unknown";
if (/^\d+$/.test(raw)) {
const seconds = Number(raw);
if (seconds > 0 && seconds % 86400 === 0) {
const days = seconds / 86400;
return `${days} day${days === 1 ? "" : "s"} / move`;
}
}
return raw;
};
const safeDate = value => {
const numeric = Number(value);
const date = numeric ? new Date(numeric * (numeric < 1e12 ? 1000 : 1)) : new Date(value);
return Number.isFinite(date.getTime()) ? date : null;
};
const formatDateOnly = value => {
const date = safeDate(value);
return date ? new Intl.DateTimeFormat("en-GB", { timeZone: "UTC", dateStyle: "medium" }).format(date) : "Not scheduled";
};
const formatRelative = value => {
const date = safeDate(value);
if (!date) return "—";
const delta = Date.now() - date.getTime();
const abs = Math.abs(delta);
const units = abs < 3600000 ? [60000, "minute"] : abs < 86400000 ? [3600000, "hour"] : [86400000, "day"];
return new Intl.RelativeTimeFormat("en-GB", { numeric: "auto" }).format(Math.round(-delta / units[0]), units[1]);
};
const publicReadCache = new Map();
async function loadJSON(url, options = {}) {
const resolved = new URL(String(url || ""), window.location.href);
const isSameOrigin = resolved.origin === window.location.origin;
if (!isSameOrigin && window.P2K_API_CLIENT?.json) {
return window.P2K_API_CLIENT.json(resolved.href, { attempts: 2, ...options });
}
const response = await fetch(resolved.href, {
method: options.method || "GET",
credentials: options.credentials || "same-origin",
cache: options.cache || (isSameOrigin ? "default" : "no-store"),
signal: options.signal,
headers: { Accept: "application/json", ...(options.headers || {}) }
});
let payload = null;
try { payload = await response.json(); }
catch (_) { /* Preserve the HTTP error below when a PHP response is not JSON. */ }
if (!response.ok) {
throw new Error(payload?.error?.message || payload?.message || `HTTP ${response.status}`);
}
return payload;
}
async function loadPublicCachedJSON(url, { ttl = 120000, force = false, ...options } = {}) {
const resolved = new URL(String(url || ""), window.location.href);
const isTeamPointsDatabase = /\/server\/team-points\/public\//i.test(resolved.pathname);
const key = resolved.href;
const cached = publicReadCache.get(key);
const now = Date.now();
// Green DB-backed reads must not be hidden behind a second browser-memory TTL.
if (!isTeamPointsDatabase && !force && cached && cached.expiresAt > now) return cached.payload;
const payload = await loadJSON(resolved.href, { ...options, cache: (force || isTeamPointsDatabase) ? "no-store" : "default" });
if (!isTeamPointsDatabase) publicReadCache.set(key, { payload, expiresAt: now + Math.max(1000, Number(ttl) || 120000) });
return payload;
}
function applyOAuthContext(target) {
const explicit=String(query.get("oauth")||"");
if(explicit==="1") target.searchParams.set("oauth","1"); else target.searchParams.delete("oauth");
target.searchParams.delete("simulatedOAuth");
const display=String(query.get("name")||"").trim();if(/^[A-Za-z0-9_-]{1,80}$/.test(display))target.searchParams.set("name",display);else target.searchParams.delete("name");target.searchParams.delete("admin");
return target;
}
const viewed=()=>window.P2K_AUTH?.getDisplayUsername?.()||state.session?.username||"";
function preservedURL(route,{classic=false}={}) {
const target=new URL(route,location.href);
for(const [key,value] of query){if(key!=="ui"&&!target.searchParams.has(key))target.searchParams.append(key,value)}
applyOAuthContext(target);
if (!target.searchParams.has("release")) target.searchParams.set("release", String(config.version || "2.10.6.11"));
if(classic)target.searchParams.set("ui","v1");else if(/\/ui-v2\.html$/i.test(target.pathname))target.searchParams.set("ui","v2");
return target;
}
function applyBranding() {
const title = config.siteName || "Promote to King";
const subtitle = config.siteDescription || "Play together. Improve together. Promote to King.";
const logoPath = config.logoPath || "assets/images/p2k-logo.jpg";
const logoAlt = config.logoAlt || "Promote to King club logo";
const logoLink = byId("siteClubLink");
const logo = logoLink?.querySelector("img");
const heading = document.querySelector(".site-header h1");
const subtitleElement = document.querySelector(".site-subtitle");
if (heading) heading.textContent = title;
if (subtitleElement) subtitleElement.textContent = subtitle;
if (logo) { logo.src = logoPath; logo.alt = logoAlt; logo.title = title; }
if (logoLink) { logoLink.href = clubURL; logoLink.title = `Open ${title} on Chess.com`; }
document.title = title;
}
function showToast(message) {
const toast = byId("dashboardToast");
window.clearTimeout(toastTimer);
toast.textContent = message;
toast.hidden = false;
toastTimer = window.setTimeout(() => { toast.hidden = true; }, 3500);
}
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
const ADMIN_DETAIL_DEFS = {
  competitions: {
    daily: { title:"Daily Matches", tabs:[
      {key:"upcoming",label:"Upcoming matches",src:"AnalyzeMatches.htm?embedded=1&release=2.10.6.14"},
      {key:"creation",label:"Match creation",src:"MatchCreationAnalyzer.htm?embedded=1&release=2.10.6.14"},
      {key:"challenge",label:"Challenge assistant",src:"ChallengeListAssistant.html?embedded=1&release=2.10.6.14"},
      {key:"analyzer",label:"Open analyzer",src:"AnalyzeMatch.html?embedded=1&release=2.10.6.14"}
    ]},
    mca: { title:"Multi Club Arenas", tabs:[
      {key:"source",label:"MCA source data",src:"TeamPointsAdmin.html?embedded=1&tab=live-ranks&release=2.10.6.14"}
    ]},
    tournaments: { title:"Tournaments", tabs:[{key:"management",label:"Tournament management",src:"TournamentManagement.html?embedded=1&release=2.10.6.23"}]}
  },
  members: {
    depth: { title:"Team Depth", tabs:[{key:"depth",label:"Team depth",src:"ClubIntelligence.html?embedded=1&tab=depth&release=2.10.6.14"}]},
    chronology: { title:"Member chronology", tabs:[{key:"chronology",label:"Chronology",src:"TeamPointsAdmin.html?embedded=1&tab=members&release=2.10.6.14"}]},
    aliases: { title:"Aliases & name changes", tabs:[{key:"aliases",label:"Aliases & name changes",src:"ClubIntelligence.html?embedded=1&tab=aliases&release=2.10.6.14"}]},
    recruitment: { title:"Recruitment", tabs:[{key:"recruitment",label:"Recruitment",src:"RecruitmentAdmin.html?view=admin&adminCategory=members&adminDetail=recruitment&embedded=1&release=2.11.0-r6"}]}
  },
  team: {
    team: { title:"Club intelligence", tabs:[
      {key:"overview",label:"Overview",src:"ClubIntelligence.html?embedded=1&tab=overview&release=2.10.6.14"},
      {key:"forecast",label:"Forecast",src:"ClubIntelligence.html?embedded=1&tab=forecast&release=2.10.6.14"},
      {key:"snapshots",label:"Snapshots",src:"ClubIntelligence.html?embedded=1&tab=snapshots&release=2.10.6.14"},
      {key:"anomalies",label:"Anomalies",src:"ClubIntelligence.html?embedded=1&tab=anomalies&release=2.10.6.14"}
    ]}
  },
  opponents: {
    opponents: { title:"Opponent intelligence", tabs:[
      {key:"intelligence",label:"Opponent intelligence",src:"ClubIntelligence.html?embedded=1&tab=opponents&release=2.10.6.14"},
      {key:"challenge",label:"Challenge assistant",src:"ChallengeListAssistant.html?embedded=1&release=2.10.6.14"}
    ]}
  },
  maintenance: {
    diagnostics: { title:"Diagnostics", tabs:[
      {key:"health",label:"Runtime diagnostics",src:"InsightsHealth.html?embedded=1&release=2.10.9"},
      {key:"reconciliation",label:"Data reconciliation",src:"DataReconciliation.html?embedded=1&release=2.10.6.14"}
    ]},
    tasks: { title:"Scheduled Task Control", tabs:[
      {key:"control",label:"Task Control",src:"TaskControl.html?embedded=1&tab=scheduled&release=2.10.6.16"},
      {key:"migration",label:"Production migration",src:"TeamPointsMigration.html?embedded=1&release=2.10.6.14"}
    ]},
    logs: { title:"Task logs", tabs:[{key:"logs",label:"Task logs",src:"TaskLogs.html?embedded=1&release=2.10.6.14"}]},
    storage: { title:"Storage & Capacity", tabs:[{key:"storage",label:"Storage & capacity",src:"TeamPointsAdmin.html?embedded=1&tab=storage&release=2.10.6.14"}]},
    performance: { title:"Performance", tabs:[{key:"performance",label:"Performance",src:"ClubIntelligence.html?embedded=1&tab=performance&release=2.10.6.14"}]},
    freshness: { title:"Freshness", tabs:[{key:"freshness",label:"Freshness",src:"ClubIntelligence.html?embedded=1&tab=freshness&release=2.10.6.14"}]},
    traffic: { title:"Traffic & visitors", tabs:[{key:"traffic",label:"Traffic & visitors",src:"ClubIntelligence.html?embedded=1&tab=traffic&release=2.10.6.14"}]}
  }
};
function adminDetailDefinition(category=state.category, detail=state.adminDetail) { return ADMIN_DETAIL_DEFS?.[category]?.[detail] || null; }
function adminShellHref(category, detail="", detailTab="", toolTab="") {
  const url=new URL("ui-v2.html",location.href);
  ["page","adminTool","adminContext","hall","insights","assistant","assistantFilter"].forEach(key=>url.searchParams.delete(key));
  url.searchParams.set("ui","v2");url.searchParams.set("view","admin");url.searchParams.set("adminCategory",category||"competitions");
  if(detail)url.searchParams.set("adminDetail",detail);else url.searchParams.delete("adminDetail");
  if(detailTab)url.searchParams.set("adminDetailTab",detailTab);else url.searchParams.delete("adminDetailTab");
  if(detail&&toolTab)url.searchParams.set("adminToolTab",toolTab);else url.searchParams.delete("adminToolTab");
  url.hash="";return url.href;
}
function adminShellCard({ key, category, detail=key, detailTab="", eyebrow, title, description, metrics = [], source = "Existing P2K data", links = [] }) {
const metricMarkup = metrics.map(metric => `<div class="dashboard-admin-shell-metric"><span>${escapeHTML(metric.label)}</span><strong id="${escapeHTML(metric.id)}">${escapeHTML(metric.value || "—")}</strong><small id="${escapeHTML(metric.noteId || `${metric.id}Note`)}">${escapeHTML(metric.note || "Loading…")}</small></div>`).join("");
const linkMarkup = links.map(link => `<a class="dashboard-button${link.secondary ? " is-secondary" : ""}" data-admin-shell-route="1" href="${escapeHTML(adminShellHref(category,detail,link.detailTab||link.tab||detailTab,link.toolTab||""))}">${escapeHTML(link.label)}</a>`).join("");
return `<article class="dashboard-admin-shell-card" data-admin-shell-card="${escapeHTML(key)}" data-admin-detail="${escapeHTML(detail)}" data-admin-detail-tab="${escapeHTML(detailTab)}" data-admin-detail-category="${escapeHTML(category)}" role="link" tabindex="0" aria-label="Open ${escapeHTML(title)}">
  <header class="dashboard-admin-shell-card-head"><div><span class="dashboard-admin-shell-eyebrow">${escapeHTML(eyebrow)}</span><h3>${escapeHTML(title)}</h3></div><span class="dashboard-admin-shell-status is-loading" id="adminShellStatus_${escapeHTML(key)}">Loading</span></header>
  <p>${escapeHTML(description)}</p>
  <div class="dashboard-admin-shell-metrics">${metricMarkup}</div>
  <div class="dashboard-admin-shell-meta"><span><b>Freshness</b><em id="adminShellFresh_${escapeHTML(key)}">Checking…</em></span><span><b>Source</b><em id="adminShellSource_${escapeHTML(key)}">${escapeHTML(source)}</em></span></div>
  <footer class="dashboard-admin-shell-actions">${linkMarkup}</footer>
</article>`;
}
function adminMemberLookupCard(){
return `<article class="dashboard-admin-shell-card dashboard-admin-member-lookup-card" data-admin-member-lookup-card>
  <header class="dashboard-admin-shell-card-head"><div><span class="dashboard-admin-shell-eyebrow">Members</span><h3>Member lookup</h3></div><span class="dashboard-admin-shell-status is-good">Ready</span></header>
  <p>Look up a current or former member by any known username. The result combines Green identity/lifecycle data with stored Daily, rating, achievement and MCA facts.</p>
  <form class="dashboard-admin-member-lookup-form" id="adminMemberLookupForm">
    <label for="adminMemberLookupInput">Chess.com username</label>
    <div><input id="adminMemberLookupInput" type="search" autocomplete="off" spellcheck="false" maxlength="80" placeholder="Enter current or former username"><button class="dashboard-button" type="submit">Look up</button></div>
  </form>
  <div class="dashboard-admin-member-lookup-status" id="adminMemberLookupStatus">Enter a username to display all known member information.</div>
  <div class="dashboard-admin-member-lookup-result" id="adminMemberLookupResult" hidden></div>
</article>`;
}
function adminMemberLookupUtc(value,epoch=false){if(value===null||value===undefined||value==='')return'—';const d=epoch?new Date(Number(value)*1000):new Date(`${String(value).replace(' ','T')}${/[zZ]|[+-]\d\d:?\d\d$/.test(String(value))?'':'Z'}`);if(!Number.isFinite(d.getTime()))return String(value);return new Intl.DateTimeFormat('en-GB',{timeZone:'UTC',dateStyle:'medium',timeStyle:'short'}).format(d)+' UTC'}
function adminMemberLookupEventLabel(type){return({discovered:'Discovered',joined:'Joined',left:'Left',name_changed:'Name changed',rejoined:'Rejoined'})[type]||String(type||'Event')}
function renderAdminMemberLookup(payload){
 const host=byId('adminMemberLookupResult'),status=byId('adminMemberLookupStatus');if(!host||!status)return;const d=payload?.lookup||payload||{};
 if(!d.found){host.hidden=true;host.replaceChildren();status.className='dashboard-admin-member-lookup-status is-warn';status.textContent=`No Green member identity is known for “${d.query||byId('adminMemberLookupInput')?.value||''}”.`;return}
 const m=d.member||{},a=d.activity||{},lc=d.lifecycle||{},counts=lc.counts||{},aliases=Array.isArray(d.aliases)?d.aliases:[],events=Array.isArray(lc.events)?lc.events:[],live=d.live||null;
 const totalGames=Number(a.games||0),wins=Number(a.wins||0),winRate=totalGames?100*wins/totalGames:0,lastActivity=a.last_activity_epoch?adminMemberLookupUtc(a.last_activity_epoch,true):'—';
 const membership=m.current_member?'Current member':'Former member';const account=String(m.account_status||'').trim();const statusText=account?`${membership} · account ${account}`:membership;
 const aliasHtml=aliases.length?aliases.map(x=>`<span class="dashboard-admin-member-alias${x.current?' is-current':''}">${escapeHTML(x.username||x.username_key||'')}</span>`).join(''):'<span class="dashboard-admin-member-alias is-current">'+escapeHTML(m.username||'')+'</span>';
 const lifecycleHtml=events.length?events.slice(0,16).map(e=>{const transition=e.event_type==='name_changed'?` · ${escapeHTML(e.previous_username||e.username||'—')} → ${escapeHTML(e.new_username||'—')}`:'';const profile=e.event_type==='left'&&e.profile_status?` · ${escapeHTML(e.profile_status)}`:'';return `<li><time>${escapeHTML(adminMemberLookupUtc(e.detected_at))}</time><strong>${escapeHTML(adminMemberLookupEventLabel(e.event_type))}</strong><span>${escapeHTML(e.username||e.new_username||m.username||'')}${transition}${profile}</span></li>`}).join(''):'<li><span>No lifecycle event has been recorded.</span></li>';
 const avatar=m.avatar_url?`<img src="${escapeHTML(m.avatar_url)}" alt="" loading="lazy">`:'';
 const liveSummary=live?`${Number(live.total_points||0).toLocaleString('en-GB',{maximumFractionDigits:1})} pts · ${number(live.arena_count||0)} arenas · best rank ${live.best_rank?`#${number(live.best_rank)}`:'—'}`:'No stored MCA summary';
 host.innerHTML=`<div class="dashboard-admin-member-profile-head">${avatar}<div><h4>${escapeHTML(m.username||d.query||'Member')}</h4><p>${escapeHTML(statusText)}</p><div class="dashboard-admin-member-aliases">${aliasHtml}</div></div><a class="dashboard-button is-secondary" href="https://www.chess.com/member/${encodeURIComponent(m.username||d.query||'')}" target="_blank" rel="noopener noreferrer">Chess.com profile</a></div>
 <div class="dashboard-admin-member-metrics">
   <div><span>Daily rating</span><strong>${m.daily_rating??'—'}</strong></div><div><span>Chess960</span><strong>${m.chess960_rating??'—'}</strong></div><div><span>Team Points</span><strong>${Number(a.points||0).toLocaleString('en-GB',{maximumFractionDigits:1})}</strong></div><div><span>Matches / games</span><strong>${number(a.matches||0)} / ${number(a.games||0)}</strong></div><div><span>W / D / L</span><strong>${number(a.wins||0)} / ${number(a.draws||0)} / ${number(a.losses||0)}</strong><small>${totalGames?winRate.toFixed(1)+'% wins':'—'}</small></div><div><span>Current load</span><strong>${number(a.registered_matches||0)} + ${number(a.in_progress_matches||0)}</strong><small>registered + ongoing</small></div><div><span>Achievements</span><strong>${number(d.achievements||0)}</strong></div><div><span>MCA</span><strong>${live?Number(live.total_points||0).toLocaleString('en-GB',{maximumFractionDigits:1}):'—'}</strong><small>${escapeHTML(liveSummary)}</small></div>
 </div>
 <div class="dashboard-admin-member-detail-grid"><section><h5>Membership lifecycle</h5><dl><dt>Current join</dt><dd>${escapeHTML(adminMemberLookupUtc(m.joined_epoch,true))}</dd><dt>First observed</dt><dd>${escapeHTML(adminMemberLookupUtc(lc.first_event?.detected_at||m.created_at))}</dd><dt>Rejoins</dt><dd>${number(counts.rejoined||0)}</dd><dt>Last departure</dt><dd>${escapeHTML(adminMemberLookupUtc(lc.last_left?.detected_at||m.left_at))}</dd><dt>Closure evidence</dt><dd>${lc.last_closure?escapeHTML(adminMemberLookupUtc(lc.last_closure.detected_at)):(account.toLowerCase()==='closed'?'Account reported closed':'—')}</dd></dl></section>
 <section><h5>Activity & freshness</h5><dl><dt>Last Daily activity</dt><dd>${escapeHTML(lastActivity)}</dd><dt>Last roster observation</dt><dd>${escapeHTML(adminMemberLookupUtc(m.last_seen_roster_at))}</dd><dt>Profile checked</dt><dd>${escapeHTML(adminMemberLookupUtc(m.profile_checked_at))}</dd><dt>Stats checked</dt><dd>${escapeHTML(adminMemberLookupUtc(m.stats_checked_at))}</dd><dt>Chess player ID</dt><dd>${m.chess_player_id??'—'}</dd></dl></section></div>
 <section class="dashboard-admin-member-history"><h5>Lifecycle chronology</h5><ol>${lifecycleHtml}</ol></section>`;
 host.hidden=false;status.className='dashboard-admin-member-lookup-status is-good';status.textContent=`Resolved “${d.query||m.username}” to ${m.username}${aliases.length>1?` · ${aliases.length} known names`:''}.`;
}
async function loadAdminMemberLookup(){const input=byId('adminMemberLookupInput'),status=byId('adminMemberLookupStatus'),host=byId('adminMemberLookupResult');const username=String(input?.value||'').trim().replace(/^@/,'');if(!username){if(status)status.textContent='Enter a username first.';input?.focus();return}if(status){status.className='dashboard-admin-member-lookup-status';status.textContent='Looking up Green member history…'}if(host)host.hidden=true;try{const url=new URL('server/team-points-green/public/api.php',location.href);url.searchParams.set('action','member-lookup');url.searchParams.set('username',username);renderAdminMemberLookup(await adminShellJSON(url.href))}catch(error){if(status){status.className='dashboard-admin-member-lookup-status is-bad';status.textContent=error.message||String(error)}}}

function adminPanelMarkup() {
const cards = {
competitions: [
  adminShellCard({key:"daily",category:"competitions",eyebrow:"Daily matches",title:"Daily Matches",description:"Operational view of registered, ongoing and finished Daily team matches.",metrics:[{label:"Registered",id:"adminShellDailyRegistered"},{label:"Ongoing",id:"adminShellDailyOngoing"},{label:"Club points",id:"adminShellDailyPoints"}],source:"Green Core",links:[{label:"Upcoming matches",tab:"upcoming"},{label:"Match creation",tab:"creation",secondary:true}]}),
  adminShellCard({key:"mca",category:"competitions",eyebrow:"Multi Club Arenas",title:"Multi Club Arenas",description:"Stored MCA source coverage and computed Live arena totals.",metrics:[{label:"Arenas",id:"adminShellMcaArenas"},{label:"Current players",id:"adminShellMcaPlayers"},{label:"Arena points",id:"adminShellMcaPoints"}],source:"Green Analytics · MCA results CSV",links:[{label:"MCA source data",tab:"source"},{label:"Task control",tab:"tasks",secondary:true}]}),
  adminShellCard({key:"tournaments",category:"competitions",eyebrow:"Tournament archive",title:"Tournaments",description:"Tournament archive coverage and maintenance state.",metrics:[{label:"Recorded",id:"adminShellTournamentCount"},{label:"Players ranked",id:"adminShellTournamentPlayers"},{label:"Years",id:"adminShellTournamentYears"}],source:"Tournament archive index",links:[{label:"Tournament management",tab:"management"}]})
],
members: [
  adminMemberLookupCard(),
  adminShellCard({key:"depth",category:"members",eyebrow:"Members",title:"Team Depth",description:"Current rating-band depth, activity and availability coverage.",metrics:[{label:"Current members",id:"adminShellDepthMembers"},{label:"Active ≤30d",id:"adminShellDepthActive"},{label:"Rating coverage",id:"adminShellDepthRatings"}],source:"Club Intelligence · Green Core/Analytics",links:[{label:"Open team depth",tab:"depth"}]}),
  adminShellCard({key:"chronology",category:"members",eyebrow:"Members",title:"Chronology",description:"Join, leave and name-change chronology from the authoritative Green member lifecycle.",metrics:[{label:"Observed members",id:"adminShellChronMembers"},{label:"Lifecycle events",id:"adminShellChronEvents"},{label:"Pending checks",id:"adminShellChronPending"}],source:"Green member lifecycle",links:[{label:"Open chronology",tab:"chronology"}]}),
  adminShellCard({key:"aliases",category:"members",eyebrow:"Members",title:"Aliases & name changes",description:"Canonical identity mappings, possible renames and review state.",metrics:[{label:"Known names",id:"adminShellAliasMappings"},{label:"Review queue",id:"adminShellAliasReview"},{label:"Confirmed",id:"adminShellAliasConfirmed"}],source:"MIAC identity graph",links:[{label:"Open aliases",tab:"aliases"}]}),
  adminShellCard({key:"recruitment",category:"members",eyebrow:"Members",title:"Recruitment",description:"Maintain the candidate pool and evaluate prospective members against Daily activity, reliability and membership criteria.",metrics:[{label:"Candidates",id:"adminRecruitmentCandidates"},{label:"Checked",id:"adminRecruitmentChecked"},{label:"Selected",id:"adminRecruitmentSelected"}],source:"Green Core + Chess.com OAuth",links:[{label:"Open Recruitment",tab:"recruitment"}]})
],
team: [adminShellCard({key:"team",category:"team",eyebrow:"Team",title:"Club intelligence",description:"Authoritative team health, Club Points, snapshots, forecast and current anomalies.",metrics:[{label:"Club points",id:"adminShellTeamPoints"},{label:"Current members",id:"adminShellTeamMembers"},{label:"Open anomalies",id:"adminShellTeamAnomalies"}],source:"Green Core · Club Intelligence",links:[{label:"Open club intelligence",tab:"overview"},{label:"Forecast",tab:"forecast",secondary:true}]})],
opponents: [adminShellCard({key:"opponents",category:"opponents",eyebrow:"Opponents",title:"Opponent intelligence",description:"Recurring opponents, historical outcomes and opponent maintenance intelligence.",metrics:[{label:"Profiles",id:"adminShellOpponentProfiles"},{label:"Recent opponents",id:"adminShellOpponentRecent"},{label:"Review needed",id:"adminShellOpponentReview"}],source:"Club Intelligence · Green match history",links:[{label:"Open opponent intelligence",tab:"intelligence"},{label:"Challenge assistant",tab:"challenge",secondary:true}]})],
maintenance: [
  adminShellCard({key:"diagnostics",category:"maintenance",eyebrow:"Maintenance",title:"Diagnostics",description:"Runtime and API health with direct access to diagnostic details.",metrics:[{label:"Open anomalies",id:"adminShellDiagAnomalies"},{label:"Failed queue",id:"adminShellDiagFailed"},{label:"Unresolved boards",id:"adminShellDiagBoards"}],source:"Club Intelligence · runtime diagnostics",links:[{label:"Open diagnostics",tab:"health"},{label:"Data reconciliation",tab:"reconciliation",secondary:true}]}),
  adminShellCard({key:"tasks",category:"maintenance",eyebrow:"Maintenance",title:"Scheduled Task Control",description:"CRON-compatible task state, including the Green accelerator control.",metrics:[{label:"Queue pending",id:"adminShellTaskPending"},{label:"Queue failed",id:"adminShellTaskFailed"},{label:"Core generation",id:"adminShellTaskGeneration"}],source:"Task registry · Green state",links:[{label:"Scheduled tasks",detailTab:"control",toolTab:"scheduled"},{label:"Green Team Points",detailTab:"control",toolTab:"green",secondary:true}]}),
  adminShellCard({key:"logs",category:"maintenance",eyebrow:"Maintenance",title:"Logs",description:"Execution history for match, database and tournament scheduled tasks.",metrics:[{label:"Recent endpoint events",id:"adminShellLogEvents"},{label:"Telemetry days",id:"adminShellLogDays"}],source:"Task logs · runtime telemetry",links:[{label:"Open task logs",tab:"logs"}]}),
  adminShellCard({key:"storage",category:"maintenance",eyebrow:"Maintenance",title:"Storage & Capacity",description:"Core, Analytics and filesystem capacity with projection tools.",metrics:[{label:"Core → Analytics lag",id:"adminShellStorageLag"},{label:"Finished boards verified",id:"adminShellStorageBoards"}],source:"Green Core/Analytics storage telemetry",links:[{label:"Storage & capacity",tab:"storage"}]}),
  adminShellCard({key:"performance",category:"maintenance",eyebrow:"Maintenance",title:"Performance",description:"Endpoint volume, errors and latency telemetry.",metrics:[{label:"Endpoint events",id:"adminShellPerfEvents"},{label:"ACAMR claims",id:"adminShellPerfClaims"},{label:"Warnings",id:"adminShellPerfWarnings"}],source:"Protected runtime telemetry",links:[{label:"Open performance",tab:"performance"}]}),
  adminShellCard({key:"freshness",category:"maintenance",eyebrow:"Maintenance",title:"Freshness",description:"Roster, ratings, player facts, boards and worker-queue freshness.",metrics:[{label:"Fresh ratings",id:"adminShellFreshRatings"},{label:"Player matches fresh",id:"adminShellFreshMatches"},{label:"Boards pending",id:"adminShellFreshBoards"}],source:"Club Intelligence freshness",links:[{label:"Open freshness",tab:"freshness"}]}),
  adminShellCard({key:"traffic",category:"maintenance",eyebrow:"Maintenance",title:"Traffic & visitors",description:"First-party cookieless page views, sessions and visitor estimates.",metrics:[{label:"Page views",id:"adminShellTrafficViews"},{label:"Sessions",id:"adminShellTrafficSessions"},{label:"Est. uniques",id:"adminShellTrafficUniques"}],source:"First-party traffic analytics",links:[{label:"Traffic / visitors",tab:"traffic"}]})
]};
return `<section aria-label="Administrator dashboard" class="dashboard-panel dashboard-admin-shell" id="adminDashboardPanel">
  <nav aria-label="Administrator sections" class="dashboard-page-tabs dashboard-admin-category-tabs" role="tablist">
    <button aria-pressed="true" data-admin-category="competitions" type="button"><svg aria-hidden="true" class="dashboard-tab-icon" focusable="false" viewBox="0 0 24 24"><path d="M8 4h8v4a4 4 0 0 1-8 0V4Z"/><path d="M8 6H5v2a3 3 0 0 0 3 3M16 6h3v2a3 3 0 0 1-3 3M12 12v5M8.5 20h7M10 17h4"/></svg><span>Competitions</span></button>
    <button aria-pressed="false" data-admin-category="members" type="button"><svg aria-hidden="true" class="dashboard-tab-icon" focusable="false" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3.5 20v-1.5A4.5 4.5 0 0 1 8 14h2a4.5 4.5 0 0 1 4.5 4.5V20M14.5 15a4 4 0 0 1 6 3.5V20"/></svg><span>Members</span></button>
    <button aria-pressed="false" data-admin-category="team" type="button"><svg aria-hidden="true" class="dashboard-tab-icon" focusable="false" viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.6 2.8 7.7 7 10 4.2-2.3 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-5"/></svg><span>Team</span></button>
    <button aria-pressed="false" data-admin-category="opponents" type="button"><svg aria-hidden="true" class="dashboard-tab-icon" focusable="false" viewBox="0 0 24 24"><path d="M7 4 20 17M17 4l3 3-13 13-3-3L17 4Z"/><path d="m4 7 3-3 13 13-3 3"/></svg><span>Opponents</span></button>
    <button aria-pressed="false" data-admin-category="maintenance" type="button"><svg aria-hidden="true" class="dashboard-tab-icon" focusable="false" viewBox="0 0 24 24"><path d="M14.7 6.3a4 4 0 0 0-5 5L4 17l3 3 5.7-5.7a4 4 0 0 0 5-5l-2.4 2.4-3-3 2.4-2.4Z"/></svg><span>Maintenance</span></button>
    <button aria-pressed="false" data-admin-category="misc" type="button"><svg aria-hidden="true" class="dashboard-tab-icon" focusable="false" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg><span>Misc</span></button>
  </nav>
  <div class="dashboard-admin-shell-head-actions dashboard-admin-shell-menu-actions"><span class="dashboard-context-badge dashboard-context-admin">OAuth/local admin</span><button type="button" class="dashboard-button is-secondary" id="adminShellRefresh">Refresh live cards</button></div>
  <div class="dashboard-admin-shell-updated"><span id="adminShellOverallStatus">Loading live administration data…</span><small id="adminShellUpdated">Status · freshness · source are shown on every card.</small></div>
  ${Object.entries(cards).map(([key,list])=>`<section class="dashboard-admin-shell-panel" data-admin-shell-panel="${key}" ${key==="competitions"?"":"hidden"}><div class="dashboard-admin-shell-grid">${list.join("")}</div></section>`).join("")}
  <section class="dashboard-admin-shell-panel" data-admin-shell-panel="misc" hidden><div class="dashboard-admin-lost-found-head"><div><span class="dashboard-admin-shell-eyebrow">Misc</span><h3>Lost &amp; found tools</h3><p>Complete catalogue of existing administrator tools. Nothing was removed by the new shell.</p></div><strong>${tools.length} tools</strong></div><div class="dashboard-tool-grid dashboard-admin-lost-found-grid" id="adminToolGrid"></div></section>
  <section class="dashboard-admin-shell-detail" id="adminShellDetail" hidden>
    <div class="dashboard-admin-detail-heading"><div><button class="dashboard-button is-secondary" id="adminShellDetailBack" type="button">← Back</button><p class="dashboard-eyebrow" id="adminShellDetailBreadcrumb">Administration</p><h3 id="adminShellDetailTitle">Detail</h3></div></div>
    <nav class="dashboard-admin-detail-tabs" id="adminShellDetailTabs" aria-label="Administration detail sections"></nav>
    <div class="dashboard-admin-detail-frame-wrap" id="adminShellDetailFrameWrap"><iframe class="dashboard-integrated-frame dashboard-admin-detail-frame" id="adminShellDetailFrame" title="Administration detail"></iframe></div>
    <div class="dashboard-admin-detail-native" id="adminShellNativeDetailHost" hidden></div>
  </section>
</section>`;
}
function adminShellSet(id,value,note){const el=byId(id);if(el)el.textContent=value??"—";if(note!==undefined){const n=byId(`${id}Note`);if(n)n.textContent=note}}
function adminShellAge(value){if(!value)return"—";const t=Date.parse(value);if(!Number.isFinite(t))return String(value);const sec=Math.max(0,(Date.now()-t)/1000);if(sec<60)return"just now";if(sec<3600)return`${Math.round(sec/60)} min ago`;if(sec<86400)return`${(sec/3600).toFixed(1)} h ago`;return`${(sec/86400).toFixed(1)} d ago`}
function adminShellStatus(key,label,tone="good",freshness="—",source=null){const b=byId(`adminShellStatus_${key}`);if(b){b.textContent=label;b.className=`dashboard-admin-shell-status is-${tone}`;}const f=byId(`adminShellFresh_${key}`);if(f)f.textContent=freshness;if(source){const s=byId(`adminShellSource_${key}`);if(s)s.textContent=source}}
function adminShellNumber(value){const n=Number(value);return Number.isFinite(n)?number(n):"—"}
function adminShellPercent(value){const n=Number(value);return Number.isFinite(n)?`${n.toFixed(1)}%`:"—"}
function adminShellOpenDetail(category,detail,tab="",{updateHistory=true,replace=false,toolTab=""}={}){
  const def=adminDetailDefinition(category,detail);if(!def)return;
  state.category=category;state.adminDetail=detail;state.adminDetailTab=def.tabs.some(item=>item.key===tab)?tab:def.tabs[0].key;state.adminToolTab=String(toolTab||"").toLowerCase();
  adminShellActivate();if(updateHistory)writeNavigationState({replace});
}
function adminShellCloseDetail({updateHistory=true}={}){
  setIntegratedFrameActivity("");
  state.adminDetail="";state.adminDetailTab="";state.adminToolTab="";adminShellActivate();if(updateHistory)writeNavigationState();
}
function renderAdminShellDetail(){
  const detailHost=byId("adminShellDetail");if(!detailHost)return;
  const def=adminDetailDefinition();
  const frame=byId("adminShellDetailFrame");
  const frameWrap=byId("adminShellDetailFrameWrap")||frame?.parentElement;
  const nativeHost=byId("adminShellNativeDetailHost");
  if(!def){detailHost.hidden=true;setIntegratedFrameActivity("");if(frameWrap)frameWrap.hidden=false;if(frame)frame.hidden=false;if(nativeHost){nativeHost.hidden=true;nativeHost.replaceChildren();nativeHost.removeAttribute("data-native-detail");}return;}
  const validTab=def.tabs.find(item=>item.key===state.adminDetailTab)||def.tabs[0];state.adminDetailTab=validTab.key;
  detailHost.hidden=false;setText("adminShellDetailTitle",def.title);setText("adminShellDetailBreadcrumb",`Administration · ${state.category.replace("maintenance","Maintenance")}`);
  const tabs=byId("adminShellDetailTabs");if(tabs){tabs.hidden=def.tabs.length<=1;tabs.innerHTML=def.tabs.map(item=>`<a class="dashboard-admin-detail-tab${item.key===validTab.key?" is-active":""}" data-admin-detail-tab="${escapeHTML(item.key)}" href="${escapeHTML(adminShellHref(state.category,state.adminDetail,item.key,item.key===validTab.key?state.adminToolTab:""))}" aria-current="${item.key===validTab.key?"page":"false"}">${escapeHTML(item.label)}</a>`).join("");}
  const nativeKey=String(validTab.nativeKey||"");
  const nativeMode=validTab.mode==="native"||nativeKey!=="";
  if(nativeMode){
    setIntegratedFrameActivity("");
    if(frameWrap)frameWrap.hidden=true;
    if(frame){frame.hidden=true;frame.removeAttribute("data-p2k-r2-stable");}
    if(nativeHost){nativeHost.hidden=false;nativeHost.dataset.nativeDetail=nativeKey||validTab.key;}
    return;
  }
  if(nativeHost){nativeHost.hidden=true;nativeHost.replaceChildren();nativeHost.removeAttribute("data-native-detail");}
  if(frameWrap)frameWrap.hidden=false;
  if(frame){
    frame.hidden=false;
    const targetUrl=applyOAuthContext(new URL(validTab.src,location.href));
    targetUrl.searchParams.set("embedded","1");
    targetUrl.searchParams.set("active","1");
    if(state.adminToolTab&&targetUrl.searchParams.has("tab"))targetUrl.searchParams.set("tab",state.adminToolTab);
    const target=targetUrl.href;
    if(frame.src!==target){
      setIntegratedFrameActivity("");
      frame.dataset.p2kLoaded="0";frame.dataset.p2kRetried="0";frame.style.height="";
      frame.title=`${def.title} — ${validTab.label}`;frame.src=target;
    }
    ensureIntegratedFrame("adminShellDetailFrame");
  }
}
function adminShellActivate(){
  const def=adminDetailDefinition();
  const detail=Boolean(def);
  document.querySelectorAll("[data-admin-shell-panel]").forEach(panel=>panel.hidden=detail||panel.dataset.adminShellPanel!==state.category);
  document.querySelectorAll("[data-admin-category]").forEach(button=>{const active=button.dataset.adminCategory===state.category;button.setAttribute("aria-pressed",String(active));button.classList.toggle("is-active",active);button.setAttribute("aria-selected",String(active));button.tabIndex=active?0:-1;});
  if(detail)renderAdminShellDetail();else if(byId("adminShellDetail"))byId("adminShellDetail").hidden=true;
  const activeTab=def?.tabs?.find(item=>item.key===state.adminDetailTab)||def?.tabs?.[0]||null;
  try{window.dispatchEvent(new CustomEvent("p2k-admin-shell-route",{detail:{category:state.category,detail:state.adminDetail,tab:state.adminDetailTab,nativeKey:activeTab?.nativeKey||""}}));}catch(_){ }
}
async function adminShellJSON(url){const response=await fetch(url,{credentials:"same-origin",cache:"no-store"});const payload=await response.json();if(!response.ok||payload?.ok===false)throw new Error(payload?.error?.message||`HTTP ${response.status}`);return payload}
async function loadAdminShellMetrics(force=false){
const host=byId("adminDashboardHost");if(!host||!byId("adminDashboardPanel")||!state.admin)return;if(state.adminShellLoading)return;if(!force&&state.adminShellLoadedAt&&Date.now()-state.adminShellLoadedAt<30000)return;state.adminShellLoading=true;setText("adminShellOverallStatus","Refreshing live administration data…");
const requests={
team:()=>window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("team",{_fresh:Date.now()})||adminShellJSON("server/team-points/public/public.php?action=team"),
live:()=>adminShellJSON("server/team-points/public/public.php?action=live-team"),
tournaments:()=>adminShellJSON("server/tournaments/public/browse.php?view=summary"),
overview:()=>adminShellJSON("server/team-points/public/intelligence.php?scope=overview"),
freshness:()=>adminShellJSON("server/team-points/public/intelligence.php?scope=freshness"),
aliases:()=>adminShellJSON("server/team-points/public/intelligence.php?scope=aliases&limit=500"),
opponents:()=>adminShellJSON("server/team-points/public/public.php?action=opponents&page=1&page_size=25&include_summary=1"),
chronology:()=>window.P2K_TEAM_POINTS_CLIENT?.endpointRequest?.("server/team-points-green/public/api.php",{action:"member-events",method:"GET",params:{limit:500},serverTrafficClass:"foreground"})||Promise.reject(new Error("Green member chronology client unavailable")),
performance:()=>adminShellJSON("server/team-points/public/intelligence.php?scope=performance"),
traffic:()=>adminShellJSON("server/team-points/public/intelligence.php?scope=traffic&days=90")
};
const results=Object.fromEntries(await Promise.all(Object.entries(requests).map(async([key,fn])=>{try{return[key,{ok:true,payload:await fn()}]}catch(error){return[key,{ok:false,error}]}})));
const team=results.team.ok?(results.team.payload?.team||results.team.payload||{}):{};const live=results.live.ok?(results.live.payload?.team||results.live.payload||{}):{};const tour=results.tournaments.ok?results.tournaments.payload:{};const overview=results.overview.ok?(results.overview.payload?.data||{}):{};const fresh=results.freshness.ok?(results.freshness.payload?.data||{}):{};const aliases=results.aliases.ok?(results.aliases.payload?.data||{}):{};const opponents=results.opponents.ok?(results.opponents.payload||{}):{};const chronology=results.chronology.ok?(results.chronology.payload||{}):{};const perf=results.performance.ok?(results.performance.payload?.data||{}):{};const traffic=results.traffic.ok?(results.traffic.payload?.data||{}):{};
adminShellSet("adminShellDailyRegistered",adminShellNumber(team.registered_matches));adminShellSet("adminShellDailyOngoing",adminShellNumber(team.in_progress_matches??team.ongoing_matches));adminShellSet("adminShellDailyPoints",adminShellNumber(team.club_points));adminShellStatus("daily",results.team.ok?"Healthy":"Unavailable",results.team.ok?"good":"bad",adminShellAge(team.cache_updated_at),team.data_source==="green_native_core"?"Green Core · live":"Team Points database");
adminShellSet("adminShellMcaArenas",adminShellNumber(live.arenas));adminShellSet("adminShellMcaPlayers",adminShellNumber(live.current_players));adminShellSet("adminShellMcaPoints",adminShellNumber(live.aggregate_points));adminShellStatus("mca",results.live.ok?"Current":"Unavailable",results.live.ok?"good":"bad",results.live.ok?"live database read":"—","Green Analytics · MCA results CSV");
const ts=tour.summary||{};const years=Array.isArray(tour.years)?tour.years:[];adminShellSet("adminShellTournamentCount",adminShellNumber(ts.tournaments??ts.total_tournaments??ts.events));adminShellSet("adminShellTournamentPlayers",adminShellNumber(ts.players??ts.ranked_players??ts.medalists));adminShellSet("adminShellTournamentYears",adminShellNumber(years.length));adminShellStatus("tournaments",results.tournaments.ok?"Current":"Unavailable",results.tournaments.ok?"good":"bad",adminShellAge(tour.generated_at),tour.recovery_source?`Tournament index · ${tour.recovery_source}`:"Tournament archive index");
const coverage=overview.freshness?.coverage||fresh.coverage||{},activity=overview.activity_summary||{};adminShellSet("adminShellDepthMembers",adminShellNumber(coverage.current_members??team.current_members));adminShellSet("adminShellDepthActive",adminShellNumber(activity.active));adminShellSet("adminShellDepthRatings",adminShellPercent(coverage.rating_percent));adminShellStatus("depth",results.overview.ok?"Current":"Unavailable",results.overview.ok?"good":"bad",results.overview.ok?"live admin read":"—");
const lifecycleEvents=Array.isArray(chronology.events)?chronology.events:[];const pendingLifecycle=lifecycleEvents.filter(e=>e.event_type==="left"&&e.profile_status==="pending").length;adminShellSet("adminShellChronMembers",adminShellNumber(coverage.current_members??team.current_members));adminShellSet("adminShellChronEvents",adminShellNumber(lifecycleEvents.length));adminShellSet("adminShellChronPending",adminShellNumber(pendingLifecycle));adminShellStatus("chronology",results.chronology.ok?"Current":"Unavailable",results.chronology.ok?"good":"bad",results.chronology.ok?"live Green read":"—","Green member lifecycle");
const edges=Array.isArray(aliases.edges)?aliases.edges:[];const edgeCounts=aliases.edge_counts||{};const confirmed=Number(edgeCounts.confirmed||0);const review=Number(edgeCounts.candidate||0)+Number(edgeCounts.conflict||0);adminShellSet("adminShellAliasMappings",adminShellNumber(aliases.names));adminShellSet("adminShellAliasReview",adminShellNumber(review));adminShellSet("adminShellAliasConfirmed",adminShellNumber(confirmed));adminShellStatus("aliases",results.aliases.ok?"Current":"Unavailable",results.aliases.ok?"good":"bad",results.aliases.ok?"live admin read":"—");
adminShellSet("adminShellTeamPoints",adminShellNumber(team.club_points));adminShellSet("adminShellTeamMembers",adminShellNumber(team.current_members));adminShellSet("adminShellTeamAnomalies",adminShellNumber((overview.anomalies||[]).length));adminShellStatus("team",results.team.ok&&results.overview.ok?"Healthy":results.team.ok?"Partial":"Unavailable",results.team.ok&&results.overview.ok?"good":results.team.ok?"warn":"bad",adminShellAge(team.cache_updated_at));
const oppRows=Array.isArray(opponents.rows)?opponents.rows:[],oppSummary=opponents.summary||{};adminShellSet("adminShellOpponentProfiles",adminShellNumber(oppSummary.different_opponents??opponents.pagination?.total_rows));adminShellSet("adminShellOpponentRecent",adminShellNumber(oppSummary.currently_playing));adminShellSet("adminShellOpponentReview",adminShellNumber(oppRows.filter(x=>x.disabled||Number(x.result_missing||0)>0).length));adminShellStatus("opponents",results.opponents.ok?"Current":"Unavailable",results.opponents.ok?"good":"bad",results.opponents.ok?"live database read":"—","Green Analytics opponent projection");
const fc=fresh.coverage||{},fa=fresh.ages||{};adminShellSet("adminShellDiagAnomalies",adminShellNumber((overview.anomalies||[]).length));adminShellSet("adminShellDiagFailed",adminShellNumber(fc.queue_failed));adminShellSet("adminShellDiagBoards",adminShellNumber(fc.finished_boards_unresolved));adminShellStatus("diagnostics",results.freshness.ok?((Number(fc.queue_failed||0)+Number(fc.finished_boards_unresolved||0))?"Attention":"Healthy"):"Unavailable",results.freshness.ok?((Number(fc.queue_failed||0)+Number(fc.finished_boards_unresolved||0))?"warn":"good"):"bad",results.freshness.ok?"live admin read":"—");
adminShellSet("adminShellTaskPending",adminShellNumber(fc.queue_pending));adminShellSet("adminShellTaskFailed",adminShellNumber(fc.queue_failed));adminShellSet("adminShellTaskGeneration",adminShellNumber(fresh.core?.core_generation));adminShellStatus("tasks",results.freshness.ok?(Number(fc.queue_failed||0)?"Attention":"Ready"):"Unavailable",results.freshness.ok?(Number(fc.queue_failed||0)?"warn":"good"):"bad",adminShellAge(fresh.core?.members_last_observed_at));
adminShellSet("adminShellLogEvents",adminShellNumber(perf.events));adminShellSet("adminShellLogDays",adminShellNumber(perf.days));adminShellStatus("logs",results.performance.ok?"Current":"Unavailable",results.performance.ok?"good":"bad",results.performance.ok?"rolling telemetry":"—");
adminShellSet("adminShellStorageLag",(()=>{const n=Number(fa.core_analytics_lag_seconds);if(!Number.isFinite(n))return"—";if(n<60)return`${Math.round(n)} s`;if(n<3600)return`${Math.round(n/60)} min`;return`${(n/3600).toFixed(1)} h`})());adminShellSet("adminShellStorageBoards",adminShellPercent(fc.finished_board_complete_percent??fc.board_complete_percent));adminShellStatus("storage",results.freshness.ok?"Current":"Unavailable",results.freshness.ok?"good":"bad",results.freshness.ok?"live capacity inputs":"—");
adminShellSet("adminShellPerfEvents",adminShellNumber(perf.events));adminShellSet("adminShellPerfClaims",adminShellNumber(perf.acamr?.claims));adminShellSet("adminShellPerfWarnings",adminShellNumber((perf.acamr?.warnings||[]).length));adminShellStatus("performance",results.performance.ok?"Current":"Unavailable",results.performance.ok?"good":"bad",results.performance.ok?`${adminShellNumber(perf.days)} d rolling window`:"—");
adminShellSet("adminShellFreshRatings",adminShellPercent(fc.fresh_rating_percent));adminShellSet("adminShellFreshMatches",adminShellPercent(fc.player_matches_operational_fresh_percent??fc.player_matches_fresh_percent));adminShellSet("adminShellFreshBoards",adminShellNumber(fc.boards_pending));adminShellStatus("freshness",results.freshness.ok?((Number(fc.queue_failed||0)||Number(fc.boards_failed||0))?"Attention":"Current"):"Unavailable",results.freshness.ok?((Number(fc.queue_failed||0)||Number(fc.boards_failed||0))?"warn":"good"):"bad",adminShellAge(fresh.core?.members_last_observed_at));
const tr=traffic.summary||{};adminShellSet("adminShellTrafficViews",adminShellNumber(tr.pageviews));adminShellSet("adminShellTrafficSessions",adminShellNumber(tr.sessions));adminShellSet("adminShellTrafficUniques",adminShellNumber(tr.latest_daily_unique_visitors));adminShellStatus("traffic",results.traffic.ok?"Current":"Unavailable",results.traffic.ok?"good":"bad",adminShellAge(results.traffic.payload?.server_utc),"First-party cookieless analytics");
const okCount=Object.values(results).filter(x=>x.ok).length;state.adminShellLoadedAt=Date.now();setText("adminShellOverallStatus",okCount===Object.keys(results).length?"All live administration sources responded.":`${okCount} of ${Object.keys(results).length} live sources responded.`);setText("adminShellUpdated",`Updated ${new Date().toLocaleTimeString("en-GB", { timeZone: "UTC", hour12: false })} UTC · Status · freshness · source are shown on every card.`);state.adminShellLoading=false;
}
function ensureAdminInterface() {
const toggleHost = byId("dashboardAdminToggleHost");
if (toggleHost && !toggleHost.firstElementChild) {
const bar = document.createElement("div");bar.className = "dashboard-view-bar";
const group = document.createElement("div");group.className = "dashboard-view-switch";group.setAttribute("role", "group");group.setAttribute("aria-label", "Choose the dashboard view");
for (const [view, label] of [["public", "Public"], ["admin", "Admin"]]) {
const button = document.createElement("button");button.type = "button";button.className = "dashboard-view-button";button.dataset.dashboardView = view;button.textContent = label;
button.addEventListener("click", () => {if (view === "admin" && !state.admin) return;state.view = view;renderView();writeNavigationState();});group.appendChild(button);}
bar.appendChild(group);toggleHost.appendChild(bar);
}
const host = byId("adminDashboardHost");
if (host && !host.firstElementChild) {
host.innerHTML = adminPanelMarkup();
host.querySelectorAll("[data-admin-category]").forEach(button => {button.setAttribute("aria-pressed", String(button.dataset.adminCategory === state.category));button.addEventListener("click", () => {state.category = button.dataset.adminCategory;state.adminDetail="";state.adminDetailTab="";state.adminToolTab="";adminShellActivate();if (state.category === "misc") renderTools();writeNavigationState();});});
host.addEventListener("click",event=>{
  const route=event.target.closest?.("[data-admin-shell-route]");
  if(route){event.preventDefault();const u=new URL(route.href,location.href);adminShellOpenDetail(u.searchParams.get("adminCategory")||state.category,u.searchParams.get("adminDetail")||"",u.searchParams.get("adminDetailTab")||"",{toolTab:u.searchParams.get("adminToolTab")||""});return;}
  const card=event.target.closest?.("[data-admin-detail]");if(card&&!event.target.closest("a,button,input,select,textarea")){adminShellOpenDetail(card.dataset.adminDetailCategory||state.category,card.dataset.adminDetail||"",card.dataset.adminDetailTab||"");}
  const tab=event.target.closest?.("[data-admin-detail-tab]");if(tab){event.preventDefault();adminShellOpenDetail(state.category,state.adminDetail,tab.dataset.adminDetailTab||"",{toolTab:""});}
});
host.addEventListener("keydown",event=>{const card=event.target.closest?.("[data-admin-detail]");if(card&&(event.key==="Enter"||event.key===" ")){event.preventDefault();adminShellOpenDetail(card.dataset.adminDetailCategory||state.category,card.dataset.adminDetail||"",card.dataset.adminDetailTab||"");}});
byId("adminShellDetailBack")?.addEventListener("click",()=>adminShellCloseDetail());
byId("adminShellRefresh")?.addEventListener("click",()=>loadAdminShellMetrics(true));
byId("adminMemberLookupForm")?.addEventListener("submit",event=>{event.preventDefault();loadAdminMemberLookup();});
adminShellActivate();renderTools();loadAdminShellMetrics();
}
}
function removeAdminInterface() {
state.adminShellLoadedAt=0;state.adminShellLoading=false;
byId("dashboardAdminToggleHost")?.replaceChildren();
const host = byId("adminDashboardHost");
host?.replaceChildren();
if (host) host.hidden = true;
}
function setAdmin(enabled, run = state.authRun) {
if (run !== state.authRun) return;
state.admin = Boolean(enabled);
state.adminResolved = true;
window.P2K_ADMIN_MODE = state.admin;
window.dispatchEvent(new CustomEvent("p2k-admin-ready", { detail: { allowed: state.admin } }));
if (state.admin) {
ensureAdminInterface();
ensureAdminPriorityCard();
loadAdminPriorityHealth();
} else {
state.view = "public";
if (state.publicPage === "administration") state.publicPage = "dashboard";
removeAdminInterface();
removeAdminPriorityCard();
}
renderView();
if (!state.admin && state.adminResolved) writeNavigationState({ replace: true });
}
function writeTableState(url, prefix, tableState, active) {
const values = tableState || {};
const mapping = {
[`${prefix}Q`]: values.query || "",
[`${prefix}Filter`]: values.filter && values.filter !== "all" ? values.filter : "",
[`${prefix}Sort`]: values.sort || "",
[`${prefix}Dir`]: values.direction && values.direction !== "desc" ? values.direction : "",
[`${prefix}Page`]: Number(values.page) > 1 ? String(values.page) : ""
};
for (const [key, value] of Object.entries(mapping)) {
if (active && value) url.searchParams.set(key, value);
else url.searchParams.delete(key);
}
}
function writeNavigationState({ replace = false } = {}) {
const url = new URL(window.location.href);
if (state.view === "admin") url.searchParams.set("view", "admin");
else url.searchParams.delete("view");
url.searchParams.set("page", state.publicPage || "dashboard");
if(state.view === "public" && state.publicPage === "administration") {
url.searchParams.set("adminTool",state.adminSubtab);
if (state.adminContext) url.searchParams.set("adminContext", state.adminContext); else url.searchParams.delete("adminContext");
if (state.adminSubtab === "tasks" && state.adminToolTab) url.searchParams.set("adminToolTab", state.adminToolTab); else url.searchParams.delete("adminToolTab");
} else { url.searchParams.delete("adminTool"); url.searchParams.delete("adminContext"); if(state.view!=="admin")url.searchParams.delete("adminToolTab"); }
if (state.publicPage === "hall") url.searchParams.set("hall", state.hallSubtab);
else url.searchParams.delete("hall");
if (state.publicPage === "insights") url.searchParams.set("insights", state.insightsSubtab);
else url.searchParams.delete("insights");
if (state.view === "admin") {
url.searchParams.set("adminCategory", state.category || "competitions");
if (state.adminDetail) url.searchParams.set("adminDetail", state.adminDetail); else url.searchParams.delete("adminDetail");
if (state.adminDetail && state.adminDetailTab) url.searchParams.set("adminDetailTab", state.adminDetailTab); else url.searchParams.delete("adminDetailTab");
if (state.adminDetail && state.adminToolTab) url.searchParams.set("adminToolTab", state.adminToolTab); else url.searchParams.delete("adminToolTab");
} else { url.searchParams.delete("adminCategory"); url.searchParams.delete("adminDetail"); url.searchParams.delete("adminDetailTab"); if(!(state.publicPage === "administration" && state.adminSubtab === "tasks"))url.searchParams.delete("adminToolTab"); }
if (state.publicPage === "hall" && state.hallSearch) url.searchParams.set("hallSearch", state.hallSearch);
else url.searchParams.delete("hallSearch");
if (state.publicPage === "hall" && state.hallSubtab === "daily" && state.hallRank) url.searchParams.set("hallRank", state.hallRank);
else url.searchParams.delete("hallRank");
if (state.publicPage === "hall" && state.hallSubtab === "live" && state.liveRank) url.searchParams.set("liveRank", state.liveRank);
else url.searchParams.delete("liveRank");
if (state.publicPage === "insights" && state.insightsSubtab === "team" && state.teamStart) url.searchParams.set("teamStart", state.teamStart); else url.searchParams.delete("teamStart");
if (state.publicPage === "insights" && state.insightsSubtab === "team" && state.teamEnd) url.searchParams.set("teamEnd", state.teamEnd); else url.searchParams.delete("teamEnd");
if (state.publicPage === "dashboard" && state.assistantOpen) {
url.searchParams.set("assistant", "1");
if (["next7","priority"].includes(state.pendingAssistantFilter)) url.searchParams.set("assistantFilter", state.pendingAssistantFilter); else url.searchParams.delete("assistantFilter");
// Assistant opening is state-driven. Drop legacy/page anchors so hash scrolling cannot
// race the dynamic iframe reveal and leave the assistant outside the visible panel.
url.hash = "";
} else { url.searchParams.delete("assistant"); url.searchParams.delete("assistantFilter"); }
if (state.publicPage === "dashboard" && state.personalMode === "live") url.searchParams.set("playerMode", "live"); else url.searchParams.delete("playerMode");
writeTableState(url, "opp", state.opponentsTableState, state.publicPage === "insights" && state.insightsSubtab === "opponents");
writeTableState(url, "match", state.matchesTableState, state.publicPage === "insights" && state.insightsSubtab === "matches");
writeTableState(url, "member", state.membersTableState, state.publicPage === "insights" && state.insightsSubtab === "members");
writeTableState(url, "arena", state.arenasTableState, state.publicPage === "insights" && state.insightsSubtab === "arenas");
const method = replace ? "replaceState" : "pushState";
window.history[method]({ page: state.publicPage, hall: state.hallSubtab, insights: state.insightsSubtab }, "", url);
}
function renderSubtabs() {
document.querySelectorAll("[data-insights-subtab]").forEach(button => {
const active = button.dataset.insightsSubtab === state.insightsSubtab;
button.classList.toggle("is-active", active);
button.setAttribute("aria-selected", String(active));
button.tabIndex = active ? 0 : -1;
});
document.querySelectorAll("[data-insights-panel]").forEach(panel => { panel.hidden = panel.dataset.insightsPanel !== state.insightsSubtab; });
document.querySelectorAll("[data-hall-subtab]").forEach(button => {
const active = button.dataset.hallSubtab === state.hallSubtab;
button.classList.toggle("is-active", active);
button.setAttribute("aria-selected", String(active));
button.tabIndex = active ? 0 : -1;
});
document.querySelectorAll("[data-hall-panel]").forEach(panel => { panel.hidden = panel.dataset.hallPanel !== state.hallSubtab; });
}
function renderView() {
const showAdmin = state.admin && state.view === "admin";
const showDashboard = !showAdmin && (state.publicPage === "dashboard" || (!state.adminResolved && state.publicPage === "administration"));
const showHall = !showAdmin && state.publicPage === "hall";
const showInsights = !showAdmin && state.publicPage === "insights";
const showAdministration = !showAdmin && state.admin && state.publicPage === "administration";
byId("dashboardPublicTabs").hidden = showAdmin;
byId("publicDashboardPanel").hidden = !showDashboard;
byId("hallOfFamePage").hidden = !showHall;
byId("teamInsightsPage").hidden = !showInsights;
if (byId("administrationPage")) byId("administrationPage").hidden = !showAdministration;
if (byId("dashboardAdministrationTab")) byId("dashboardAdministrationTab").hidden = !state.admin;
if (showAdministration && window.P2K_ADMIN_MODE === true) { const map={upcoming:"adminUpcomingFrame",creation:"adminCreationFrame",challenge:"adminChallengeFrame",open:"adminOpenFrame","live-ranks":"adminLiveRanksFrame",tasks:"adminTasksFrame",logs:"adminLogsFrame",diagnostics:"adminDiagnosticsFrame",storage:"adminStorageFrame",intelligence:"adminIntelligenceFrame",reconciliation:"adminReconciliationFrame",members:"adminMembersFrame",migration:"adminMigrationFrame"}; ensureIntegratedFrame(map[state.adminSubtab]||map.upcoming); }
renderSubtabs();
const adminGroupForTool = tool => tool === "live-ranks" ? "live" : (["tasks","logs","diagnostics","storage","intelligence","reconciliation","members","migration"].includes(tool) ? "tools" : "daily");
const activeAdminGroup = adminGroupForTool(state.adminSubtab);
if (showAdministration && activeAdminGroup === "tools") loadAdminApiThroughput(); else stopAdminApiThroughput();
document.querySelectorAll("[data-admin-group]").forEach(b => {
const active = b.dataset.adminGroup === activeAdminGroup;
b.classList.toggle("is-active", active);
b.setAttribute("aria-selected", String(active));
});
document.querySelectorAll("[data-admin-group-panel]").forEach(p => { p.hidden = p.dataset.adminGroupPanel !== activeAdminGroup; });
document.querySelectorAll("[data-admin-subtab]").forEach(b=>b.classList.toggle("is-active",b.dataset.adminSubtab===state.adminSubtab));
document.querySelectorAll("[data-admin-panel]").forEach(p=>p.hidden=p.dataset.adminPanel!==state.adminSubtab);
if (showInsights && state.insightsSubtab === "team") ensureIntegratedFrame("teamInsightsFrame");
if (showInsights && state.insightsSubtab === "members") loadMemberInsights();
if (showInsights && state.insightsSubtab === "matches") loadMatchInsights();
if (showInsights && state.insightsSubtab === "arenas") loadArenaInsights();
if (showInsights && state.insightsSubtab === "opponents") loadOpponentInsights();
if (showHall && state.hallSubtab === "live") loadLiveRanksNative();
if (showHall && state.hallSubtab === "tournaments") ensureIntegratedFrame("tournamentsFrame");
if (showHall && state.hallSubtab === "achievements") ensureIntegratedFrame("achievementsFrame");
if (!showAdministration && !showInsights && !showHall) setIntegratedFrameActivity("");
const adminHost = byId("adminDashboardHost");
if (adminHost) adminHost.hidden = !showAdmin;
if (showAdmin) { adminShellActivate(); loadAdminShellMetrics(); }
document.querySelectorAll("[data-dashboard-view]").forEach(button => button.setAttribute("aria-pressed", String(button.dataset.dashboardView === (showAdmin ? "admin" : "public"))));
document.querySelectorAll("[data-public-page]").forEach(button => {
const active = !showAdmin && button.dataset.publicPage === state.publicPage;
button.classList.toggle("is-active", active);
if (active) button.setAttribute("aria-current", "page"); else button.removeAttribute("aria-current");
});
}
function showPublicPage(page, { member = "", reset = false, hallSubtab = "", insightsSubtab = "", updateHistory = true, replaceHistory = false } = {}) {
state.view = "public";
if (page === "team-insights") page = "insights";
if (page === "tournaments") { page = "hall"; hallSubtab = "tournaments"; }
state.publicPage = ["dashboard", "hall", "insights", "administration"].includes(page) && (page !== "administration" || state.admin || !state.adminResolved) ? page : "dashboard";
if (state.publicPage === "insights" && ["team", "members", "matches", "arenas", "opponents"].includes(insightsSubtab)) state.insightsSubtab = insightsSubtab;
if (state.publicPage === "hall" && ["daily", "live", "tournaments", "achievements"].includes(hallSubtab)) state.hallSubtab = hallSubtab;
renderView();
if (updateHistory) writeNavigationState({ replace: replaceHistory });
if (state.publicPage === "hall") {
if (state.hallSearch) {
byId("hallMemberSearch").value = state.hallSearch;
searchHallUnified(state.hallSearch, { updateHistory: false });
}
if (state.hallSubtab === "daily") {
if (member) {
state.hallSearch = member;
byId("hallMemberSearch").value = member;
searchHallUnified(member, { updateHistory: true });
loadHall({ member });
} else if (state.hallRank) {
loadHall({ rank: state.hallRank });
} else if (reset || !state.hallData || state.hallData.selected_rank) {
state.hallHighlight = "";
loadHall();
} else {
renderHallSummary(state.hallData);
renderHallRanks(state.hallData);
}
}
}
}
function selectInsightsSubtab(key, { updateHistory = true } = {}) {
if (!["team", "members", "matches", "arenas", "opponents"].includes(key)) return;
state.insightsSubtab = key;
renderView();
if (key === "members") loadMemberInsights();
if (key === "matches") loadMatchInsights();
if (key === "arenas") loadArenaInsights();
if (key === "opponents") loadOpponentInsights();
if (updateHistory) writeNavigationState();
}
function selectHallSubtab(key, { updateHistory = true } = {}) {
if (!["daily", "live", "tournaments", "achievements"].includes(key)) return;
state.hallSubtab = key;
renderView();
if (updateHistory) writeNavigationState();
if (key === "daily") {
if (state.hallRank) loadHall({ rank: state.hallRank });
else if (!state.hallData) loadHall();
else { renderHallSummary(state.hallData); renderHallRanks(state.hallData); }
}
if (state.hallSearch) searchHallUnified(state.hallSearch, { updateHistory: false });
}
function nativeLink(label, href, className = "p2k-table-link") {
if (!href) return document.createTextNode(label || "—");
const link = document.createElement("a");
link.className = className;
link.href = href;
link.target = "_blank";
link.rel = "noopener noreferrer";
link.textContent = label || href;
return link;
}
function cellWithSub(primary, secondary = "") {
const wrap = document.createElement("span");
wrap.append(primary instanceof Node ? primary : document.createTextNode(String(primary ?? "—")));
if (secondary) {
const small = document.createElement("small");
small.className = "p2k-table-sub";
small.textContent = secondary;
wrap.appendChild(small);
}
return wrap;
}
function statusChip(status) {
const chip = document.createElement("span");
const normalized = String(status || "unknown").toLowerCase();
chip.className = `p2k-status-chip is-${normalized.replaceAll("_", "-")}`;
chip.textContent = normalized.replaceAll("_", " ");
return chip;
}
const escapeHTML = value => String(value ?? "").replace(/[&<>"']/g, character => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[character]);
  function insightAction(label, callback, className = "p2k-table-link-button") {
    const button = document.createElement("button");
    button.type = "button";
    button.className = className;
    button.textContent = label || "Open";
    button.addEventListener("click", event => { event.stopPropagation(); callback(); });
    return button;
  }
  const insightsModalStack = [];
  function showInsightsModal({ eyebrow = "Insights", title = "Details", subtitle = "", html = "", replace = false } = {}) {
    const modal = byId("insightsDetailModal"), body = byId("insightsDetailBody"); if (!modal || !body) return;
    if (!modal.hidden && !replace) { const card=modal.querySelector(".p2k-profile-modal-card"), nodes=Array.from(body.childNodes), stash=document.createDocumentFragment(); const previous={eyebrow:byId("insightsDetailEyebrow")?.textContent||"",title:byId("insightsDetailTitle")?.textContent||"",subtitle:byId("insightsDetailSubtitle")?.textContent||"",nodes,modalScroll:modal.scrollTop,cardScroll:card?.scrollTop||0,focus:document.activeElement instanceof HTMLElement&&modal.contains(document.activeElement)?document.activeElement:null,stash}; nodes.forEach(node=>stash.appendChild(node)); insightsModalStack.push(previous); }
    setText("insightsDetailEyebrow",eyebrow);setText("insightsDetailTitle",title);setText("insightsDetailSubtitle",subtitle);body.innerHTML=html;modal.hidden=false;modal.scrollTop=0;const card=modal.querySelector(".p2k-profile-modal-card");if(card)card.scrollTop=0;document.body.classList.add("dashboard-modal-open");requestAnimationFrame(()=>byId("insightsDetailClose")?.focus({preventScroll:true}));
  }
  function closeInsightsModal() {
    const modal=byId("insightsDetailModal"),body=byId("insightsDetailBody");if(!modal||!body)return;if(insightsModalStack.length){const previous=insightsModalStack.pop();setText("insightsDetailEyebrow",previous.eyebrow);setText("insightsDetailTitle",previous.title);setText("insightsDetailSubtitle",previous.subtitle);body.replaceChildren(...previous.nodes);modal.hidden=false;requestAnimationFrame(()=>{modal.scrollTop=previous.modalScroll||0;const card=modal.querySelector(".p2k-profile-modal-card");if(card)card.scrollTop=previous.cardScroll||0;if(previous.focus?.isConnected)previous.focus.focus({preventScroll:true});});return;}modal.hidden=true;body.replaceChildren();document.body.classList.remove("dashboard-modal-open");
  }
  function normalizeTournamentName(entry) {
    if (entry && typeof entry === "object") return String(entry.username || entry.name || entry.player || "").trim();
    return String(entry || "").trim();
  }
  function tournamentAchievements(archivePayload, username) {
    const key = String(username || "").trim().toLowerCase();
    const tournaments = Array.isArray(archivePayload?.archive?.tournaments)
      ? archivePayload.archive.tournaments
      : Array.isArray(archivePayload?.tournaments) ? archivePayload.tournaments : [];
    const medals = { gold: 0, silver: 0, bronze: 0, events: [] };
    for (const tournament of tournaments) {
      const podium = tournament?.podium || tournament?.medallists || tournament?.medalists || {};
      for (const medal of ["gold", "silver", "bronze"]) {
        const rawNames = podium?.[medal] ?? podium?.[medal === "gold" ? "first" : medal === "silver" ? "second" : "third"] ?? [];
        const names = (Array.isArray(rawNames) ? rawNames : [rawNames]).map(normalizeTournamentName).filter(Boolean);
        if (!names.some(name => name.toLowerCase() === key)) continue;
        medals[medal] += 1;
        medals.events.push({
          medal,
          name: tournament?.name || tournament?.title || tournament?.slug || "Tournament",
          date: tournament?.finishAt || tournament?.end_time || "",
          url: tournament?.url || (tournament?.slug ? `https://www.chess.com/tournament/${tournament.slug}` : "")
        });
      }
    }
    medals.events.sort((a, b) => String(b.date).localeCompare(String(a.date)));
    return medals;
  }
  function profileMetric(label, value, detail = "") {
    return `<article class="p2k-profile-metric"><span>${escapeHTML(label)}</span><strong>${escapeHTML(value)}</strong>${detail ? `<small>${escapeHTML(detail)}</small>` : ""}</article>`;
  }
  function tournamentAchievementKeys(medals) {
    const total = Number(medals?.gold || 0) + Number(medals?.silver || 0) + Number(medals?.bronze || 0);
    const keys = [];
    if (total >= 1) keys.push("tournament-first-medal");
    if (Number(medals?.gold || 0) >= 1) keys.push("tournament-first-gold");
    if (total >= 5) keys.push("tournament-medals-5");
    if (total >= 10) keys.push("tournament-medals-10");
    return keys;
  }
  function achievementCard(item, earned = false, options = {}) {
    const icon = String(item?.miniature || item?.icon || "p2k-logo.jpg");
    const count = Number(item?.earnedCurrentMemberCount ?? item?.earned_current_member_count ?? 0);
    const totalMembers = Number(item?.clubCurrentMemberCount ?? item?.club_current_member_count ?? 0);
    const share = totalMembers > 0 ? (count * 100 / totalMembers) : 0;
    const earnedAt = earned && item?.earned_at ? formatDateOnly(String(item.earned_at).includes("T") ? String(item.earned_at) : `${item.earned_at.replace(" ", "T")}Z`) : "";
    const datePending = earned && item?.earned_at_precision === "tournament-pending";
    const earnedApproximate=earned&&["mca-interpolated","mca-upload-fallback"].includes(String(item?.earned_at_precision||""));
    const earnedMeta=earned?(earnedAt?`Earned ${earnedAt}${earnedApproximate?" (approximative date)":""}`:datePending?"Earned · date pending tournament refresh":"Earned"):"";
    const ownershipMeta = !options.hideOwnership && count > 0 ? `${number(count)} club member${count === 1 ? "" : "s"}${totalMembers > 0 ? ` (${share.toFixed(1)}%)` : ""}` : "";
    const meta = [earnedMeta, ownershipMeta].filter(Boolean).join(" · ");
    const progress = !earned && options.progress && Number(options.progress.current) >= 0 && Number(options.progress.target) > 0
      ? `<div class="p2k-achievement-progress"><span>${escapeHTML(options.progress.progress_metric || "Progress")} · ${number(options.progress.current)} / ${number(options.progress.target)}</span><progress max="100" value="${Math.max(0,Math.min(100,Number(options.progress.progress_percent)||0))}"></progress></div>` : "";
    return `<button type="button" class="p2k-achievement-card${earned ? " is-earned" : ""}" data-achievement-key="${escapeHTML(item?.key || "")}" data-achievement-name="${escapeHTML(item?.label || item?.key || "")}"><img src="${escapeHTML(icon)}" alt="${escapeHTML(item?.label || "Achievement")}" loading="lazy" onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='assets/images/achievements/placeholders/generic.svg'}"><div><strong>${escapeHTML(item?.label || item?.key || "Achievement")}</strong><p>${escapeHTML(item?.description || "")}</p>${meta?`<small>${escapeHTML(meta)}</small>`:""}${progress}</div></button>`;
  }
  function achievementEarnedDetail(item) {
    const candidates = [item?.earned_at, item?.earned_on, item?.date, item?.achieved_at, item?.first_earned_at].filter(Boolean);
    const rawDate = candidates.length ? String(candidates[0]) : "";
    const normalizedDate = rawDate.includes("T") ? rawDate : rawDate.includes(" ") ? `${rawDate.replace(" ", "T")}Z` : rawDate ? `${rawDate}T00:00:00Z` : "";
    const approximateDate=["mca-interpolated","mca-upload-fallback"].includes(String(item?.earned_at_precision||""));
    const earnedDate=normalizedDate?`${formatDateOnly(normalizedDate)}${approximateDate?" (approximative date)":""}`:item?.earned_at_precision==="tournament-pending"?"Date pending tournament refresh":"Stored achievement records do not contain an exact award date";
    const context = item?.context || item?.source || item?.earned_via || item?.details || "The stored player statistics reached this achievement threshold.";
    return{earnedDate,context:typeof context==="string"?context:JSON.stringify(context)};
  }
  function achievementWebURL(raw) {
    const value=String(raw||"").trim();if(!value)return "";let url;try{url=new URL(value,window.location.href)}catch{return ""}
    if(url.hostname==="www.chess.com"||url.hostname==="chess.com")return url.href;if(url.hostname!=="api.chess.com")return url.href;
    let m=url.pathname.match(/^\/pub\/match\/(\d+)/i);if(m)return `https://www.chess.com/club/matches/${m[1]}`;m=url.pathname.match(/^\/pub\/tournament\/([^/]+)/i);if(m)return `https://www.chess.com/tournament/${encodeURIComponent(decodeURIComponent(m[1]))}`;return "";
  }
  let achievementDetailNav=null;
  function openAchievementDetail(item, earned = false, options = {}) {
    if (!item) return;
    const fullImage = String(item.icon || item.miniature || "p2k-logo.jpg");
    const criterion = item.criteria || item.criterion || item.description || "Achievement criterion unavailable.";
    const currentCount = Number(item.earnedCurrentMemberCount ?? item.earned_current_member_count ?? 0);
    const currentMembers = Number(item.clubCurrentMemberCount ?? item.club_current_member_count ?? 0);
    const share = currentMembers > 0 ? (currentCount * 100 / currentMembers) : 0;
    const ownership = currentCount > 0
      ? `${number(currentCount)} club member${currentCount === 1 ? "" : "s"}. ${currentMembers > 0 ? `${share.toFixed(1)}% of current club members.` : ""}`
      : `0 club members.${currentMembers > 0 ? " 0.0% of current club members." : ""}`;
    const earnedInfo = achievementEarnedDetail(item);
    const sourceName = String(item?.source_name || "").trim();
    const sourceURL = String(item?.source_url || "").trim();
    const safeSourceURL = achievementWebURL(sourceURL);
    const earnedRows = earned ? `<div><dt>Achievement date</dt><dd>${escapeHTML(earnedInfo.earnedDate)}</dd></div>${sourceName ? `<div><dt>Triggered by</dt><dd>${safeSourceURL ? `<a href="${escapeHTML(safeSourceURL)}" target="_blank" rel="noopener noreferrer">${escapeHTML(sourceName)}</a>` : escapeHTML(sourceName)}</dd></div>` : ""}` : "";
    const progress=options.progress&&!earned&&Number(options.progress.target)>0?`<div class="p2k-achievement-progress"><span>${escapeHTML(options.progress.progress_metric||"Progress")} · ${number(options.progress.current||0)} / ${number(options.progress.target||0)}</span><progress max="100" value="${Math.max(0,Math.min(100,Number(options.progress.progress_percent)||0))}"></progress></div>`:"";
    const nav=options.navIndex!=null&&achievementDetailNav?`<div class="p2k-achievement-detail-nav"><button type="button" data-achievement-prev ${options.navIndex<=0?"disabled":""}>← Previous</button><button type="button" data-achievement-next ${options.navIndex>=achievementDetailNav.items.length-1?"disabled":""}>Next →</button></div>`:"";
    const html = `${nav}<section class="p2k-achievement-detail"><img src="${escapeHTML(fullImage)}" alt="${escapeHTML(item.label || "Achievement")}" onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='assets/images/achievements/placeholders/generic.svg'}"><div><span class="p2k-achievement-detail-state ${earned ? "is-earned" : ""}">${earned ? "Earned" : "Not earned yet"}</span><h3>${escapeHTML(item.label || item.key || "Achievement")}</h3>${progress}<dl><div><dt>Criterion</dt><dd>${escapeHTML(criterion)}</dd></div>${earnedRows}<div><dt>Achieved by</dt><dd>${escapeHTML(ownership)}</dd></div></dl></div></section>`;
    showInsightsModal({replace:Boolean(options.replace),eyebrow:"Achievement details",title:item.label||item.key||"Achievement",subtitle:earned?"Player achievement record":"Achievement criterion",html});
    const body=byId("insightsDetailBody");body?.querySelector("[data-achievement-prev]")?.addEventListener("click",()=>openAchievementNav(options.navIndex-1,true));body?.querySelector("[data-achievement-next]")?.addEventListener("click",()=>openAchievementNav(options.navIndex+1,true));
  }
  function openAchievementNav(index,replace=false){const nav=achievementDetailNav;if(!nav||index<0||index>=nav.items.length)return;nav.index=index;const x=nav.items[index];openAchievementDetail(x.item,x.earned,{progress:x.progress,navIndex:index,replace})}
  function achievementDisplayBar(done, total, label = "Achievement progress") {
    const complete=Math.max(0,Math.min(Number(total)||0,Number(done)||0)),count=Math.max(0,Number(total)||0),pct=count?Math.max(0,Math.min(100,100*complete/count)):0;
    if(count>10){
      return `<span class="p2k-achievement-display-bar is-progressive"><span class="p2k-achievement-display-track" role="progressbar" aria-label="${escapeHTML(label)}" aria-valuemin="0" aria-valuemax="${count}" aria-valuenow="${complete}"><i style="width:${pct.toFixed(2)}%"></i></span></span>`;
    }
    return `<span class="p2k-achievement-display-bar is-segmented" role="progressbar" aria-label="${escapeHTML(label)}" aria-valuemin="0" aria-valuemax="${count}" aria-valuenow="${complete}"><span class="p2k-achievement-segments">${Array.from({length:count},(_,i)=>`<i class="${i<complete?'is-complete':''}"></i>`).join("")}</span></span>`;
  }
  function achievementCategoryLabel(item, category) {
    return String(item?.category_label || category || "Other").trim() || "Other";
  }
  function achievementFamilyLabel(item, family) {
    return String(item?.family_label || family || "Other").trim() || "Other";
  }
  async function openAchievementCatalog(username = "", focusKey = "") {
    const name=String(username||"").trim();showInsightsModal({eyebrow:"Achievements",title:name?`${name} · all achievements`:"All achievements",subtitle:"Loading the complete achievement catalogue…",html:'<p class="p2k-table-status">Loading achievement catalogue…</p>'});
    try {
      const requests=[loadPublicCachedJSON("server/team-points/public/achievements.php",{ttl:300000,credentials:"same-origin"})];
      if(name){const profileURL=new URL("server/team-points/public/player-profile.php",window.location.href);profileURL.searchParams.set("username",name);profileURL.searchParams.set("mode","modal");requests.push(loadPublicCachedJSON(profileURL.href,{ttl:120000,credentials:"same-origin"}));const progressURL=new URL("server/team-points/public/member-intelligence.php",window.location.href);progressURL.searchParams.set("username",name);requests.push(loadPublicCachedJSON(progressURL.href,{ttl:120000,credentials:"same-origin"}))}
      const results=await Promise.all(requests),catalogPayload=results[0],profilePayload=results[1],progressPayload=results[2],catalog=Array.isArray(catalogPayload?.catalog)?catalogPayload.catalog:[],earnedItems=Array.isArray(profilePayload?.player?.achievements)?profilePayload.player.achievements:[],progressRows=Array.isArray(progressPayload?.player?.member?.achievement_progress)?progressPayload.player.member.achievement_progress:[],progressByKey=new Map(progressRows.map(item=>[String(item.key||""),item])),metricEarned=new Set(progressRows.filter(item=>Number(item?.target)>0&&Number(item?.current)>=Number(item?.target)).map(item=>String(item.key||""))),earnedByKey=new Map(earnedItems.map(item=>[String(item.key||""),item])),earned=new Set([...earnedItems.map(item=>String(item.key||"")),...metricEarned]);metricEarned.forEach(k=>{if(!earnedByKey.has(k))earnedByKey.set(k,{key:k,level:"earned",earned_at:null,earned_at_precision:"metric-reconciled"})});const families=new Map();
      catalog.forEach(item=>{const family=String(item.family||"other"),category=String(item.category||"other");if(!families.has(family))families.set(family,{label:achievementFamilyLabel(item,family),groups:new Map(),items:[]});const f=families.get(family);f.items.push(item);if(!f.groups.has(category))f.groups.set(category,{label:achievementCategoryLabel(item,category),items:[]});f.groups.get(category).items.push(item)});
      const familyContainingFocus=focusKey?String(catalog.find(item=>String(item.key||"")===String(focusKey))?.family||""):"";
      const showCatalogueProgress=Boolean(name);
      const familiesHTML=[...families.entries()].map(([familyKey,family])=>{
        const familyAchieved=family.items.filter(item=>earned.has(item.key)).length,total=family.items.length;
        const groupsHTML=[...family.groups.entries()].map(([category,group])=>{const achievedCount=group.items.filter(item=>earned.has(item.key)).length,groupTotal=group.items.length;return `<section class="p2k-achievement-group-static" data-achievement-group="${escapeHTML(category)}"><header><div><h3>${escapeHTML(group.label)}</h3><span>${number(achievedCount)} / ${number(groupTotal)} achieved</span></div>${showCatalogueProgress?achievementDisplayBar(achievedCount,groupTotal,`${group.label} progress`):""}</header><div class="p2k-achievement-catalog">${group.items.map(item=>{const key=String(item.key||"");return achievementCard(earnedByKey.has(key)?{...item,...earnedByKey.get(key)}:item,earned.has(item.key),{hideOwnership:Boolean(name),progress:progressByKey.get(key)||null})}).join("")}</div></section>`}).join("");
        return `<details class="p2k-achievement-family" data-achievement-family="${escapeHTML(familyKey)}"${familyKey===familyContainingFocus?' open':''}><summary><span class="p2k-achievement-family-copy"><strong>${escapeHTML(family.label)}</strong><small>${number(familyAchieved)} / ${number(total)} achieved</small></span>${showCatalogueProgress?achievementDisplayBar(familyAchieved,total,`${family.label} progress`):""}<span class="p2k-achievement-family-chevron" aria-hidden="true"></span></summary><div class="p2k-achievement-family-body">${groupsHTML}</div></details>`;
      }).join("");
      const html=catalog.length?`<div class="p2k-achievement-catalog-search"><label><span>Search achievement names</span><input type="search" data-achievement-search autocomplete="off" placeholder="Type an achievement name…"></label><small data-achievement-search-count>${number(catalog.length)} achievements</small></div>${familiesHTML}`:'<p class="p2k-table-status">No achievement definitions are available.</p>';
      showInsightsModal({replace:true,eyebrow:"Achievement catalogue",title:name?`${name} · ${number(earned.size)} earned`:`${number(catalog.length)} achievements`,subtitle:"",html});
      const body=byId("insightsDetailBody"),catalogByKey=new Map(catalog.map(item=>{const key=String(item.key||"");return [key,earnedByKey.has(key)?{...item,...earnedByKey.get(key)}:item]}));achievementDetailNav={items:catalog.map(item=>{const key=String(item.key||"");return{item:catalogByKey.get(key),earned:earned.has(key),progress:progressByKey.get(key)||null}}),index:0};body?.querySelectorAll("[data-achievement-key]").forEach(button=>button.addEventListener("click",()=>{const key=String(button.dataset.achievementKey||""),i=achievementDetailNav.items.findIndex(x=>String(x.item.key||"")===key);openAchievementNav(i)}));const search=body?.querySelector("[data-achievement-search]"),searchCount=body?.querySelector("[data-achievement-search-count]");if(search){const applySearch=()=>{const q=String(search.value||"").trim().toLocaleLowerCase("en"),allCards=[...body.querySelectorAll("[data-achievement-key]")];let visible=0;allCards.forEach(card=>{const match=!q||String(card.dataset.achievementName||"").toLocaleLowerCase("en").includes(q);card.hidden=!match;if(match)visible++});body.querySelectorAll(".p2k-achievement-group-static").forEach(group=>{const any=[...group.querySelectorAll("[data-achievement-key]")].some(card=>!card.hidden);group.hidden=!any});body.querySelectorAll(".p2k-achievement-family").forEach(family=>{const any=[...family.querySelectorAll("[data-achievement-key]")].some(card=>!card.hidden);family.hidden=!any;if(q&&any)family.open=true});if(searchCount)searchCount.textContent=`${number(visible)} of ${number(allCards.length)} achievements`};search.addEventListener("input",applySearch)}if(focusKey){const target=body?.querySelector(`[data-achievement-key="${CSS.escape(String(focusKey))}"]`);if(target){target.closest(".p2k-achievement-family")?.setAttribute("open","");target.classList.add("is-focused-achievement");target.scrollIntoView({block:"center",behavior:"smooth"});target.focus({preventScroll:true});}}
    }catch(error){showInsightsModal({replace:true,eyebrow:"Achievements",title:name||"Achievement catalogue",subtitle:"Unable to load",html:`<p class="p2k-table-status is-error">${escapeHTML(error.message||error)}</p>`})}
  }
  async function openUnifiedPlayerProfile(username) {
    const name=String(username||"").trim();if(!name)return;showInsightsModal({eyebrow:"Player profile",title:name,subtitle:"Loading database profile…",html:'<p class="p2k-table-status">Loading unified player profile…</p>'});
    try {
      const profileURL=new URL("server/team-points/public/player-profile.php",window.location.href);profileURL.searchParams.set("username",name);profileURL.searchParams.set("mode","modal");const profilePayload=await loadPublicCachedJSON(profileURL.href,{ttl:120000,credentials:"same-origin"});if(profilePayload?.ok===false)throw new Error(profilePayload?.error?.message||"Player profile is unavailable.");
      const player=profilePayload.player||{},achievementTimestamp=item=>{const raw=item?.earned_at||item?.first_recorded_at||"";const ts=raw?Date.parse(String(raw).includes("T")?String(raw):String(raw).replace(" ","T")+"Z"):0;return Number.isFinite(ts)?ts:0},achievements=[...(Array.isArray(player.achievements)?player.achievements:[])].sort((a,b)=>achievementTimestamp(b)-achievementTimestamp(a)||String(a?.key||"").localeCompare(String(b?.key||""))),achievementTotal=Number(profilePayload?.meta?.achievement_total||profilePayload?.achievement_total||115),achievementCount=achievements.length,achievementPct=achievementTotal?100*achievementCount/achievementTotal:0,achievementPosition=Number(player.achievement_position||profilePayload?.meta?.achievement_position||0),monthly=Array.isArray(player.monthly)?player.monthly:[];let cumulativePoints=0;const progression=monthly.map(row=>{cumulativePoints+=Number(row.points)||0;return {...row,cumulative_points:cumulativePoints}});
      const monthlyHTML=monthly.length?`<div class="p2k-chart-heading"><div><p>Monthly Team Points are bars; cumulative Team Points are the line. Drag across the graph to zoom and double-click to reset.</p></div><button type="button" class="p2k-chart-reset" data-profile-progression-reset>Reset zoom</button></div><div id="playerTeamPointsProgression" class="p2k-native-chart p2k-profile-progression-chart"></div><div class="p2k-chart-legend"><span data-chart-color="blue">Monthly points</span><span data-chart-color="gold">Cumulative points</span></div>`:'<p class="p2k-table-status">No monthly activity is stored.</p>',achievementsHTML=achievements.length?achievements.slice(0,10).map(item=>achievementCard(item,true,{hideOwnership:true})).join(""):'<span class="p2k-table-status">No achievement threshold reached yet.</span>',avatar=String(player.avatar||""),dailyRanked=Boolean(player?.rank&&String(player?.rank?.key||player?.rank?.name||"").toLowerCase()!=="unranked"),dailyImage=dailyRanked?originalRankAsset(player?.rank?.framed_image||player?.rank?.image||""):"",liveRank=player?.live?.rank||null,liveRanked=Boolean(liveRank&&String(player?.live?.rank_name||liveRank?.name||liveRank?.key||"").toLowerCase()!=="unranked"),liveImage=liveRanked&&liveRank?.framed_image?`assets/images/live-ranks/${liveRank.framed_image}`:"";
      const profileToken=`profile-${Date.now()}-${Math.random().toString(36).slice(2)}`;const html=`<div class="p2k-profile-root" data-profile-root="${profileToken}"><section class="p2k-profile-overview"><div class="p2k-profile-identity"><div class="p2k-profile-avatar">${avatar?`<img src="${escapeHTML(avatar)}" alt="${escapeHTML(player.username||name)} avatar">`:"♔"}</div><div><h3>${escapeHTML(player.username||name)}</h3><p>${player.current_member?"Current Promote to King member":"Former or unconfirmed member"}</p>${player.profile_url?`<a href="${escapeHTML(player.profile_url)}" target="_blank" rel="noopener noreferrer">Open Chess.com profile</a>`:""}</div></div><div class="p2k-profile-rank-pair"><article class="${dailyImage?"":"is-unranked"}">${dailyImage?`<img src="${escapeHTML(dailyImage)}" alt="${escapeHTML(player?.rank?.name||"Daily rank")}">`:""}<span>Daily rank</span><strong>${escapeHTML(player?.rank?.name||"Unranked")}</strong></article><article class="${liveImage?"":"is-unranked"}">${liveImage?`<img src="${escapeHTML(liveImage)}" alt="${escapeHTML(player?.live?.rank_name||"Live rank")}">`:""}<span>Live rank</span><strong>${escapeHTML(player?.live?.rank_name||"Unranked")}</strong></article></div><div class="p2k-profile-metrics">${profileMetric("Team Points",number(player.points))}${profileMetric("Team matches",number(player.matches))}${profileMetric("Team games",number(player.games))}${profileMetric("W / D / L",`${number(player.wins)} / ${number(player.draws)} / ${number(player.losses)}`)}${profileMetric("Daily team position",player.team_position?`#${number(player.team_position)}`:"—")}${profileMetric("MCA points",player.live?number(player.live.points):"—",player.live?`${number(player.live.arenas)} arenas played`:"No MCA record")}${profileMetric("Live rank",player?.live?.rank_name||"Unranked",player?.live?.top3?`${number(player.live.top3)} podium finish${Number(player.live.top3)===1?"":"es"}`:"No MCA podium recorded")}${profileMetric("Achievements",`${number(achievementCount)} / ${number(achievementTotal)}`,`${achievementPct.toFixed(1)}% complete${achievementPosition?` · team position #${number(achievementPosition)}`:""}`)}<article class="p2k-profile-metric" data-tournament-medals><span>Tournament medals</span><strong>…</strong><small>Loading tournament history</small></article></div></section><section class="p2k-profile-section" data-profile-challenges><h3>Achievement challenges</h3><p class="p2k-table-status">Loading achievement challenges…</p></section><section class="p2k-profile-section"><div class="p2k-profile-section-title" data-profile-achievement-actions><h3>Achievements</h3><button type="button" class="dashboard-button" data-profile-achievements="${escapeHTML(player.username||name)}">View all achievements of ${escapeHTML(player.username||name)}</button></div><div class="p2k-achievement-catalog is-compact">${achievementsHTML}</div></section><section class="p2k-profile-section"><h3>Team Points progression</h3>${monthlyHTML}</section><section class="p2k-profile-section"><h3>Tournament achievements</h3><div class="p2k-profile-list" data-tournament-history><p class="p2k-table-status">Loading tournament history…</p></div></section></div>`;
      showInsightsModal({replace:true,eyebrow:"Unified player profile",title:player.username||name,subtitle:`${profilePayload?.meta?.source||"database"} · updated ${profilePayload?.meta?.last_database_update?formatRelative(profilePayload.meta.last_database_update):"from stored records"}`,html});
      const body=byId("insightsDetailBody"),profileRoot=body?.querySelector(`[data-profile-root="${profileToken}"]`),achievementByKey=new Map(achievements.map(item=>[String(item.key||""),item]));window.P2K_ACHIEVEMENT_DETAIL_CACHE=achievementByKey;profileRoot?.querySelectorAll("[data-achievement-key]").forEach(button=>button.addEventListener("click",()=>openAchievementDetail(achievementByKey.get(String(button.dataset.achievementKey||"")),true)));profileRoot?.querySelector("[data-profile-achievements]")?.addEventListener("click",()=>openAchievementCatalog(player.username||name));
      if(progression.length){renderNativeBarLine("playerTeamPointsProgression",progression,{xKey:"month",barKey:"points",lineKey:"cumulative_points",barLabel:"Monthly Team Points",lineLabel:"Cumulative Team Points"});profileRoot?.querySelector("[data-profile-progression-reset]")?.addEventListener("click",()=>profileRoot?.querySelector("#playerTeamPointsProgression")?._p2kResetZoom?.())}
      {const intelURL=new URL("server/team-points/public/member-intelligence.php",window.location.href);intelURL.searchParams.set("username",player.username||name);loadPublicCachedJSON(intelURL.href,{ttl:120000,credentials:"same-origin"}).then(ip=>{const host=profileRoot?.querySelector("[data-profile-challenges]"),m=ip?.player?.member;if(!host)return;const challenges=challengeProgress(m);const rows=challenges.length?`<div class="p2k-profile-challenges">${challenges.map(c=>{const criterion=String(c.criteria||c.description||"Achievement criterion unavailable.");return `<button type="button" class="p2k-challenge-row" data-challenge-achievement="${escapeHTML(c.key||'')}" title="${escapeHTML(criterion)}" aria-label="${escapeHTML(`${c.label||"Achievement"} — ${criterion}`)}"><span>${escapeHTML(c.label)}</span><progress max="100" value="${Number(c.progress_percent||0)}" aria-label="${escapeHTML(`${c.label||"Achievement"} progress`)}"></progress></button>`}).join("")}</div>`:`<p class="p2k-table-status">No supported achievement challenge is currently in progress.</p>`;host.innerHTML=`<h3>Achievement challenges</h3>${rows}`;host.querySelectorAll('[data-challenge-achievement]').forEach(button=>button.addEventListener('click',()=>openAchievementCatalog(player.username||name,String(button.dataset.challengeAchievement||''))));}).catch(()=>{const host=profileRoot?.querySelector("[data-profile-challenges]");if(host)host.innerHTML='<h3>Achievement challenges</h3><p class="p2k-table-status">Challenges temporarily unavailable.</p>'})}
      loadPublicCachedJSON(`server/tournaments/public/browse.php?view=player&username=${encodeURIComponent(player.username||name)}`,{ttl:300000,credentials:"same-origin"}).then(result=>{if(!profileRoot)return;const m=result?.player||{},events=Array.isArray(m.tournaments)?m.tournaments:[],total=Number(m.gold||0)+Number(m.silver||0)+Number(m.bronze||0),metric=profileRoot.querySelector("[data-tournament-medals]"),list=profileRoot.querySelector("[data-tournament-history]");if(metric)metric.innerHTML=`<span>Tournament medals</span><strong>${number(total)}</strong><small>${number(m.gold||0)} gold · ${number(m.silver||0)} silver · ${number(m.bronze||0)} bronze</small>`;if(list)list.innerHTML=events.length?events.slice(0,12).map(event=>`<a class="p2k-profile-list-row" href="${escapeHTML(event.webUrl||"#")}" ${event.webUrl?'target="_blank" rel="noopener noreferrer"':""}><span><strong>${escapeHTML(event.name||"Tournament")}</strong><small>${escapeHTML(event.date?formatDateOnly(event.date):event.period||"Date unavailable")}</small></span><b class="is-${escapeHTML(event.medal)}">${event.medal==='gold'?'🥇':event.medal==='silver'?'🥈':'🥉'} ${escapeHTML(event.medal)}</b></a>`).join(""):'<p class="p2k-table-status">No tournament medal is recorded.</p>'}).catch(()=>{const list=profileRoot?.querySelector("[data-tournament-history]"),metric=profileRoot?.querySelector("[data-tournament-medals]");if(list)list.innerHTML='<p class="p2k-table-status is-error">Tournament history is temporarily unavailable.</p>';if(metric)metric.innerHTML='<span>Tournament medals</span><strong>—</strong><small>History unavailable</small>'});
      if(window.P2K_API_CLIENT?.json){const chessProfileURL=`https://api.chess.com/pub/player/${encodeURIComponent(String(player.username||name).toLowerCase())}`;window.P2K_API_CLIENT.json(chessProfileURL,{attempts:2,cacheMode:"network-only",priority:-20}).then(fresh=>{const freshAvatar=String(fresh?.avatar||""),avatarHost=profileRoot?.querySelector(".p2k-profile-avatar");if(avatarHost&&freshAvatar)avatarHost.innerHTML=`<img src="${escapeHTML(freshAvatar)}" alt="${escapeHTML(fresh?.username||player.username||name)} avatar">`;const identity=profileRoot?.querySelector(".p2k-profile-identity > div:last-child"),freshURL=String(fresh?.url||"");if(identity&&freshURL&&!identity.querySelector("a"))identity.insertAdjacentHTML("beforeend",`<a href="${escapeHTML(freshURL)}" target="_blank" rel="noopener noreferrer">Open Chess.com profile</a>`)}).catch(()=>{})}
    }catch(error){showInsightsModal({replace:true,eyebrow:"Player profile",title:name,subtitle:"Unable to load",html:`<p class="p2k-table-status is-error">${escapeHTML(error.message||error)}</p>`})}
  }
  function membersTableColumns() { return []; }
  function loadFeatureScriptWithRetry(src, readiness, label) {
    const attempt = retry => new Promise((resolve, reject) => {
      if (readiness()) return resolve();
      const script = document.createElement("script");
      const url = new URL(src, window.location.href);
      if (retry) url.searchParams.set("hotfixRetry", String(Date.now()));
      script.src = url.href;
      script.defer = true;
      let settled = false;
      const finish = error => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        if (error) reject(error); else resolve();
      };
      const timer = window.setTimeout(() => finish(new Error(`${label} timed out while loading.`)), 8000);
      script.onload = () => readiness() ? finish() : finish(new Error(`${label} loaded without initializing.`));
      script.onerror = () => finish(new Error(`Unable to load ${label}.`));
      document.head.appendChild(script);
    });
    return attempt(false).catch(first => attempt(true).catch(second => {
      console.warn(`${label} failed after hotfix retry.`, first, second);
      throw second;
    }));
  }
  let dashboardInsightsModulePromise = null;
  function dashboardInsightsContext() {
    return { state, byId, number, escapeHTML, cellWithSub, insightAction, openUnifiedPlayerProfile, formatDateOnly, formatRelative, setText, loadJSON, profileMetric, showInsightsModal, matchRulesLabel, matchTimeControlLabel, statusChip, writeNavigationState };
  }
  function ensureDashboardInsightsModule() {
    if (window.P2K_DASHBOARD_INSIGHTS) return Promise.resolve(window.P2K_DASHBOARD_INSIGHTS);
    if (dashboardInsightsModulePromise) return dashboardInsightsModulePromise;
    dashboardInsightsModulePromise = loadFeatureScriptWithRetry(
      "assets/js/pages/dashboard-insights.js?v=2.10.6.24",
      () => typeof window.P2K_CREATE_DASHBOARD_INSIGHTS === "function",
      "Dashboard Insights module"
    ).then(() => {
      const factory = window.P2K_CREATE_DASHBOARD_INSIGHTS;
      window.P2K_DASHBOARD_INSIGHTS = factory(dashboardInsightsContext());
      return window.P2K_DASHBOARD_INSIGHTS;
    }).catch(error => { dashboardInsightsModulePromise = null; throw error; });
    return dashboardInsightsModulePromise;
  }
  async function loadMemberInsights(options = {}) { return (await ensureDashboardInsightsModule()).loadMemberInsights(options); }
  async function loadMatchInsights(options = {}) { return (await ensureDashboardInsightsModule()).loadMatchInsights(options); }
  async function loadOpponentInsights(options = {}) { return (await ensureDashboardInsightsModule()).loadOpponentInsights(options); }
  async function loadArenaInsights(options = {}) { return (await ensureDashboardInsightsModule()).loadArenaInsights(options); }
  async function openMatchDetail(matchId, options = {}) { return (await ensureDashboardInsightsModule()).openMatchDetail(matchId, options); }
  function renderNativeBarLine(...args) { ensureDashboardInsightsModule().then(api => api.renderNativeBarLine(...args)).catch(error => console.warn(error)); }
function integratedFrames() {
    return Array.from(document.querySelectorAll("iframe.dashboard-integrated-frame[id]"));
  }
  function setIntegratedFrameActivity(activeId = "") {
    integratedFrames().forEach(frame => {
      if (!frame?.contentWindow) return;
      try { frame.contentWindow.postMessage({ type: "p2k-tool-activity", active: frame.id === activeId }, window.location.origin); } catch (_) {}
    });
  }
  function ensureIntegratedFrame(id) {
    const frame = byId(id);
    if (!frame) return;
    const activate = () => {
      setIntegratedFrameActivity(id);
      try { frame.contentWindow?.postMessage({ type: "p2k-admin-ready", allowed: window.P2K_ADMIN_MODE === true }, window.location.origin); } catch (_) {}
    };
    if (!frame.dataset.p2kActivityBound) {
      frame.dataset.p2kActivityBound = "1";
      frame.addEventListener("load", () => {
        if (frame._p2kLoadTimer) clearTimeout(frame._p2kLoadTimer);
        frame.dataset.p2kLoaded = "1";
        activate();
        window.setTimeout(activate, 50);
      });
    }
    const armLoadTimeout = () => {
      if (frame._p2kLoadTimer) clearTimeout(frame._p2kLoadTimer);
      frame.dataset.p2kLoaded = "0";
      frame._p2kLoadTimer = window.setTimeout(() => {
        if (frame.dataset.p2kLoaded === "1" || frame.dataset.p2kRetried === "1") return;
        frame.dataset.p2kRetried = "1";
        try {
          const retry = applyOAuthContext(new URL(frame.src || frame.dataset.src || "about:blank", window.location.href));
          retry.searchParams.set("hotfixRetry", String(Date.now()));
          frame.src = retry.href;
        } catch (error) { console.warn(`Unable to retry integrated frame ${id}.`, error); }
      }, 8000);
    };
    if (!frame.src) {
      const url = applyOAuthContext(new URL(frame.dataset.src || "about:blank", window.location.href));
      url.searchParams.set("active", "1");
      if (id === "teamInsightsFrame") {
        if (state.teamStart) url.searchParams.set("start", state.teamStart);
        if (state.teamEnd) url.searchParams.set("end", state.teamEnd);
      }
      if (id === "adminIntelligenceFrame" && state.adminContext) url.searchParams.set("tab", state.adminContext);
      if (id === "adminTasksFrame" && ["scheduled","green"].includes(state.adminToolTab)) url.searchParams.set("tab", state.adminToolTab);
      if (id === "adminOpenFrame") {
        const parentParams = new URLSearchParams(window.location.search);
        for (const key of ["match", "club", "team", "scope"]) { const value = parentParams.get(key); if (value) url.searchParams.set(key, value); }
      }
      frame.src = url.href;
      armLoadTimeout();
    } else {
      if (id === "adminIntelligenceFrame" && state.adminContext) {
        try {
          const current = applyOAuthContext(new URL(frame.src, window.location.href));
          if (current.searchParams.get("tab") !== state.adminContext) {
            current.searchParams.set("tab", state.adminContext);
            current.searchParams.set("active", "1");
            frame.dataset.p2kLoaded = "0";
            frame.src = current.href;
            armLoadTimeout();
            return;
          }
        } catch (_) {}
      }
      if (id === "adminTasksFrame" && ["scheduled","green"].includes(state.adminToolTab)) {
        try {
          const current = applyOAuthContext(new URL(frame.src, window.location.href));
          if (current.searchParams.get("tab") !== state.adminToolTab) {
            current.searchParams.set("tab", state.adminToolTab);
            current.searchParams.set("active", "1");
            frame.dataset.p2kLoaded = "0";
            frame.src = current.href;
            armLoadTimeout();
            return;
          }
        } catch (_) {}
      }
      activate();
      if (frame.dataset.p2kLoaded !== "1") armLoadTimeout();
    }
  }
  function currentSession() { return window.P2K_AUTH?.getSession?.() || null; }
  function memberRecord(payload, username) {
    const key = adminEntryUsername(username);
    if (!key || !payload || typeof payload !== "object") return null;
    for (const bucket of ["all_time", "monthly", "weekly"]) {
      for (const member of Array.isArray(payload[bucket]) ? payload[bucket] : []) {
        if (adminEntryUsername(member?.username || member?.name || member) === key) return member;
      }
    }
    return null;
  }
  function renderPersonalJoinDate() {
    const host = byId("personalJoinDate");
    if (!host) return;
    const joined = state.playerPoints?.joined_at || state.playerPoints?.first_seen_at || null;
    if (joined) {
      const date = new Date(String(joined).replace(" ", "T") + (String(joined).includes("Z") ? "" : "Z"));
      host.textContent = Number.isNaN(date.getTime()) ? "Join date unavailable" : `Member since ${date.toLocaleDateString("en-GB",{timeZone:"UTC",year:"numeric",month:"short",day:"numeric"})}`;
      return;
    }
    const record = memberRecord(state.membersPayload, viewed());
    const epoch = Number(record?.joined || record?.joined_at || 0);
    host.textContent = epoch > 0 ? `Member since ${new Date(epoch * 1000).toLocaleDateString("en-GB",{timeZone:"UTC",year:"numeric",month:"short",day:"numeric"})}` : "Join date unavailable";
  }
  function rankForPoints(points) {
    const numeric = Math.max(0, Number(points) || 0);
    return ranks.reduce((selected, rank) => numeric >= rank.minimum ? rank : selected, null) || unrankedRank;
  }
  const nullableMetric = value => value === null || value === undefined || value === "" ? "—" : number(value);
  const challengeProgress=m=>Array.isArray(m?.challenges)&&m.challenges.length?m.challenges:Array.isArray(m?.achievement_progress)?m.achievement_progress:[];
  function setPersonalRankImage(source, fallback, alt, title) {
    const image = byId("personalRankImage");
    if (!image) return;
    image.onerror = () => {
      image.onerror = null;
      image.src = fallback || "assets/images/p2k-logo.jpg";
    };
    image.src = source || fallback || "assets/images/p2k-logo.jpg";
    image.alt = alt;
    image.title = title;
  }
  const bindHome=(h,n)=>{h.hidden=false;h.querySelector("[data-home-achievements-view]")?.addEventListener("click",()=>{showPublicPage("hall",{hallSubtab:"achievements"});selectHallSubtab("achievements")});h.querySelectorAll("[data-home-challenge]").forEach(b=>b.addEventListener("click",()=>openAchievementCatalog(n,String(b.dataset.homeChallenge||""))))};
  let personalizedHomeRequest="", personalizedHomePromise=null;
  async function loadPersonalizedHome(username) {
    const name=String(username||"").trim(),host=byId("personalizedHome");if(!name||!host)return;if(personalizedHomeRequest===name&&personalizedHomePromise)return personalizedHomePromise;if(personalizedHomeRequest===name&&!host.hidden)return;personalizedHomeRequest=name;
    const request=(async()=>{try{const url=new URL("server/team-points/public/member-intelligence.php",window.location.href);url.searchParams.set("username",name);const payload=await loadPublicCachedJSON(url.href,{ttl:120000,credentials:"same-origin"}),member=payload?.player?.member;if(!member||payload?.player?.found===false){host.hidden=true;return;}const challenges=challengeProgress(member);host.innerHTML=`<div class="dashboard-achievement-challenges"><div class="dashboard-achievement-heading"><strong>Achievements</strong><button type="button" data-home-achievements-view>View →</button></div>${challenges.length?challenges.slice(0,3).map(c=>{const criterion=String(c.criteria||c.description||"Achievement criterion unavailable.");return `<button type="button" class="p2k-challenge-row" data-home-challenge="${escapeHTML(c.key||'')}" title="${escapeHTML(criterion)}" aria-label="${escapeHTML(`${c.label||"Achievement"} — ${criterion}`)}"><span>${escapeHTML(c.label)}</span><progress max="100" value="${Number(c.progress_percent||0)}" aria-label="${escapeHTML(`${c.label||"Achievement"} progress`)}"></progress></button>`}).join(""):`<p class="p2k-table-status">No supported achievement challenge is currently in progress.</p>`}</div>`;bindHome(host,name);}catch(_){host.innerHTML=`<div class="dashboard-achievement-challenges"><div class="dashboard-achievement-heading"><strong>Achievements</strong><button type="button" data-home-achievements-view>View →</button></div><p class="p2k-table-status">Challenges temporarily unavailable.</p></div>`;bindHome(host,name);}})();personalizedHomePromise=request;try{return await request;}finally{if(personalizedHomePromise===request)personalizedHomePromise=null;}
  }
  function renderDailyPersonalCard(payload = state.playerPoints || {}) {
    const points = Number(payload?.points || 0);
    const localRank = rankForPoints(points);
    const rank = payload?.rank && typeof payload.rank === "object" ? { ...localRank, ...payload.rank } : localRank;
    setText("personalPointsLabel", "Team Points");
    const info = byId("personalPointsInfo");
    if (info) info.dataset.p2kInfoMessage = "Team Points are earned from finished team games: one point for a win and half a point for a draw.";
    setText("personalTeamPoints", number(points));
    setText("personalStat1Label", "Wins");
    setText("personalStat2Label", "Draws");
    setText("personalStat3Label", "Losses");
    setText("personalWins", number(payload?.wins || 0));
    setText("personalDraws", number(payload?.draws || 0));
    setText("personalLosses", number(payload?.losses || 0));
    const teamPosition = Number(payload?.team_position);
    const positionLabel = Number.isFinite(teamPosition) && teamPosition > 0 ? `Team #${number(teamPosition)}` : "Team position unavailable";
    setText("personalRankName", `${rank.name} · ${positionLabel} · ${number(payload?.matches || 0)} matches`);
    const source = rankThumbnailAsset(rank.image, 320);
    setPersonalRankImage(source, originalRankAsset(rank.image), `${rank.name} team rank`, `${rank.name} — ${number(points)} Team Points · ${positionLabel}`);
    const hallButton = byId("openHallOfFame");
    if (hallButton) hallButton.textContent = "View Daily Ranks →";
    byId("personalDailyActivity").hidden = false;
    byId("personalLiveActivity").hidden = true;
    if (byId("explorePlayerGames")) byId("explorePlayerGames").hidden = false;
    setText("hallPersonalPosition", Number.isFinite(teamPosition) && teamPosition > 0 ? `#${number(teamPosition)} · ${rank.name}` : "—");
    if(viewed())void loadPersonalizedHome(viewed());
  }
  function renderLivePersonalCard(payload = state.playerLive || {}) {
    const available = payload?.available === true;
    const points = Number(payload?.points || 0);
    const rank = payload?.rank && typeof payload.rank === "object" ? payload.rank : null;
    setText("personalPointsLabel", "Live points");
    const info = byId("personalPointsInfo");
    if (info) info.dataset.p2kInfoMessage = "Live points are arena scores aggregated from Multi-Club Arenas (MCAs).";
    setText("personalTeamPoints", number(points));
    setText("personalStat1Label", "Best rank");
    setText("personalStat2Label", "Top 3");
    setText("personalStat3Label", "Top 10");
    setText("personalWins", nullableMetric(payload?.best_rank));
    setText("personalDraws", number(payload?.top3_count || 0));
    setText("personalLosses", number(payload?.top10_count || 0));
    const teamPosition = Number(payload?.team_position);
    const positionLabel = Number.isFinite(teamPosition) && teamPosition > 0 ? `Team #${number(teamPosition)}` : "Team position unavailable";
    const rankName = rank?.name || payload?.rank_name || "Unranked";
    setText("personalRankName", available ? `${rankName} · ${positionLabel} · ${number(payload?.arenas || 0)} arenas` : "No Live arena record in the current MCA data");
    if (rank?.icon) {
      const icon = String(rank.icon);
      const source = `assets/images/ranks/thumbs/320/${icon.replace(/\.png$/i, ".webp")}`;
      setPersonalRankImage(source, `assets/images/ranks/${icon}`, `${rankName} Live rank`, `${rankName} — ${number(points)} Live points · ${positionLabel}`);
    } else {
      setPersonalRankImage("assets/images/p2k-logo.jpg", "assets/images/p2k-logo.jpg", "No Live rank", "No Live arena rank is available yet");
    }
    setText("personalLiveBestScore", nullableMetric(payload?.best_score));
    setText("personalLiveTotalWins", nullableMetric(payload?.wins));
    setText("personalLiveMaxWins", nullableMetric(payload?.max_wins_single_arena));
    setText("personalLiveBestStreak", nullableMetric(payload?.best_streak));
    const hallButton = byId("openHallOfFame");
    if (hallButton) hallButton.textContent = "View Live Ranks →";
    byId("personalDailyActivity").hidden = true;
    byId("personalLiveActivity").hidden = false;
    if (byId("explorePlayerGames")) byId("explorePlayerGames").hidden = true;
    if(viewed())void loadPersonalizedHome(viewed());
  }
  function shortArenaName(value) {
    return String(value || "").replace(/\.csv$/i, "").replaceAll("-", " ").trim() || "Arena not available";
  }
  function renderLiveTeamData(payload = state.liveTeamData || {}) {
    setText("teamLiveArenas", number(payload?.arenas || 0));
    setText("teamLiveCurrentPlayers", number(payload?.current_players || 0));
    setText("teamLiveMostParticipants", number(payload?.most_participants || 0));
    setText("teamLiveMostParticipantsArena", shortArenaName(payload?.most_participants_arena));
    setText("teamLiveMostPoints", nullableMetric(payload?.most_points));
    setText("teamLiveMostPointsArena", shortArenaName(payload?.most_points_arena));
    setText("teamLiveFirstPlaces", number(payload?.first_places || 0));
    setText("teamLiveSecondPlaces", number(payload?.second_places || 0));
    setText("teamLiveThirdPlaces", number(payload?.third_places || 0));
    setText("teamLiveAggregatePoints", nullableMetric(payload?.aggregate_points));
  }
  function renderTeamMode() {
    const live = state.personalMode === "live";
    const daily = byId("teamDailyContent");
    const livePanel = byId("teamLiveContent");
    if (daily) daily.hidden = live;
    if (livePanel) livePanel.hidden = !live;
    setText("teamStatsTitle", live ? "Promote to King · Live arenas" : "Promote to King");
    if (live) renderLiveTeamData();
  }
  function renderPersonalCard() {
    document.querySelectorAll("[data-player-mode]").forEach(button => {
      const active = button.dataset.playerMode === state.personalMode;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-pressed", String(active));
    });
    if (state.personalMode === "live") renderLivePersonalCard();
    else renderDailyPersonalCard();
  }
  function selectPersonalMode(mode, { updateHistory = true } = {}) {
    state.personalMode = mode === "live" ? "live" : "daily";
    renderPersonalCard();
    renderTeamMode();
    if (updateHistory) writeNavigationState({ replace: true });
  }
  function renderPlayerPoints(payload) {
    const points = Number(payload?.points || 0);
    const localRank = rankForPoints(points);
    const rank = payload?.rank && typeof payload.rank === "object" ? { ...localRank, ...payload.rank } : localRank;
    state.playerPoints = { ...payload, points, rank };
    if (state.personalMode === "daily") renderPersonalCard();
  }
  function renderLivePlayer(payload) {
    state.playerLive = payload && typeof payload === "object" ? payload : { available: false, points: 0 };
    if (state.personalMode === "live") renderPersonalCard();
  }
  function playerMatchKey(entry) {
    const direct = String(entry?.["@id"] || entry?.url || entry?.match || "").trim();
    if (direct) return direct.toLowerCase();
    const board = String(entry?.board || "").trim();
    return board ? board.replace(/\/\d+\/?$/, "").toLowerCase() : "";
  }
  function uniquePlayerMatches(entries) {
    const seen = new Set();
    return (Array.isArray(entries) ? entries : []).filter(entry => {
      if (!entry || typeof entry !== "object") return false;
      const club = String(entry.club || "").replace(/\/$/, "").toLowerCase();
      if (club !== clubProfileAPI.toLowerCase()) return false;
      const key = playerMatchKey(entry);
      if (!key || seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }
  function renderPlayerActivity(matchesPayload, pointsPayload = state.playerPoints || {}) {
    const lists = {
      registered: uniquePlayerMatches(matchesPayload?.registered || matchesPayload?.registration),
      ongoing: uniquePlayerMatches(matchesPayload?.in_progress || matchesPayload?.ongoing),
      finished: uniquePlayerMatches(matchesPayload?.finished)
    };
    const currentGames = lists.ongoing.length * 2;
    const playedGames = Number(pointsPayload?.games || 0);
    const playedMatches = Number(pointsPayload?.matches || 0);
    state.playerActivity = { lists, currentGames, playedGames, playedMatches };
    setText("personalCurrentGames", `${number(currentGames)} games`);
    setText("personalCurrentMatches", `in ${number(lists.ongoing.length)} matches`);
    setText("personalRegisteredMatches", `${number(lists.registered.length)} matches`);
    setText("personalPlayedGames", `${number(playedGames)} games`);
    setText("personalPlayedMatches", `in ${number(playedMatches)} matches`);
  }
  function playerMatchResult(entry) {
    const results = entry?.results && typeof entry.results === "object" ? entry.results : {};
    const white = String(results.played_as_white || "").trim();
    const black = String(results.played_as_black || "").trim();
    return [white && `White: ${white}`, black && `Black: ${black}`].filter(Boolean).join(" · ") || "Two team games";
  }
  function playerMatchTitle(entry) {
    return String(entry?.name || entry?.title || "Team match");
  }
  function renderPlayerGamesSection(host, titleText, entries, emptyText) {
    const section = document.createElement("section");
    section.className = "dashboard-player-games-section";
    const heading = document.createElement("h3");
    heading.textContent = `${titleText} (${number(entries.length)})`;
    section.appendChild(heading);
    if (!entries.length) {
      const empty = document.createElement("p");
      empty.className = "dashboard-player-games-empty";
      empty.textContent = emptyText;
      section.appendChild(empty);
    } else {
      entries.forEach(entry => {
        const row = document.createElement("article");
        row.className = "dashboard-player-game-row";
        const copy = document.createElement("div");
        const title = document.createElement("strong");
        title.textContent = playerMatchTitle(entry);
        const meta = document.createElement("small");
        meta.textContent = `${formatDateOnly(entry?.start_time || entry?.end_time)} · ${playerMatchResult(entry)}`;
        copy.append(title, meta);
        const url = matchURL(entry);
        if (url) {
          const link = document.createElement("a");
          link.className = "dashboard-button dashboard-button-muted";
          link.href = url;
          link.target = "_blank";
          link.rel = "noopener noreferrer";
          link.textContent = "Open";
          row.append(copy, link);
        } else row.append(copy);
        section.appendChild(row);
      });
    }
    host.appendChild(section);
  }
  function openPlayerGames() {
    const activity = state.playerActivity || { lists: { registered: [], ongoing: [], finished: [] }, currentGames: 0, playedGames: 0, playedMatches: 0 };
    const username = viewed() || "Player";
    setText("playerGamesTitle", `${username}'s team games`);
    setText("playerGamesSummary", `${number(activity.currentGames)} current games in ${number(activity.lists.ongoing.length)} matches · registered to ${number(activity.lists.registered.length)} matches · ${number(activity.playedGames)} games played in ${number(activity.playedMatches)} matches.`);
    const host = byId("playerGamesList");
    host.replaceChildren();
    renderPlayerGamesSection(host, "Registered", activity.lists.registered, "No current team-match registrations were found.");
    renderPlayerGamesSection(host, "Ongoing", activity.lists.ongoing, "No team matches are currently in progress.");
    renderPlayerGamesSection(host, "Recent finished", activity.lists.finished, "No recent finished team matches were returned by Chess.com.");
    byId("playerGamesModal").hidden = false;
    document.body.classList.add("dashboard-modal-open");
  }
  function closePlayerGames() {
    byId("playerGamesModal").hidden = true;
    document.body.classList.remove("dashboard-modal-open");
  }
  async function applySession(session) {
    state.session = session || null;
    const loggedIn = Boolean(state.session?.username);
    if (state.liveRanksPayload) renderLiveRanksNative(state.liveRanksPayload);
    if (loggedIn) renderPersonalCard();
    byId("personalGuest").hidden = loggedIn;
    byId("personalMember").hidden = !loggedIn;
    byId("personalModeToggle").hidden = !loggedIn;
    setText("personalStatsTitle", loggedIn ? viewed() : "Member");
    setText("personalStatusBadge", loggedIn ? "Loading" : (window.P2K_AUTH?.enabled ? "Login required" : "OAuth disabled"));
    renderPersonalJoinDate();
    if (!loggedIn) {
      setText("personalGuestMessage", "Use the login button in the header to load authenticated player information.");
    } else {
      loadPersonalData(viewed());
    }
    verifyAdmin(window.P2K_AUTH?.getDisplaySession?.() || state.session).catch(error => console.warn("Unable to verify administrator state.", error));
    if (loggedIn) (window.P2K_PROGRESSIVE?.afterFirstPaint || (fn => setTimeout(fn, 0)))(() => (window.P2K_PROGRESSIVE?.lowPriority || ((run)=>Promise.resolve().then(run)))(() => loadRecommendations()));
    else loadRecommendations();
  }
  async function loadPersonalData(username) {
    const normalized = String(username || "").trim().toLowerCase();
    if (!normalized) return;
    const snapshotKey = `dashboard-player-${normalized}`;
    const cached = window.P2K_PROGRESSIVE?.snapshotGet?.(snapshotKey, 6 * 3600000);
    if (cached?.payload?.points) {
      renderPlayerPoints(cached.payload.points);
      renderPersonalCard();
      setText("personalStatusBadge", "Cached · refreshing");
    }
    let pointsPayload = {};
    try {
      const pointsResult = await (window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("player", { username: normalized }) || Promise.reject(new Error("Team Points client unavailable")));
      if (adminEntryUsername(viewed()) !== normalized) return;
      pointsPayload = pointsResult?.player || {};
      renderPlayerPoints(pointsPayload);
      renderPersonalCard();
      setText("personalStatusBadge", "Database linked");
      window.P2K_PROGRESSIVE?.snapshotSet?.(snapshotKey, { points: pointsPayload });
    } catch (error) {
      console.warn("Unable to load personal Team Points data.", error);
      if (!cached?.payload?.points) state.playerPoints = { available: false, points: 0, rank: unrankedRank };
      renderPersonalCard();
    }
    const secondWave = async () => {
      const low = window.P2K_PROGRESSIVE?.lowPriority || ((run) => Promise.resolve().then(run));
      const greenLists = pointsPayload?.data_source === "green_native_core" && pointsPayload?.match_lists && typeof pointsPayload.match_lists === "object" ? pointsPayload.match_lists : null;
      const [matchesResult, liveResult] = await Promise.allSettled([
        greenLists ? Promise.resolve(greenLists) : low(() => loadJSON(`https://api.chess.com/pub/player/${encodeURIComponent(normalized)}/matches`)),
        low(() => loadJSON(`server/team-points/public/public.php?action=live-player&username=${encodeURIComponent(normalized)}&_fresh=${Date.now()}`, { credentials: "same-origin", cache: "no-store" }))
      ]);
      if (adminEntryUsername(viewed()) !== normalized) return;
      if (liveResult.status === "fulfilled") renderLivePlayer(liveResult.value?.player || {});
      else renderLivePlayer({ available: false, points: 0 });
      if (matchesResult.status === "fulfilled") renderPlayerActivity(matchesResult.value, pointsPayload);
      else renderPlayerActivity({}, pointsPayload);
      renderPersonalCard();
    };
    (window.P2K_PROGRESSIVE?.afterFirstPaint || (fn => setTimeout(fn, 0)))(secondWave);
  }
  function matchLists(payload) {
    return {
      registered: Array.isArray(payload?.registered) ? payload.registered : Array.isArray(payload?.registration) ? payload.registration : [],
      ongoing: Array.isArray(payload?.in_progress) ? payload.in_progress : Array.isArray(payload?.ongoing) ? payload.ongoing : [],
      finished: Array.isArray(payload?.finished) ? payload.finished : []
    };
  }
  function matchBoardCount(match) {
    const direct = [match?.boards, match?.board_count, match?.num_boards, match?.boardCount]
      .map(Number).find(value => Number.isFinite(value) && value >= 0);
    if (direct !== undefined) return direct;
    const teams = Array.isArray(match?.teams) ? match.teams : Object.values(match?.teams || {});
    const playerCounts = teams.map(team => Array.isArray(team?.players) ? team.players.length : 0).filter(Boolean);
    if (playerCounts.length) return Math.min(...playerCounts);
    const configured = Number(match?.settings?.max_team_players ?? match?.settings?.max_players ?? match?.max_players);
    return Number.isFinite(configured) && configured >= 0 ? configured : 0;
  }
  function matchListTotals(list) {
    const boards = (Array.isArray(list) ? list : []).reduce((sum, match) => sum + matchBoardCount(match), 0);
    return { boards, games: boards * 2 };
  }
  function authoritativeMatchListTotals(list) {
    return window.P2K_DASHBOARD_MATCH_BOARD_HYDRATION?.totals(list) || { boards: 0, games: 0, unresolved: Array.isArray(list) ? list.length : 0 };
  }
  async function hydrateDashboardMatchBoards(lists) {
    const dmbhf = window.P2K_DASHBOARD_MATCH_BOARD_HYDRATION;
    if (!dmbhf?.hydrate) throw new Error("Dashboard Match Board Hydration module is unavailable.");
    return dmbhf.hydrate(lists, { client: window.P2K_API_CLIENT, loadJSON });
  }
  function setMatchMetric(status, list, options = {}) {
    const ids = {
      registered: ["teamOpenRegistrations", "teamRegisteredBoards"],
      ongoing: ["teamActiveMatches", "teamOngoingBoards"],
      finished: ["teamFinishedMatches", "teamFinishedBoards"]
    }[status];
    if (!ids) return;
    const matches = Array.isArray(list) ? list : [];
    setText(ids[0], number(matches.length));
    if (options.loadingBoards) {
      setText(ids[1], "Loading authoritative board totals…");
      return;
    }
    const totals = status === "finished" ? matchListTotals(matches) : authoritativeMatchListTotals(matches);
    const suffix = totals.unresolved > 0 ? "+" : "";
    setText(ids[1], `${number(totals.boards)}${suffix} boards · ${number(totals.games)}${suffix} games${totals.unresolved > 0 ? ` · ${number(totals.unresolved)} unresolved` : ""}`);
  }
  async function loadTeamData() {
    setText("teamStatusBadge", "Loading Green database");
    const data = { members: null, profile: null, matches: null, database: null, lists: { registered: [], ongoing: [], finished: [] } };
    const cached = window.P2K_PROGRESSIVE?.snapshotGet?.("dashboard-team", 6 * 3600000);
    try {
      const databaseResult = await (window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("team", { _fresh: Date.now() }) || Promise.reject(new Error("Team Points client unavailable")));
      data.database = databaseResult?.team || {};
      data.members = Number(data.database.current_members ?? 0) || null;
      setText("teamMembers", number(data.members));
      setText("teamClubPoints", number(data.database.club_points));
      setText("teamClubPointsNote", "5 × boards won · 2 × boards drawn");
      if (data.database.finished_matches_available) {
        setText("teamFinishedMatches", number(data.database.finished_matches));
        setText("teamFinishedBoards", `${number(data.database.finished_boards)} boards · ${number(data.database.finished_games)} games`);
      }
      setText("teamOpenRegistrations", number(data.database.registered_matches ?? 0));
      setText("teamRegisteredBoards", `${number(data.database.registered_boards ?? 0)} boards · ${number(data.database.registered_games ?? 0)} games`);
      setText("teamActiveMatches", number(data.database.in_progress_matches ?? data.database.ongoing_matches ?? 0));
      setText("teamOngoingBoards", `${number(data.database.in_progress_boards ?? data.database.ongoing_boards ?? 0)} boards · ${number(data.database.in_progress_games ?? data.database.ongoing_games ?? 0)} games`);
      state.teamData = data;
      renderTeamMode();
      setText("teamStatusBadge", data.database.data_source === "green_native_core" ? "GREEN · live database" : "Database ready");
      window.P2K_PROGRESSIVE?.snapshotSet?.("dashboard-team", { database: data.database, members: data.members });
    } catch (error) {
      console.warn("Green Dashboard database refresh failed.", error);
      if (cached?.payload?.database) {
        data.database = cached.payload.database; data.members = Number(data.database.current_members ?? cached.payload.members ?? 0) || null;
        setText("teamMembers", number(data.members)); setText("teamClubPoints", number(data.database.club_points));
        setText("teamStatusBadge", "Database unavailable · cached fallback");
      } else {
        setText("teamClubPoints", "—"); setText("teamClubPointsNote", "Database unavailable"); setText("teamStatusBadge", "Database unavailable");
      }
    }
    const secondWave = () => {
      renderPersonalJoinDate();
      Promise.all([
        window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("dashboard-matches", { status: "registered", limit: 1500, _fresh: Date.now() }),
        window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("dashboard-matches", { status: "in_progress", limit: 2000, _fresh: Date.now() })
      ]).then(([registeredPayload, ongoingPayload]) => {
        data.lists.registered = Array.isArray(registeredPayload?.matches?.rows) ? registeredPayload.matches.rows : [];
        data.lists.ongoing = Array.isArray(ongoingPayload?.matches?.rows) ? ongoingPayload.matches.rows : [];
        setMatchMetric("registered", data.lists.registered);
        setMatchMetric("ongoing", data.lists.ongoing);
        setText("adminRegistrationCount", number(data.lists.registered.length));
        setText("adminOngoingCount", number(data.lists.ongoing.length));
        state.teamData = data; renderTeamMode();
      }).catch(error => console.warn("Green Dashboard match-list refresh failed.", error));
      loadJSON("server/team-points/public/public.php?action=live-team", { credentials: "same-origin", cache: "no-store" }).then(payload => {
        state.liveTeamData = payload?.team || payload || {}; renderLiveTeamData();
      }).catch(error => { console.warn("Live team refresh failed.", error); state.liveTeamData = {}; renderLiveTeamData(); });
    };
    (window.P2K_PROGRESSIVE?.afterFirstPaint || (fn => setTimeout(fn, 0)))(secondWave);
    (window.P2K_PROGRESSIVE?.afterIdle || (fn => setTimeout(fn, 1200)))(() => {
      if (window.P2K_PROGRESSIVE?.canPrefetch && !window.P2K_PROGRESSIVE.canPrefetch()) return;
      loadPublicCachedJSON("server/team-points/public/achievement-players.php?page=1&page_size=12&filter=current", { ttl: 120000, credentials: "same-origin" }).catch(() => {});
      loadPublicCachedJSON("server/team-points/public/team-insights.php?section=summary", { ttl: 120000, credentials: "same-origin" }).catch(() => {});
    });
  }
  function renderGauge(valueId, gaugeId, noteId, value, note) {
    const numeric = Number(value);
    const available = value !== null && value !== undefined && String(value).trim() !== "" && Number.isFinite(numeric);
    const bounded = available ? Math.max(0, Math.min(100, numeric)) : 0;
    const statusLabel = !available ? "—" : bounded >= 80 ? "Ready" : bounded >= 50 ? "Progressing" : "Needs support";
    setText(valueId, statusLabel);
    const gauge = byId(gaugeId);
    gauge.style.width = `${bounded}%`;
    const card = gauge.closest(".dashboard-gauge-card");
    if (card) {
      card.classList.remove("status-low", "status-medium", "status-good");
      if (available) card.classList.add(bounded >= 80 ? "status-good" : bounded >= 50 ? "status-medium" : "status-low");
    }
    setText(noteId, note);
  }
  function renderTeamIndicators(indicators) {
    if (!indicators || typeof indicators !== "object") {
      renderGauge("teamLineupReadinessValue", "teamLineupReadinessGauge", "teamLineupReadinessNote", null, "Log in to calculate from Match Assistant data");
      renderGauge("teamRegistrationTargetsValue", "teamRegistrationTargetsGauge", "teamRegistrationTargetsNote", null, "Log in to calculate from Match Assistant data");
      setText("teamStartingSoon", "—");
      setText("teamPriorityCalls", "—");
      return;
    }
    renderGauge(
      "teamLineupReadinessValue", "teamLineupReadinessGauge", "teamLineupReadinessNote",
      indicators.lineupReadiness,
      `${number(indicators.leagueMatches || 0)} priority league registration match${Number(indicators.leagueMatches) === 1 ? "" : "es"}`
    );
    renderGauge(
      "teamRegistrationTargetsValue", "teamRegistrationTargetsGauge", "teamRegistrationTargetsNote",
      indicators.registrationTargets,
      `${number(indicators.registrationMatches || 0)} open Daily registration match${Number(indicators.registrationMatches) === 1 ? "" : "es"}`
    );
    setText("teamStartingSoon", number(indicators.startingWithinSevenDays));
    setText("teamPriorityCalls", number(indicators.priorityCalls));
  }
  function stopRecommendationTimer() {
    window.clearTimeout(state.recommendationTimer);
    state.recommendationTimer = 0;
  }
  function stopAssistantTimer() {
    window.clearTimeout(state.assistantTimer);
    state.assistantTimer = 0;
  }
  function disposeRecommendationFrame() {
    stopRecommendationTimer();
    stopAssistantTimer();
    state.recommendationObserver?.disconnect?.();
    state.recommendationObserver = null;
    state.recommendationFrame?.remove();
    state.assistantFrame?.remove();
    state.recommendationFrame = null;
    state.assistantFrame = null;
    state.recommendationReady = false;
    state.assistantReady = false;
    state.assistantFullReady = false;
    state.assistantDedicated = false;
  }
  function prepareEmbeddedMatchAssistant(frame) {
    try {
      const doc = frame.contentDocument;
      if (!doc) return false;
      let style = doc.getElementById("p2k-dashboard-embedded-style");
      if (!style) {
        style = doc.createElement("style");
        style.id = "p2k-dashboard-embedded-style";
        style.textContent = `
          html,body{min-height:0!important;margin:0!important;padding:0!important;background:transparent!important;overflow:hidden!important}
          .finder{width:100%!important;max-width:none!important;min-height:0!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;background-image:none!important;box-shadow:none!important}
          .finder>.header,.p2k-user-search-row{display:none!important}
          html.p2k-dashboard-assistant-hydrating .finder{visibility:hidden!important}
        `;
        (doc.head || doc.documentElement).appendChild(style);
      }
      state.recommendationObserver?.disconnect?.();
      if ("ResizeObserver" in frame.contentWindow) {
        state.recommendationObserver = new frame.contentWindow.ResizeObserver(() => resizeRecommendationFrame(frame));
        state.recommendationObserver.observe(doc.body);
      }
      resizeRecommendationFrame(frame);
      return true;
    } catch (error) {
      console.warn("Unable to prepare embedded Match Assistant.", error);
      return false;
    }
  }
  function resizeRecommendationFrame(frame = state.recommendationFrame, reportedHeight = 0) {
    if (!frame || frame.hidden) return;
    let height = Number(reportedHeight) || 0;
    try {
      height = Math.max(height, frame.contentDocument?.documentElement?.scrollHeight || 0, frame.contentDocument?.body?.scrollHeight || 0);
    } catch (_) { /* same-origin frame may still be loading */ }
    frame.style.height = `${Math.max(520, height)}px`;
  }
  function recommendationError(message, { preserveExisting = false } = {}) {
    const text = String(message || "Recommendation analysis unavailable.");
    state.adminPriorityError = preserveExisting ? "" : text;
    renderAdminPriorityCard();
    const loading = byId("recommendationsLoading");
    if (loading) loading.hidden = true;
    const list = byId("recommendationsList");
    const empty = byId("recommendationsEmpty");
    if (!preserveExisting) {
      list?.replaceChildren();
      if (empty) { empty.textContent = text; empty.hidden = false; }
    } else {
      if (empty) empty.hidden = true;
      setText("recommendationsSubtitle", text);
    }
    const findMoreButton = byId("findMoreMatchesLink");
    if (findMoreButton) findMoreButton.disabled = false;
  }
  function loadRecommendations() {
    const run = ++state.recommendationRun;
    state.adminPriorityData = null;
    state.adminPriorityError = "";
    state.adminPriorityFailures = 0;
    state.recommendationFallbackVisible = false;
    renderAdminPriorityCard();
    disposeRecommendationFrame();
    const findMoreButton = byId("findMoreMatchesLink");
    if (findMoreButton) findMoreButton.disabled = true;
    byId("recommendationsList").replaceChildren();
    byId("recommendationsEmpty").hidden = true;
    const username = viewed();
    if (!username) {
      recommendationError("Log in to load the Match Assistant's personalized top five results.");
      setText("recommendationsSubtitle", "Recommendations use your live Daily and Chess960 ratings and the Match Assistant's default filters.");
      renderTeamIndicators(null);
      return;
    }
    byId("recommendationsLoading").hidden = false;
    setText("recommendationsSubtitle", `Analyzing current opportunities for ${username}…`);
    const frame = document.createElement("iframe");
    frame.dataset.username = username;
    frame.title = "Promote to King Match Assistant recommendation engine";
    frame.className = "dashboard-recommendation-engine";
    frame.hidden = true;
    frame.setAttribute("aria-hidden", "true");
    frame.tabIndex = -1;
    const url = preservedURL(config.routes?.find || "FindMatch.htm");
    url.searchParams.set("dashboardRecommendations", "1");
    url.searchParams.set("username", username);
    frame.src = url.href;
    state.recommendationFrame = frame;
    state.assistantFullReady = false;
    byId("dashboardMatchAssistantHost")?.appendChild(frame);
    state.recommendationTimer = window.setTimeout(() => {
      if (run !== state.recommendationRun) return;
      const fallback = preservedURL(config.routes?.find || "FindMatch.htm");
      fallback.searchParams.set("dashboardAssistant", "1");
      fallback.searchParams.set("username", username);
      disposeRecommendationFrame();
      const button = byId("findMoreMatchesLink");
      if (button) button.dataset.fallbackHref = fallback.href;
      recommendationError(
        state.recommendationFallbackVisible
          ? "Live refresh timed out; cached recommendations shown."
          : "The Match Assistant recommendation analysis took too long. Open the full assistant to retry.",
        { preserveExisting: state.recommendationFallbackVisible }
      );
    }, 90000);
  }
  function displayRatingRange(value) {
    const text = String(value || "—").trim();
    return /^0\s*\+$/.test(text) ? "Open rating" : text;
  }
  function recommendationCard(item) {
    const article = document.createElement("article");
    article.className = `dashboard-recommendation${item.priority ? " is-priority" : ""}`;
    const line = document.createElement("div");
    line.className = "dashboard-recommendation-line";
    const tags = document.createElement("div");
    tags.className = "dashboard-recommendation-tags";
    const tag = (label, className = "") => {
      const element = document.createElement("span");
      element.className = `dashboard-tag${className ? ` ${className}` : ""}`;
      element.textContent = label;
      return element;
    };
    tags.append(
      tag(item.league || "Open", item.priority ? "priority" : ""),
      tag(`${item.scoreLabel || "Recommended"} ${Number(item.score || 0)}`, "recommended"),
      tag(displayRatingRange(item.ratingRange), "rating"),
      tag(item.rules || "Daily", "rules")
    );
    const title = document.createElement("a");
    title.className = "dashboard-recommendation-title";
    title.textContent = item.name || "Unnamed match";
    title.href = item.url || preservedURL(config.routes?.find || "FindMatch.htm").href;
    if (item.url) { title.target = "_blank"; title.rel = "noopener noreferrer"; }
    const date = document.createElement("span");
    date.className = "dashboard-recommendation-date";
    date.textContent = formatDateOnly(item.startTime);
    const info = document.createElement("span");
    info.className = "dashboard-recommendation-info-wrap";
    const infoId = `dashboardRecommendationInfo${++recommendationInfoSequence}`;
    const infoButton = document.createElement("button");
    infoButton.type = "button";
    infoButton.className = "dashboard-recommendation-info-button p2k-info-button";
    infoButton.setAttribute("aria-label", "Show recommendation justification");
    infoButton.setAttribute("aria-expanded", "false");
    infoButton.setAttribute("aria-controls", infoId);
    infoButton.dataset.p2kInfoTrigger = infoId;
    infoButton.title = "Recommendation justification";
    infoButton.textContent = "i";
    const content = document.createElement("span");
    content.id = infoId;
    content.className = "dashboard-recommendation-popover p2k-info-popover";
    content.dataset.kind = "dialog";
    content.dataset.p2kInfoPopover = "dialog";
    content.setAttribute("role", "dialog");
    content.setAttribute("aria-label", "Recommendation justification");
    content.hidden = true;
    const reasons = Array.isArray(item.reasons) && item.reasons.length ? item.reasons.join(" ") : "The Match Assistant recommendation score passed its default threshold.";
    const reason = document.createElement("p");
    reason.textContent = reasons;
    content.appendChild(reason);
    if (item.scoreExplanation) {
      const calculation = document.createElement("p");
      const calculationLabel = document.createElement("strong");
      calculationLabel.textContent = "Score calculation: ";
      calculation.append(calculationLabel, document.createTextNode(String(item.scoreExplanation)));
      content.appendChild(calculation);
    }
    info.append(infoButton, content);
    line.append(tags, title, date, info);
    const view = document.createElement("a");
    view.className = "dashboard-button dashboard-recommendation-view";
    view.textContent = "View";
    view.href = item.url || preservedURL(config.routes?.find || "FindMatch.htm").href;
    if (item.url) { view.target = "_blank"; view.rel = "noopener noreferrer"; }
    article.append(line, view);
    return article;
  }
  function ensureDedicatedMatchAssistant(filter = state.pendingAssistantFilter) {
    const normalized = ["next7", "priority"].includes(String(filter || "")) ? String(filter) : "";
    const username = viewed();
    if (!username) return null;
    let frame = state.assistantDedicated ? state.assistantFrame : null;
    if (frame && String(frame.dataset.username || "").toLowerCase() === username.toLowerCase()) {
      frame.dataset.dashboardFilter = normalized;
      if (state.assistantReady) frame.contentWindow?.postMessage({ type: "p2k-dashboard-apply-filter", filter: normalized }, window.location.origin);
      return frame;
    }
    if (state.assistantDedicated && state.assistantFrame) state.assistantFrame.remove();
    frame = document.createElement("iframe");
    frame.dataset.username = username;
    frame.dataset.dashboardFilter = normalized;
    frame.title = "Promote to King Match Assistant";
    frame.className = "dashboard-assistant-frame";
    frame.hidden = true;
    frame.setAttribute("aria-hidden", "true");
    frame.tabIndex = -1;
    const url = preservedURL(config.routes?.find || "FindMatch.htm");
    url.searchParams.delete("dashboardRecommendations");
    url.searchParams.set("dashboardAssistant", "1");
    url.searchParams.set("username", username);
    if (normalized) url.searchParams.set("dashboardFilter", normalized); else url.searchParams.delete("dashboardFilter");
    url.hash = "";
    frame.src = url.href;
    state.assistantFrame = frame;
    state.assistantDedicated = true;
    state.assistantReady = false;
    state.assistantFullReady = false;
    byId("dashboardMatchAssistantHost")?.appendChild(frame);
    stopAssistantTimer();
    state.assistantTimer = window.setTimeout(() => {
      if (!state.assistantDedicated || state.assistantFrame !== frame || state.assistantReady) return;
      const preparing = byId("dashboardMatchAssistantPreparing");
      if (preparing && !preparing.hidden && state.assistantOpen) preparing.textContent = "The Match Assistant is still loading. You can close this panel and retry without losing Dashboard state.";
    }, 30000);
    return frame;
  }

  function promoteMatchAssistantFrame(frame = state.assistantFrame) {
    if (!frame) return;
    frame.classList.remove("dashboard-recommendation-engine");
    frame.classList.add("dashboard-assistant-frame");
    frame.title = "Promote to King Match Assistant";
  }
  function syncMatchAssistantLoadingState() {
    // v2.10.6.10: one authority for assistant/loader visibility. A ready,
    // visible assistant must never coexist with a stale preparation layer.
    const panel = byId("dashboardMatchAssistant");
    const frame = state.assistantFrame;
    const assistantVisible = !!(state.assistantOpen && panel && !panel.hidden);
    const frameVisible = !!(assistantVisible && state.assistantFullReady && frame && !frame.hidden);
    const preparing = byId("dashboardMatchAssistantPreparing");
    if (preparing) {
      preparing.hidden = !assistantVisible || frameVisible;
      if (preparing.hidden) preparing.textContent = "Preparing the full Match Assistant…";
    }
    const recommendationsLoading = byId("recommendationsLoading");
    if (recommendationsLoading && assistantVisible) recommendationsLoading.hidden = true;
  }
  function revealMatchAssistantFrame(frame = state.assistantFrame) {
    if (!frame || !state.assistantOpen || !state.assistantFullReady) return;
    const card=byId("recommendationsCard");
    state.recommendationCardHeight=Math.max(card?.getBoundingClientRect().height||0,1);
    card?.classList.add("dashboard-no-transition");
    if(card)card.style.minHeight=`${Math.ceil(state.recommendationCardHeight)}px`;
    promoteMatchAssistantFrame(frame);
    if(byId("recommendationsDefaultView"))byId("recommendationsDefaultView").hidden=true;
    if(byId("dashboardMatchAssistant"))byId("dashboardMatchAssistant").hidden=false;
    card?.classList.add("is-assistant-open");
    frame.removeAttribute("hidden");frame.hidden=false;frame.removeAttribute("aria-hidden");frame.tabIndex=0;
    resizeRecommendationFrame(frame);syncMatchAssistantLoadingState();
    requestAnimationFrame(()=>requestAnimationFrame(()=>{card?.classList.remove("dashboard-no-transition");if(card)card.style.minHeight="";}));
  }
  function handleAdminEmbeddedNavigation(event) {
    if(event.origin!==window.location.origin||state.view!=="admin"||!state.adminDetail)return false;
    if(event.data?.type!=="p2k-embedded-tab-change")return false;
    const frame=byId("adminShellDetailFrame");if(!frame||event.source!==frame.contentWindow)return true;
    const tab=String(event.data.tab||"").toLowerCase();if(!tab)return true;
    const def=adminDetailDefinition();
    if(def?.tabs?.some(item=>item.key===tab)){state.adminDetailTab=tab;state.adminToolTab="";byId("adminShellDetailTabs")?.querySelectorAll("[data-admin-detail-tab]").forEach(link=>{const active=link.dataset.adminDetailTab===tab;link.classList.toggle("is-active",active);if(active)link.setAttribute("aria-current","page");else link.removeAttribute("aria-current");});}
    else state.adminToolTab=tab;
    writeNavigationState();return true;
  }
  function handleRecommendationMessage(event) {
    if (handleAdminEmbeddedNavigation(event)) return;
    if (event.origin !== window.location.origin) return;
    const expectedUsername = viewed().toLowerCase();
    if (event.data?.type === "p2k-dashboard-full-assistant-ready") {
      const frame = state.assistantFrame;
      if (!frame || event.source !== frame.contentWindow) return;
      if (String(event.data.username || "").trim().toLowerCase() !== expectedUsername) return;
      state.assistantFullReady = true;
      revealMatchAssistantFrame(frame);
      if (state.pendingAssistantFilter) frame.contentWindow?.postMessage({ type: "p2k-dashboard-apply-filter", filter: state.pendingAssistantFilter }, window.location.origin);
      return;
    }
    if (event.data?.type === "p2k-dashboard-assistant-ready") {
      const frame = state.assistantFrame;
      if (!frame || event.source !== frame.contentWindow) return;
      if (String(event.data.username || "").trim().toLowerCase() !== expectedUsername) return;
      stopAssistantTimer();
      state.assistantReady = true;
      state.assistantFullReady = false;
      prepareEmbeddedMatchAssistant(frame);
      frame.hidden = true;
      frame.setAttribute("aria-hidden", "true");
      frame.tabIndex = -1;
      const button = byId("findMoreMatchesLink");
      if (button) { button.disabled = false; delete button.dataset.fallbackHref; }
      if (state.assistantOpen && state.publicPage === "dashboard") {
        frame.contentWindow?.postMessage({ type: "p2k-dashboard-show-full-assistant" }, window.location.origin);
        syncMatchAssistantLoadingState();
      }
      return;
    }
    if (!state.recommendationReady && event.data?.type === "p2k-dashboard-recommendations-progress" && event.source === state.recommendationFrame?.contentWindow) {
      if (String(event.data.username || "").trim().toLowerCase() !== expectedUsername) return;
      const message = String(event.data.message || "Analyzing recommended matches…");
      setText("recommendationsSubtitle", message);
      return;
    }
    if (event.data?.type === "p2k-match-finder-height") {
      if (event.source === state.assistantFrame?.contentWindow) resizeRecommendationFrame(state.assistantFrame, event.data.height);
      return;
    }
    if (event.data?.type !== "p2k-dashboard-recommendations") return;
    const frame = state.recommendationFrame;
    if (!frame || event.source !== frame.contentWindow) return;
    if (String(event.data.username || "").trim().toLowerCase() !== expectedUsername) return;
    const cached=!!event.data.cached,terminal=!!event.data.terminal,warning=String(event.data.warning||"").trim();
    const recommendations = Array.isArray(event.data.recommendations) ? event.data.recommendations.slice(0, 5) : [];
    if (cached && recommendations.length) {
      state.recommendationFallbackVisible = true;
      state.adminPriorityData = event.data.adminQueue || state.adminPriorityData || null;
      state.adminPriorityError = "";
      state.adminPriorityFailures = Math.max(0, Number(event.data.failedCount) || 0);
      renderAdminPriorityCard();
      renderTeamIndicators(event.data.teamIndicators || null);
      const loading = byId("recommendationsLoading");
      if (loading) loading.hidden = terminal;
      const list = byId("recommendationsList");
      list?.replaceChildren(...recommendations.map(recommendationCard));
      const empty = byId("recommendationsEmpty");
      if (empty) empty.hidden = true;
      setText("recommendationsSubtitle", warning || "Showing cached recommendations; refreshing live data.");
      if (!terminal) return;
      stopRecommendationTimer();
      state.recommendationReady = true;
      if (!state.assistantDedicated) {
        state.assistantFrame = frame;
        state.assistantReady = true;
        state.assistantFullReady = false;
      }
      if (!state.assistantDedicated) {
        prepareEmbeddedMatchAssistant(frame);
        frame.hidden = true;
        frame.setAttribute("aria-hidden", "true");
        frame.tabIndex = -1;
      }
      const cachedButton = byId("findMoreMatchesLink");
      if (cachedButton) { cachedButton.disabled = false; delete cachedButton.dataset.fallbackHref; }
      // v2.10.6: opening intent survives recommendation hydration. Re-enter the
      // assistant opener once the cached terminal frame is promotable; it will
      // request full-assistant mode and keep the pending next7/priority filter.
      if (state.assistantOpen && state.publicPage === "dashboard") openMatchAssistant({ updateHistory: false });
      return;
    }
    if (event.data.error) {
      stopRecommendationTimer();
      recommendationError(event.data.error, { preserveExisting: state.recommendationFallbackVisible });
      return;
    }
    stopRecommendationTimer();
    state.recommendationFallbackVisible = false;
    state.recommendationReady = true;
    if (!state.assistantDedicated) {
      state.assistantFrame = frame;
      state.assistantReady = true;
      state.assistantFullReady = false;
    }
    if (!state.assistantDedicated) {
      prepareEmbeddedMatchAssistant(frame);
      frame.hidden = true;
      frame.setAttribute("aria-hidden", "true");
      frame.tabIndex = -1;
    }
    const findMoreButton = byId("findMoreMatchesLink");
    if (findMoreButton) { findMoreButton.disabled = false; delete findMoreButton.dataset.fallbackHref; }
    const loading = byId("recommendationsLoading");
    if (loading) loading.hidden = true;
    state.adminPriorityData = event.data.adminQueue || null;
    state.adminPriorityError = "";
    state.adminPriorityFailures = Math.max(0, Number(event.data.failedCount) || 0);
    renderAdminPriorityCard();
    renderTeamIndicators(event.data.teamIndicators || null);
    if (!recommendations.length) {
      recommendationError("The Match Assistant found no match matching its default recommended, unregistered and rating-eligible filters.");
      return;
    }
    const list = byId("recommendationsList");
    list?.replaceChildren(...recommendations.map(recommendationCard));
    const empty = byId("recommendationsEmpty");
    if (empty) empty.hidden = true;
    setText("recommendationsSubtitle", `${recommendations.length} personalized recommendation${recommendations.length === 1 ? "" : "s"} for ${viewed()}.`);
    // v2.10.6: do not let the recommendation terminal render hide an already
    // requested Match Assistant. The opener is authoritative and will reveal
    // the iframe as soon as full-assistant-ready arrives.
    if (state.assistantOpen && state.publicPage === "dashboard") openMatchAssistant({ updateHistory: false });
  }
  function openMatchAssistant({ updateHistory = true } = {}) {
    const button=byId("findMoreMatchesLink");
    state.assistantOpen=true;
    state.recommendationReturnScroll={x:window.scrollX,y:window.scrollY};
    const frame=ensureDedicatedMatchAssistant(state.pendingAssistantFilter);
    if(!frame){
      if(button?.dataset.fallbackHref&&!state.pendingAssistantFilter){window.location.href=button.dataset.fallbackHref;return;}
      if(updateHistory)writeNavigationState();return;
    }
    if(state.assistantFullReady)revealMatchAssistantFrame(frame);
    else if(state.assistantReady)frame.contentWindow?.postMessage({type:"p2k-dashboard-show-full-assistant"},window.location.origin);
    syncMatchAssistantLoadingState();
    if(updateHistory)writeNavigationState();
  }
  function openMatchAssistantWithFilter(filter, { updateHistory = true } = {}) {
    const normalized=["next7","priority"].includes(String(filter||""))?String(filter):"";
    state.pendingAssistantFilter = normalized;
    ensureDedicatedMatchAssistant(normalized);
    openMatchAssistant({ updateHistory });
  }
  function closeMatchAssistant({ updateHistory = true } = {}) {
    const card = byId("recommendationsCard");
    const frame = state.assistantFrame;
    card?.classList.add("dashboard-no-transition");
    if (card) card.style.minHeight = `${Math.ceil(Math.max(state.recommendationCardHeight, card.getBoundingClientRect().height))}px`;
    if (frame) {
      frame.hidden = true;
      frame.setAttribute("aria-hidden", "true");
      frame.tabIndex = -1;
    }
    byId("dashboardMatchAssistant").hidden = true;
    byId("recommendationsDefaultView").hidden = false;
    byId("recommendationsCard").classList.remove("is-assistant-open");
    state.assistantOpen = false;
    syncMatchAssistantLoadingState();
    if (updateHistory) writeNavigationState();
    const position = state.recommendationReturnScroll;
    if (position) window.scrollTo(position.x, position.y);
    requestAnimationFrame(() => requestAnimationFrame(() => {
      card?.classList.remove("dashboard-no-transition");
      if (card) card.style.minHeight = "";
      if (position) window.scrollTo(position.x, position.y);
    }));
  }
  let dashboardHallModulePromise = null;
  function dashboardHallContext() {
    return { state, byId, number, escapeHTML, openUnifiedPlayerProfile, setText, loadJSON, writeNavigationState, rankThumbnailAsset, originalRankAsset, adminEntryUsername, selectHallSubtab, openAchievementCatalog, loadPublicCachedJSON, showPublicPage };
  }
  function ensureDashboardHallModule() {
    if (window.P2K_DASHBOARD_HALL) return Promise.resolve(window.P2K_DASHBOARD_HALL);
    if (dashboardHallModulePromise) return dashboardHallModulePromise;
    dashboardHallModulePromise = loadFeatureScriptWithRetry(
      "assets/js/pages/dashboard-hall.js?v=2.10.4",
      () => typeof window.P2K_CREATE_DASHBOARD_HALL === "function",
      "Dashboard Hall module"
    ).then(() => {
      const factory = window.P2K_CREATE_DASHBOARD_HALL;
      window.P2K_DASHBOARD_HALL = factory(dashboardHallContext());
      return window.P2K_DASHBOARD_HALL;
    }).catch(error => { dashboardHallModulePromise = null; throw error; });
    return dashboardHallModulePromise;
  }
  function renderHallSummary(hall) { ensureDashboardHallModule().then(api => api.renderHallSummary(hall)).catch(error => console.warn(error)); }
  function renderHallRanks(hall) { ensureDashboardHallModule().then(api => api.renderHallRanks(hall)).catch(error => console.warn(error)); }
  function renderLiveRanksNative(payload) { ensureDashboardHallModule().then(api => api.renderLiveRanksNative(payload)).catch(error => console.warn(error)); }
  async function loadLiveRanksNative(options = {}) { return (await ensureDashboardHallModule()).loadLiveRanksNative(options); }
  async function searchHallUnified(username) {
    return (await ensureDashboardHallModule()).searchHallUnified(username);
  }
  async function loadHall(options = {}) { return (await ensureDashboardHallModule()).loadHall(options); }
  async function openHallOfFame(button) { return (await ensureDashboardHallModule()).openHallOfFame(button); }
  function resetHallRanks() { ensureDashboardHallModule().then(api => api.resetHallRanks()).catch(error => console.warn(error)); }
function matchListLabel(status) {
    return ({ registered: "Registered matches", ongoing: "Ongoing matches", finished: "Finished matches" })[status] || "Matches";
  }
  function matchURL(match) {
    return String(match?.url || match?.["@id"] || "").replace("https://api.chess.com/pub/match/", "https://www.chess.com/club/matches/");
}
function renderDashboardMatchListRows(list) {
const host = byId("dashboardMatchList");
host.replaceChildren();
if (!Array.isArray(list) || !list.length) {
const empty = document.createElement("div");
empty.className = "dashboard-empty";
empty.textContent = "No matches are available in this list.";
host.appendChild(empty);
return;
}
list.forEach(match => {
const row = document.createElement("article");
row.className = "dashboard-match-list-row";
const copy = document.createElement("div");
const title = document.createElement("strong");
title.textContent = match?.name || match?.title || "Team match";
const meta = document.createElement("small");
const boards = matchBoardCount(match);
meta.textContent = `${formatDateOnly(match?.end_time || match?.start_time)} · ${number(boards)} boards · ${number(boards * 2)} games`;
copy.append(title, meta);
const url = matchURL(match);
if (url) {
const link = document.createElement("a");
link.className = "dashboard-button dashboard-button-muted";
link.href = url;
link.target = "_blank";
link.rel = "noopener noreferrer";
link.textContent = "Open";
row.append(copy, link);
} else row.append(copy);
host.appendChild(row);
});
}
async function openDashboardMatchList(status) {
setText("dashboardMatchListTitle", matchListLabel(status));
byId("dashboardMatchListModal").hidden = false;
document.body.classList.add("dashboard-modal-open");
if (status === "finished") {
setText("dashboardMatchListSummary", "Loading the latest finished matches from the complete archive…");
const host = byId("dashboardMatchList");
host.replaceChildren();
const loading = document.createElement("div");
loading.className = "dashboard-empty";
loading.textContent = "Loading finished matches…";
host.appendChild(loading);
try {
const payload = await (window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("dashboard-matches", { status: "finished", limit: 25, _fresh: Date.now() }) || Promise.reject(new Error("Team Points client unavailable")));
const rows = Array.isArray(payload?.matches?.rows) ? payload.matches.rows : [];
const totalRows = Number(payload?.matches?.total_rows);
const totals = matchListTotals(rows);
setText("dashboardMatchListSummary", `${number(Number.isFinite(totalRows) ? totalRows : rows.length)} total matches · showing latest ${number(rows.length)} · ${number(totals.boards)} boards in this page`);
renderDashboardMatchListRows(rows);
return;
} catch (error) {
console.warn("Unable to load canonical finished-match page", error);
const recent = state.teamData?.lists?.finished || [];
const totals = matchListTotals(recent);
setText("dashboardMatchListTitle", "Recent finished matches");
setText("dashboardMatchListSummary", `Archive list unavailable · showing ${number(recent.length)} recent Chess.com matches · ${number(totals.boards)} boards`);
renderDashboardMatchListRows(recent);
return;
}
}
const list = state.teamData?.lists?.[status] || [];
const totals = matchListTotals(list);
setText("dashboardMatchListSummary", `${number(list.length)} matches · ${number(totals.boards)} boards · ${number(totals.games)} games`);
renderDashboardMatchListRows(list);
}
function closeDashboardMatchList() {
byId("dashboardMatchListModal").hidden = true;
document.body.classList.remove("dashboard-modal-open");
}
const tools = [
{ category: "matches", icon: "♟", title: "Match Assistant", description: "Find suitable open matches with the existing smart filters and detailed analysis.", route: "find" },
{ category: "matches", icon: "◷", title: "Upcoming Matches", description: "Analyze registration, lineups, recruitment needs and player availability.", route: "upcoming" },
{ category: "matches", icon: "+", title: "Match Creation", description: "Review match creation activity, opponents, scoring and player participation.", route: "creation" },
{ category: "matches", icon: "◎", title: "Open Match Analyzer", description: "Analyze one match, compare teams and review projections.", route: "open" },
{ category: "matches", icon: "↗", title: "Recruitment Assistant", description: "Prepare targeted recruitment messages for selected matches with availability/load context and an explainable recruitment-confidence score.", route: "recruit" },
{ category: "matches", icon: "%", title: "Recruitment confidence", description: "Use strength, lineup coverage, rating freshness and member availability to explain confidence in each recruitment recommendation.", route: "recruit" },
{ category: "matches", icon: "▦", title: "Recruitment demand planner", description: "Combine upcoming registrations into one rating-range demand plan with risk and win-probability indicators.", path: "RecruitmentDemandPlanner.html" },
{ category: "players", icon: "★", title: "Team Points", description: "Seed, incrementally refresh, repair, monitor, search and export durable player and club point totals.", route: "teamPoints" },
{ category: "players", icon: "✓", title: "Database consistency", description: "Run resumable arithmetic, former-member or full audits and repair confirmed issues in small rollback-backed batches.", adminTool: "reconciliation" },
{ category: "players", icon: "◫", title: "Storage & capacity", description: "Monitor Core and Analytics database size, filesystem cache usage, monthly growth and projected 80% / 100% capacity dates.", adminTool: "storage" },
{ category: "players", icon: "▥", title: "Team depth visualization", description: "Compare active, available and overloaded member depth across 100-point Daily and Chess960 rating bands.", adminTool: "intelligence", adminContext: "depth" },
{ category: "players", icon: "◉", title: "Member activity & availability", description: "Monitor active/cooling/inactive members, current load, availability score and contribution efficiency.", adminTool: "intelligence", adminContext: "members" },
{ category: "players", icon: "Σ", title: "Member contribution profiles", description: "Review Club Points share, points per game, win rate, activity and availability through the shared member-intelligence projection and unified profile.", adminTool: "intelligence", adminContext: "members" },
{ category: "players", icon: "🏅", title: "Achievement challenges", description: "Track each member’s nearest unearned Daily, Live and MCA milestones from the unified profile and personalized home.", adminTool: "intelligence", adminContext: "members" },
{ category: "players", icon: "♙", title: "Unified player intelligence", description: "Use shared player data across Hall, Achievements, Insights and Recruitment. Operational activity, availability and contribution intelligence remains in Administration; player-facing profiles keep achievements and challenges.", adminTool: "intelligence", adminContext: "members" },
{ category: "players", icon: "⌁", title: "Historical snapshots / time travel", description: "Review compact daily historical snapshots of members, points, matches, boards, activity and rating coverage.", adminTool: "intelligence", adminContext: "snapshots" },
{ category: "players", icon: "♜", title: "Live ranks computation", description: "Upload CSV source files for MCAs, process scores and review possible renamed accounts.", adminTool: "live-ranks" },
{ category: "opponents", icon: "↻", title: "Opponent maintenance", description: "Refresh club names, detect renamed or disabled opponents and review opponent intelligence in the integrated Administration context.", adminTool: "intelligence", adminContext: "opponents" },
{ category: "opponents", icon: "◇", title: "Opponent intelligence profiles", description: "Review recurring opponent frequency, outcomes, current overlap, recency and historical win rates.", adminTool: "intelligence", adminContext: "opponents" },
{ category: "team", icon: "♛", title: "Tournament management", description: "Refresh, reinitialize and validate the tournament and podium archive.", path: "TournamentManagement.html" },
{ category: "team", icon: "▤", title: "League and season centre", description: "Review database-backed league records, seasons, Club Points, boards and opponent history.", path: "LeagueSeasonCenter.html" },
{ category: "team", icon: "☰", title: "Administration center", description: "Open the classic interface for logs, diagnostics and tracked-match management.", classic: true },
{ category: "opponents", icon: "⇄", title: "Challenge Assistant", description: "Check opponent clubs and prepare challenge or rematch opportunities.", route: "challenges" },
{ category: "monitoring", icon: "⌘", title: "Scheduled task control", description: "Start, resume, pause and inspect every CRON-compatible task through the shared gateway and unified task registry.", adminTool: "tasks" },
{ category: "monitoring", icon: "≡", title: "Task logs", description: "Review the retained match, database and tournament scheduled-task execution log.", adminTool: "logs" },
{ category: "monitoring", icon: "⚙", title: "Runtime diagnostics", description: "Open API, cache, scheduler and browser diagnostics.", adminTool: "diagnostics" },
{ category: "monitoring", icon: "♥", title: "Insights API health", description: "Verify database provenance, coverage, row counts and last refresh for all Insights domains.", adminTool: "diagnostics" },
{ category: "monitoring", icon: "◌", title: "Data freshness & coverage", description: "See roster, rating, avatar, board and worker-queue freshness in one operational view.", adminTool: "intelligence", adminContext: "freshness" },
{ category: "monitoring", icon: "!", title: "Anomaly detector & action queue", description: "Surface stale or inconsistent data automatically and route administrators to the appropriate corrective tool.", adminTool: "intelligence", adminContext: "anomalies" },
{ category: "monitoring", icon: "→", title: "Admin action queue", description: "Turn detected freshness, board, queue and consistency anomalies into direct links to the relevant corrective administration tool.", adminTool: "intelligence", adminContext: "anomalies" },
{ category: "monitoring", icon: "⌬", title: "Performance telemetry", description: "Review endpoint call volume, errors, p50/p95/max latency and peak memory from protected runtime telemetry.", adminTool: "intelligence", adminContext: "performance" },
{ category: "monitoring", icon: "↻", title: "ACAMR effectiveness", description: "Measure authenticated client-assisted refresh activity, claims, distinct sessions/members and authoritative work yield.", adminTool: "intelligence", adminContext: "acamr" },
{ category: "team", icon: "⌁", title: "Explainable Club Points forecast", description: "Review low/medium/high year-end forecasts with recent-rate and trend drivers exposed.", adminTool: "intelligence", adminContext: "forecast" },
{ category: "team", icon: "⌂", title: "Personalized authenticated home", description: "Preview the authenticated Dashboard member experience with club/rank context and achievement challenges; operational Member Intelligence remains admin/internal only.", path: "ui-v2.html" },
{ category: "monitoring", icon: "⌁", title: "Tracked match data", description: "Use the classic Administration center to manage snapshots and history.", classic: true },
{ category: "more", icon: "♔", title: "Classic administrator tabs", description: "Return to the classic interface and its original tab workflow.", classic: true }
];
const categoryLabels = { matches: "Matches", players: "Players", team: "Team", opponents: "Opponents", monitoring: "Monitoring", more: "More" };
function renderTools() {
const host = byId("adminToolGrid");
if (!host) return;
host.replaceChildren();
tools.forEach(tool => {
const card = document.createElement("article"); card.className = "dashboard-tool-card";
const head = document.createElement("div"); head.className = "dashboard-tool-head";
const icon = document.createElement("span"); icon.className = "dashboard-tool-icon"; icon.textContent = tool.icon;
const status = document.createElement("span"); status.className = "dashboard-tool-status"; status.textContent = "Current tool";
head.append(icon, status);
const title = document.createElement("h3"); title.textContent = tool.title;
const description = document.createElement("p"); description.textContent = tool.description;
const foot = document.createElement("div"); foot.className = "dashboard-tool-foot";
const category = document.createElement("span"); category.className = "dashboard-tool-category"; category.textContent = categoryLabels[tool.category];
const link = document.createElement("a"); link.className = "dashboard-button"; link.textContent = "Open";
link.href = tool.adminTool ? integratedAdminHref(tool.adminTool, tool.adminContext ? { adminContext: tool.adminContext } : {}) : tool.path ? preservedURL(tool.path).href : tool.classic ? preservedURL("index.html", { classic: true }).href : preservedURL(config.routes?.[tool.route] || routeFallback(tool.route)).href;
foot.append(category, link); card.append(head, title, description, foot); host.appendChild(card);
});
}
function routeFallback(key) {
return ({ find: "FindMatch.htm", upcoming: "AnalyzeMatches.htm", creation: "MatchCreationAnalyzer.htm", open: "AnalyzeMatch.html", recruit: "RecruitMatch.html", challenges: "ChallengeListAssistant.html", teamPoints: "TeamPointsAdmin.html" })[key] || "index.html";
}
function bindControls() {
window.addEventListener("message", event => {
if (event.data?.type === "p2k-admin-status-request") { try { event.source?.postMessage({ type: "p2k-admin-ready", allowed: window.P2K_ADMIN_MODE === true }, "*"); } catch (_) {} return; }
if (event.origin !== window.location.origin) return;
if (event.data?.type === "p2k-team-insights-state" && byId("teamInsightsFrame")?.contentWindow === event.source) {
state.teamStart = String(event.data.start || "");
state.teamEnd = String(event.data.end || "");
writeNavigationState({ replace: true });
return;
}
if (event.data?.type === "p2k-embedded-tab-change" && byId("adminTasksFrame")?.contentWindow === event.source && state.publicPage === "administration" && state.adminSubtab === "tasks") {
const tab=String(event.data.tab||"").toLowerCase();if(["scheduled","green"].includes(tab)){state.adminToolTab=tab;writeNavigationState();}return;
}
if (event.data?.type === "p2k-open-green-accelerator" && byId("adminTasksFrame")?.contentWindow === event.source) {
state.publicPage="administration";state.adminSubtab="migration";state.adminContext="accelerator";renderView();writeNavigationState({replace:false});
const frame=byId("adminMigrationFrame");ensureIntegratedFrame("adminMigrationFrame");
window.setTimeout(()=>{try{frame?.contentWindow?.postMessage({type:"p2k-migration-accelerator",command:String(event.data.command||"open")},window.location.origin)}catch(_){ }},350);return;
}
if (event.data?.type === "p2k-open-player-profile") {
const trusted=[...document.querySelectorAll("iframe.dashboard-integrated-frame"),byId("achievementsFrame")].filter(Boolean).some(frame=>frame.contentWindow===event.source);
if(trusted){openUnifiedPlayerProfile(event.data.username);return;}
}
if (byId("achievementsFrame")?.contentWindow === event.source && event.data?.type === "p2k-open-achievement-catalog") {
openAchievementCatalog(event.data.username || "");
return;
}
if (event.data?.type !== "p2k-frame-height") return;
const frame = [byId("teamInsightsFrame"), byId("tournamentsFrame"), byId("achievementsFrame"), byId("adminTasksFrame"), byId("adminUpcomingFrame"), byId("adminLogsFrame"), byId("adminCreationFrame"), byId("adminChallengeFrame"), byId("adminOpenFrame"), byId("adminLiveRanksFrame"), byId("adminDiagnosticsFrame"), byId("adminStorageFrame"), byId("adminIntelligenceFrame"), byId("adminReconciliationFrame"), byId("adminMembersFrame"), byId("adminMigrationFrame"), byId("adminShellDetailFrame")].find(item => item?.contentWindow === event.source);
const maxHeight = frame?.id === "adminShellDetailFrame" ? 100000 : 12000;
const height = Math.max(360, Math.min(maxHeight, Number(event.data.height) || 0));
if (frame && height) frame.style.height = `${height}px`;
});
document.querySelectorAll("[data-public-page]").forEach(button => button.addEventListener("click", () => {
if (button.disabled) return;
const page=button.dataset.publicPage||"dashboard";
if(page==="administration"){
  if(!state.admin)return;
  state.view="admin";state.publicPage="dashboard";renderView();writeNavigationState();
  return;
}
if (page === "hall") openHallOfFame();
else showPublicPage(page);
}));
document.querySelectorAll("[data-insights-subtab]").forEach(button => button.addEventListener("click", () => {
if (!button.disabled) selectInsightsSubtab(button.dataset.insightsSubtab || "team");
}));
document.querySelectorAll("[data-hall-subtab]").forEach(button => button.addEventListener("click", () => {
if (!button.disabled) selectHallSubtab(button.dataset.hallSubtab || "daily");
}));
byId("findMoreMatchesLink").addEventListener("click", () => { state.pendingAssistantFilter = ""; if (state.assistantDedicated) state.assistantFrame?.contentWindow?.postMessage({ type: "p2k-dashboard-apply-filter", filter: "" }, window.location.origin); openMatchAssistant(); });
byId("openUnifiedProfile")?.addEventListener("click", () => { if (viewed()) openUnifiedPlayerProfile(viewed()); });
document.querySelectorAll("[data-team-insight]").forEach(card => {
const go=event=>{ if (event?.target?.closest?.("[data-match-list],.p2k-info-button")) return; showPublicPage("insights",{insightsSubtab:card.dataset.teamInsight||"team"}); };
card.addEventListener("click",go); card.addEventListener("keydown",event=>{if(event.key==="Enter"||event.key===" "){event.preventDefault();go(event);}});
});
document.querySelectorAll("[data-assistant-filter]").forEach(card=>card.addEventListener("click",()=>openMatchAssistantWithFilter(card.dataset.assistantFilter)));
document.querySelectorAll("[data-team-page='hall-live']").forEach(card => {
const go = () => { openHallOfFame(); selectHallSubtab("live"); };
card.addEventListener("click", go);
card.addEventListener("keydown", event => { if (event.key === "Enter" || event.key === " ") { event.preventDefault(); go(); } });
});
byId("backToRecommendations").addEventListener("click", closeMatchAssistant);
document.querySelectorAll("[data-match-list]").forEach(button => button.addEventListener("click", () => openDashboardMatchList(button.dataset.matchList)));
byId("closeDashboardMatchList").addEventListener("click", closeDashboardMatchList);
byId("dashboardMatchListModal").addEventListener("click", event => { if (event.target === byId("dashboardMatchListModal")) closeDashboardMatchList(); });
byId("explorePlayerGames").addEventListener("click", openPlayerGames);
byId("closePlayerGames").addEventListener("click", closePlayerGames);
byId("playerGamesModal").addEventListener("click", event => { if (event.target === byId("playerGamesModal")) closePlayerGames(); });
byId("openHallOfFame").addEventListener("click", () => openHallOfFame({ focusCurrentMember: true }));
document.querySelectorAll("[data-player-mode]").forEach(button => button.addEventListener("click", () => selectPersonalMode(button.dataset.playerMode)));
byId("hallMemberSearchForm").addEventListener("submit", event => {
event.preventDefault();
const member = byId("hallMemberSearch").value.trim();
if (member) searchHallUnified(member);
});
byId("hallMemberSearch")?.addEventListener("input", event => {
if (String(event.target?.value || "").trim() === "" && state.hallSearch) resetHallRanks();
});
byId("hallResetSearch")?.addEventListener("click", resetHallRanks);
byId("insightsDetailClose")?.addEventListener("click", closeInsightsModal);
byId("insightsDetailModal")?.addEventListener("click", event => { if (event.target === byId("insightsDetailModal")) closeInsightsModal(); });
document.addEventListener("keydown", event => {
if (event.key !== "Escape") return;
if (!byId("insightsDetailModal")?.hidden) closeInsightsModal();
else if (!byId("playerGamesModal").hidden) closePlayerGames();
else if (!byId("dashboardMatchListModal").hidden) closeDashboardMatchList();
});
}
function configureAcsr() {
const acsr = window.P2K_ACSR;
if (!acsr?.register) return;
acsr.register("dashboard-team", {
minIntervalMs: 15000, convergedIntervalMs: 90000, staleAfterMs: 45000,
refresh: async () => { await loadTeamData(); return { converged: true, nextRefreshMs: 90000 }; }
});
acsr.register("dashboard-personal", {
minIntervalMs: 15000, convergedIntervalMs: 90000, staleAfterMs: 45000,
refresh: async () => {
const username = viewed();
if (!username) return { converged: true, nextRefreshMs: 120000 };
await loadPersonalData(username);
return { converged: true, nextRefreshMs: 90000 };
}
});
acsr.register("dashboard-hall-active", {
minIntervalMs: 15000, convergedIntervalMs: 120000, staleAfterMs: 60000,
refresh: async () => {
if (state.publicPage !== "hall") return { converged: true, nextRefreshMs: 120000 };
if (state.hallSubtab === "daily") await loadHall({ force: true });
else if (state.hallSubtab === "live") await loadLiveRanksNative({ force: true });
return { converged: true, nextRefreshMs: 120000 };
}
});
}
function initialize() {
applyBranding();
bindControls();
renderView();
if (state.view === "public" && state.publicPage !== "administration") showPublicPage(state.publicPage, { hallSubtab: state.hallSubtab, insightsSubtab: state.insightsSubtab, updateHistory: false });
writeNavigationState({ replace: true });
loadTeamData();
applySession(currentSession());
configureAcsr();
window.addEventListener("p2k-auth-change", event => applySession(event.detail || null));
window.addEventListener("message", handleRecommendationMessage);
window.addEventListener("keydown",event=>{const modal=byId("insightsDetailModal");if(!modal?.hidden&&achievementDetailNav&&(event.key==="ArrowLeft"||event.key==="ArrowRight")){const n=achievementDetailNav.index+(event.key==="ArrowLeft"?-1:1);if(n>=0&&n<achievementDetailNav.items.length){event.preventDefault();openAchievementNav(n,true)}}});
window.addEventListener("popstate", () => {
const navigation = navigationFromURL();
state.view = navigation.view;
state.publicPage = navigation.publicPage;
state.insightsSubtab = navigation.insightsSubtab;
state.hallSubtab = navigation.hallSubtab;
state.adminSubtab = navigation.adminSubtab || "upcoming";
state.adminContext = navigation.adminContext || "";
state.category = navigation.category || "competitions";
state.adminDetail = navigation.adminDetail || "";
state.adminDetailTab = navigation.adminDetailTab || "";
state.adminToolTab = navigation.adminToolTab || "";
state.hallSearch = navigation.hallSearch || "";
state.hallRank = navigation.hallRank || "";
state.liveRank = navigation.liveRank || "";
state.teamStart = navigation.teamStart || "";
state.teamEnd = navigation.teamEnd || "";
state.personalMode = navigation.personalMode || "daily";
if (viewed()) renderPersonalCard();
const shouldOpenAssistant = Boolean(navigation.assistantOpen);
state.pendingAssistantFilter = navigation.assistantFilter || "";
if (shouldOpenAssistant && state.pendingAssistantFilter) {
  openMatchAssistantWithFilter(state.pendingAssistantFilter, { updateHistory: false });
} else if (shouldOpenAssistant && !state.assistantOpen) {
  state.assistantOpen = true; openMatchAssistant({ updateHistory: false });
} else if (!shouldOpenAssistant && state.assistantOpen) closeMatchAssistant({ updateHistory: false });
state.opponentsTableState = navigation.opponentsTable;
state.matchesTableState = navigation.matchesTable;
state.membersTableState = navigation.membersTable;
state.opponentsTable?.setState(state.opponentsTableState);
state.matchesTable?.setState(state.matchesTableState);
state.membersTable?.setState(state.membersTableState);
if (state.admin) ensureAdminInterface();
const adminHost = byId("adminDashboardHost");
adminHost?.querySelectorAll("[data-admin-category]").forEach(button => button.setAttribute("aria-pressed", String(button.dataset.adminCategory === state.category)));
adminShellActivate();
if(state.category === "misc")renderTools();
if (state.view === "public") showPublicPage(state.publicPage, { hallSubtab: state.hallSubtab, insightsSubtab: state.insightsSubtab, updateHistory: false }); else renderView();
});
}
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initialize, { once: true });
else initialize();
document.querySelectorAll("[data-admin-group]").forEach(button => button.addEventListener("click",()=>{
if(!state.admin)return;
const firstByGroup={daily:"upcoming",live:"live-ranks",tools:"tasks"};
state.adminSubtab=firstByGroup[button.dataset.adminGroup]||"upcoming"; state.adminContext="";
renderView(); writeNavigationState({replace:false});
}));
document.querySelectorAll("[data-admin-subtab]").forEach(button => button.addEventListener("click",()=>{
if(!state.admin)return; state.adminSubtab=button.dataset.adminSubtab||"upcoming"; if(state.adminSubtab!=="intelligence")state.adminContext="";
renderView(); writeNavigationState({replace:false});
}));
document.addEventListener("click", event => {
const button = event.target.closest?.("#insightsDetailBody [data-achievement-key]");
if (!button) return;
const key = String(button.dataset.achievementKey || "");
const item = window.P2K_ACHIEVEMENT_DETAIL_CACHE?.get(key);
if (item) openAchievementDetail(item, button.closest(".is-earned") !== null);
});
})();
