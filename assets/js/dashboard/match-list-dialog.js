/* Existing dashboard match-list modal behavior. */
(() => {
"use strict";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.matchListDialog = Object.freeze({create(context) {
const { state, byId, matchBoardCount, matchListTotals, number, setText, formatDateOnly } = context;
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

return Object.freeze({ matchListLabel, renderDashboardMatchListRows, openDashboardMatchList, closeDashboardMatchList });
}});
})();
