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
const { adminEntryUsername, clubAdminUsernames, ensureAdminPriorityCard, removeAdminPriorityCard, integratedAdminHref, adminPriorityActionHref, adminPriorityHealthRow, adminMatchListHtml, openAdminFreshMatchDetail, openAdminMetricModal, renderAdminPriorityCard, loadAdminPriorityHealth, renderAdminApiThroughput, stopAdminApiThroughput, scheduleAdminApiThroughput, loadAdminApiThroughput, configuredAdminUsernames, oauthSessionClaimsAdmin, validLocalAdminMarker, verifyAdmin } = window.P2K_DASHBOARD_MODULES.adminSession.create({
state, byId, escapeHTML: value => escapeHTML(value), number, setText, showToast, config, clubSlug, clubProfileAPI,
loadJSON, setAdmin, renderView, writeNavigationState,
adminShellOpenDetail: (...args) => adminShellOpenDetail(...args),
adminShellHref: (...args) => adminShellHref(...args)
});
const { adminDetailDefinition, adminShellHref, adminShellCard, adminMemberLookupCard, adminMemberLookupUtc, adminMemberLookupEventLabel, renderAdminMemberLookup, loadAdminMemberLookup, adminPanelMarkup, adminShellSet, adminShellAge, adminShellStatus, adminShellNumber, adminShellPercent, adminShellOpenDetail, adminShellCloseDetail, renderAdminShellDetail, adminShellActivate, adminShellJSON, loadAdminShellMetrics } = window.P2K_DASHBOARD_MODULES.adminShell.create({
state, byId, escapeHTML: value => escapeHTML(value), number, setText, applyOAuthContext,
setIntegratedFrameActivity: (...args) => setIntegratedFrameActivity(...args),
ensureIntegratedFrame: (...args) => ensureIntegratedFrame(...args), writeNavigationState
});
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
  const { insightAction, showInsightsModal, closeInsightsModal, normalizeTournamentName, tournamentAchievements, profileMetric, tournamentAchievementKeys, achievementCard, achievementEarnedDetail, achievementWebURL, openAchievementDetail, openAchievementNav, achievementDisplayBar, achievementCategoryLabel, achievementFamilyLabel, openAchievementCatalog, openUnifiedPlayerProfile, membersTableColumns, loadFeatureScriptWithRetry, dashboardInsightsContext, ensureDashboardInsightsModule, loadMemberInsights, loadMatchInsights, loadOpponentInsights, loadArenaInsights, openMatchDetail, renderNativeBarLine, getAchievementNavigation } = window.P2K_DASHBOARD_MODULES.insights.create({
    state, byId, escapeHTML, number, setText, nativeLink, cellWithSub, statusChip,
    loadPublicCachedJSON, writeNavigationState, rankThumbnailAsset, originalRankAsset,
    adminEntryUsername, selectHallSubtab, showPublicPage, formatDateOnly
  });
const { integratedFrames, setIntegratedFrameActivity, ensureIntegratedFrame } = window.P2K_DASHBOARD_MODULES.embeddedHost.create({ state, byId, applyOAuthContext });
  const { currentSession, memberRecord, renderPersonalJoinDate, rankForPoints, setPersonalRankImage, loadPersonalizedHome, renderDailyPersonalCard, renderLivePersonalCard, shortArenaName, renderLiveTeamData, renderTeamMode, renderPersonalCard, selectPersonalMode, renderPlayerPoints, renderLivePlayer, playerMatchKey, uniquePlayerMatches, renderPlayerActivity, playerMatchResult, playerMatchTitle, renderPlayerGamesSection, openPlayerGames, closePlayerGames, applySession, loadPersonalData } = window.P2K_DASHBOARD_MODULES.personalHome.create({
    state, byId, setText, number, escapeHTML, viewed, adminEntryUsername,
    showPublicPage, selectHallSubtab, openAchievementCatalog, loadPublicCachedJSON,
    writeNavigationState, verifyAdmin,
    loadRecommendations: (...args) => loadRecommendations(...args),
    renderLiveRanksNative: (...args) => renderLiveRanksNative(...args),
    ranks, unrankedRank, rankThumbnailAsset, originalRankAsset
  });
  const { matchLists, matchBoardCount, matchListTotals, authoritativeMatchListTotals, hydrateDashboardMatchBoards, setMatchMetric, loadTeamData, renderGauge, renderTeamIndicators } = window.P2K_DASHBOARD_MODULES.teamSummary.create({
    state, byId, setText, number, loadJSON, loadPublicCachedJSON,
    renderTeamMode, renderPersonalJoinDate, renderLiveTeamData
  });
  const { stopRecommendationTimer, stopAssistantTimer, disposeRecommendationFrame, prepareEmbeddedMatchAssistant, resizeRecommendationFrame, recommendationError, loadRecommendations, displayRatingRange, recommendationCard, ensureDedicatedMatchAssistant, promoteMatchAssistantFrame, syncMatchAssistantLoadingState, revealMatchAssistantFrame, handleAdminEmbeddedNavigation, handleRecommendationMessage, openMatchAssistant, openMatchAssistantWithFilter, closeMatchAssistant } = window.P2K_DASHBOARD_MODULES.matchAssistant.create({
    state, byId, viewed, preservedURL, config, escapeHTML, number, setText,
    writeNavigationState, adminDetailDefinition, renderAdminPriorityCard
  });
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
const { matchListLabel, renderDashboardMatchListRows, openDashboardMatchList, closeDashboardMatchList } = window.P2K_DASHBOARD_MODULES.matchListDialog.create({ state, byId, matchBoardCount, matchListTotals, number });
const { renderTools, routeFallback } = window.P2K_DASHBOARD_MODULES.adminTools.create({ byId, integratedAdminHref, preservedURL, config });
const dashboardBootstrap = window.P2K_DASHBOARD_MODULES.dashboardBootstrap.create({
  state,
  byId,
  writeNavigationState,
  renderView,
  openUnifiedPlayerProfile,
  ensureIntegratedFrame,
  openAchievementCatalog,
  showPublicPage,
  selectInsightsSubtab,
  openHallOfFame,
  selectHallSubtab,
  openMatchAssistant,
  closeMatchAssistant,
  openMatchAssistantWithFilter,
  viewed,
  closeDashboardMatchList,
  openDashboardMatchList,
  openPlayerGames,
  closePlayerGames,
  selectPersonalMode,
  searchHallUnified,
  resetHallRanks,
  closeInsightsModal,
  loadTeamData,
  loadPersonalData,
  loadHall,
  loadLiveRanksNative,
  applyBranding,
  applySession,
  currentSession,
  handleRecommendationMessage,
  getAchievementNavigation,
  openAchievementNav,
  navigationFromURL,
  renderPersonalCard,
  ensureAdminInterface,
  adminShellActivate,
  renderTools,
  openAchievementDetail
});
})();
