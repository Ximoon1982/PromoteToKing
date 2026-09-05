/* Existing Administration tool catalogue and links. */
(() => {
"use strict";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.adminTools = Object.freeze({create(context) {
const { byId, integratedAdminHref, preservedURL, config } = context;
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
window.P2K_TROPHY_GALLERY_POC?.mount?.(context);
}
function routeFallback(key) {
return ({ find: "FindMatch.htm", upcoming: "AnalyzeMatches.htm", creation: "MatchCreationAnalyzer.htm", open: "AnalyzeMatch.html", recruit: "RecruitMatch.html", challenges: "ChallengeListAssistant.html", teamPoints: "TeamPointsAdmin.html" })[key] || "index.html";
}
function loadTrophyGalleryPoc() {
if (window.P2K_TROPHY_GALLERY_POC) { window.P2K_TROPHY_GALLERY_POC.mount?.(context); return; }
if (document.getElementById("p2kTrophyGalleryPocScript")) return;
const script = document.createElement("script");
script.id = "p2kTrophyGalleryPocScript";
script.src = "assets/js/admin/trophy-gallery-poc.js?v=poc-dcd71c8e-1";
script.defer = true;
script.onload = () => window.P2K_TROPHY_GALLERY_POC?.mount?.(context);
document.head.appendChild(script);
}
loadTrophyGalleryPoc();

return Object.freeze({ tools, renderTools, routeFallback });
}});
})();
