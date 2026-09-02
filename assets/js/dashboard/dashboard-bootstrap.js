(function registerDashboardBootstrap(global) {
const modules = global.P2K_DASHBOARD_MODULES = global.P2K_DASHBOARD_MODULES || {};
modules.dashboardBootstrap = {
create(context) {
const {
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
} = context;
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
window.addEventListener("keydown",event=>{const modal=byId("insightsDetailModal");if(!modal?.hidden&&getAchievementNavigation()&&(event.key==="ArrowLeft"||event.key==="ArrowRight")){const n=getAchievementNavigation().index+(event.key==="ArrowLeft"?-1:1);if(n>=0&&n<getAchievementNavigation().items.length){event.preventDefault();openAchievementNav(n,true)}}});
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
return { bindControls, configureAcsr, initialize };
}
};
})(window);
