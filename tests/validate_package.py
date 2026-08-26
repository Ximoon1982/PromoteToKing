#!/usr/bin/env python3
from __future__ import annotations
import hashlib, json, re, subprocess, sys
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[1]
EXPECTED_SITE_CSS_SHA256 = "a50e24fcc3111811328bc4e55030f39a7589ee88a0c00bfff443cc8fe70482eb"

class Parser(HTMLParser):
    def __init__(self):
        super().__init__(); self.ids=[]; self.refs=[]; self.inline_handlers=[]
    def handle_starttag(self, tag, attrs):
        values=dict(attrs)
        if values.get("id"): self.ids.append(values["id"])
        for name, value in attrs:
            if name in {"src","href"} and value: self.refs.append((tag,name,value))
            if name.lower().startswith("on"): self.inline_handlers.append((tag,name))

def check(condition, message):
    if not condition: raise AssertionError(message)

def local_ref(value: str):
    if value.startswith(("#","data:","mailto:","javascript:")): return None
    parts=urlsplit(value)
    if parts.scheme or parts.netloc: return None
    path=parts.path.lstrip("/")
    return path or None

def main():
    manifest=json.loads((ROOT/"site-manifest.json").read_text())
    declared_files=set(manifest.get("files") or [])
    image_ext={".png",".jpg",".jpeg",".gif",".webp",".svg",".ico"}
    check((ROOT/"VERSION").read_text().strip()=="2.10.6.23", "VERSION must be 2.10.6.23")
    check((ROOT/"MIGRATION_VERSION").read_text().strip()=="2.10.6.23", "MIGRATION_VERSION must be 2.10.6.23")
    digest=hashlib.sha256((ROOT/"assets/css/site.css").read_bytes()).hexdigest()
    check(digest==EXPECTED_SITE_CSS_SHA256, "site.css differs from the validated v2.2.6 shell")
    admin_css=(ROOT/"assets/css/admin-features.css").read_text()
    check(not re.search(r"(^|[}\s,])(body|html|\.site-shell|\.tool-content|\.tool-frame)\s*[{,]", admin_css, re.M), "Admin CSS overrides original shell selectors")

    all_ids=set(); html_files=list(ROOT.glob("*.htm"))+list(ROOT.glob("*.html"))
    # v2.10.6: every active first-party page must force a single cache generation.
    for file in html_files:
        text=file.read_text(encoding="utf-8")
        cache_versions=set(re.findall(r"\?v=(2\.10\.[0-9.]+)", text))
        check(cache_versions.issubset({"2.10.6","2.10.6.1","2.10.6.4","2.10.6.5","2.10.6.6","2.10.6.7","2.10.6.9","2.10.6.10","2.10.6.11","2.10.6.12","2.10.6.13","2.10.6.14","2.10.6.15","2.10.6.16","2.10.6.17","2.10.6.18","2.10.6.19","2.10.6.20","2.10.6.21","2.10.6.22","2.10.6.23"}), f"Unexpected active cache markers in {file.name}: {sorted(cache_versions)}")
    site_config=(ROOT/"assets/js/site-config.js").read_text(encoding="utf-8")
    check('version: "2.10.6.23"' in site_config, "Browser site-config release marker is not 2.10.6.23")
    check(f'builtAt: "{manifest.get("builtAt")}"' in site_config, "Browser site-config build timestamp is stale")
    site_cache_versions=set(re.findall(r"\?v=(2\.10\.[0-9.]+)", site_config))
    check(site_cache_versions.issubset({"2.10.6.23"}), f"site-config dynamic cache markers are stale: {sorted(site_cache_versions)}")
    for file in html_files:
        parser=Parser(); parser.feed(file.read_text(encoding="utf-8"))
        duplicates={x for x in parser.ids if parser.ids.count(x)>1}
        check(not duplicates, f"Duplicate IDs in {file.name}: {sorted(duplicates)}")
        if file.name=="index.html": all_ids=set(parser.ids)
        check(not parser.inline_handlers, f"Inline event handlers in {file.name}: {parser.inline_handlers[:3]}")
        for _,_,value in parser.refs:
            local=local_ref(value)
            if local: check((ROOT/local).is_file() or (local in declared_files and Path(local).suffix.lower() in image_ext), f"Missing local resource from {file.name}: {local}")

    admin_js=(ROOT/"assets/js/pages/admin-features.js").read_text()
    referenced=set(re.findall(r'byId\("([A-Za-z0-9_-]+)"\)', admin_js))
    missing=sorted(referenced-all_ids)
    check(not missing, f"Admin JS IDs missing from index.html: {missing}")
    check("Array.isArray(data?.entries) ? data.entries : []" in admin_js, "Scheduled log null guard missing")
    check("api/league-match-references/" in admin_js and "api/record-league-match/" in admin_js, "Optimized recording endpoints missing")
    check("managementRecordNow" in admin_js and "matchLogRefresh" in admin_js and "taskLogRefresh" in admin_js, "Recording or log refresh wiring missing")
    check("refreshActiveLogs()" in admin_js, "Logs are not refreshed on access")
    check("formatDateTime(row.timestamp)" in admin_js, "Scheduled task timestamps are not human readable")
    check(not (ROOT/"data/match-tracking/index.json").exists(), "Mutable match-tracking registry must not ship in a release package")
    check(not (ROOT/"data/tournaments/archive.json").exists(), "Mutable tournament archive must not ship in a release package")
    check(not (ROOT/"data/server-config.json").exists(), "Production shared secret config must not ship in a release package")
    check((ROOT/"data/server-config.example.json").is_file(), "Shared configuration example is missing")
    check((ROOT/"cron-dispatch-v2.9.22.sh").is_file(), "Runtime-token CRON dispatcher is missing")
    check((ROOT/"weekly-backup-v2.9.22.sh").is_file(), "Weekly long-life backup script is missing")
    check((ROOT/"install-miac-seed-v2.9.22.sh").is_file(), "MIAC seed installer is missing")
    reset_cron=(ROOT/"reset-install-cron-v2.9.22.sh").read_text()
    for schedule in ("*/5 * * * *", "2-59/10 * * * *", "4-59/10 * * * *", "17 * * * *"):
        check(schedule in reset_cron and "$DISPATCHER" in reset_cron, f"Missing operational v2.9.22 CRON schedule: {schedule}")
    check("37 3 * * 0" in reset_cron and "$WEEKLY_BACKUP" in reset_cron, "v2.9.22 weekly backup CRON entry is missing")
    repo=(ROOT/"server/team-points/src/Repository.php").read_text()
    check("CORE_SCHEMA_VERSION = 17;" in repo and "ANALYTICS_SCHEMA_VERSION = 9;" in repo, "v2.10.6 schema contract must be Core 17 / Analytics 9")
    check((ROOT/"data/match-tracking/matches/.gitkeep").is_file(), "Unified snapshot directory is missing")
    check((ROOT/"data/match-tracking/quarantine/.gitkeep").is_file(), "Migration quarantine directory is missing")
    check(not (ROOT/"data/match-history").exists(), "Legacy history directory must not ship in the new package")
    check(not (ROOT/"data/followed-matches.json").exists(), "Legacy follow registry must not ship in the new package")
    common_php=(ROOT/"api/_common.php").read_text()
    local_py=(ROOT/"serve_local.py").read_text()
    check("migrate_legacy_tracking" in common_php and "migrate_legacy_tracking" in local_py, "Legacy tracking migration missing")
    check("captureWarning" in common_php and "captureWarning" in local_py, "Archived follow-back warning path missing")
    history_js=(ROOT/"assets/js/shared/match-history-ui.js").read_text()
    check("p2k-history-details-slot" in history_js and "ensureDetailsPanel(host)" in history_js, "Graph-anchored history panel missing")
    check("Copy text" in history_js and "changesPlainText" in history_js and "copyPlainText" in history_js, "Plain-text history copy missing")
    history_css=(ROOT/"assets/css/match-history.css").read_text()
    check("position: absolute" in history_css and "width: min(560px, 100%)" in history_css, "Graph-contained compact overlay missing")
    check("document.body.appendChild(detailsBackdrop)" not in history_js, "Viewport-wide history overlay returned")
    check(".history-dialog > .snapshot-backdrop" in admin_css, "Graph-local management change panel missing")
    check("omitUnchangedLineups" in history_js and "lineupFingerprint" in history_js, "Identical-lineup graph suppression is missing")
    check("ratingChanged" in history_js and "ratingChanged" in admin_js, "Archived rating-change details are missing")
    check("MATCH_MONITORING_FIRST_DAY_SECONDS" in common_php and "samplingDue" in common_php and "dueReferences" in common_php, "Adaptive match-monitoring policy is missing")
    task_registry=(ROOT/"server/shared/TaskRegistry.php").read_text()
    check("expected_interval_seconds' => 3600" in task_registry, "Match-monitoring health cadence is not hourly")

    index=(ROOT/"index.html").read_text()
    branding=(ROOT/"config/site-branding.js").read_text()
    check("<title>Promote to King</title>" in index and "<h1>Promote to King</h1>" in index, "Promote to King fallback identity missing")
    check("Play together. Improve together. Promote to King." in index, "Promote to King fallback subtitle missing")
    check('config/site-branding.js?v=2.10.6.11' in index and index.index('config/site-branding.js') < index.index('assets/js/site-config.js'), "Branding config load order/version is wrong")
    check('title: "Promote to King"' in branding and 'subtitle: "Play together. Improve together. Promote to King."' in branding, "Default branding configuration is wrong")
    check('clubSlug: "promote-to-king"' in branding and 'clubUrl: "https://www.chess.com/club/promote-to-king"' in branding, "Configured club identity is missing")
    check('id="siteClubLink"' in index and 'href="https://www.chess.com/club/promote-to-king"' in index, "Configured club logo link fallback is missing")
    for key in ("upcoming", "creation", "open", "recruit", "challenges"):
        check(re.search(rf'<button[^>]*(?:data-admin-only[^>]*hidden|hidden[^>]*data-admin-only)[^>]*data-key="{key}"', index), f"{key} must be admin-only")
    site_tabs=(ROOT/"assets/js/pages/site-tabs.js").read_text()
    site_config=(ROOT/"assets/js/site-config.js").read_text()
    check("PRELOAD_KEYS" not in site_tabs and "preload" not in site_tabs.lower(), "Hidden tools are preloaded")
    check('siteName || "Promote to King"' in site_tabs and 'branding.title || "Promote to King"' in site_config, "Runtime title configuration is missing")
    check("MAIN_SUBTITLE" in site_tabs and "MAIN_LOGO" in site_tabs and "applyBranding()" in site_tabs, "Runtime branding application is incomplete")
    check("MAIN_CLUB_URL" in site_tabs and 'url.searchParams.set("lockTeam", "1")' in site_tabs, "Configured logo destination or recruitment lock is missing")
    recruit_js=(ROOT/"assets/js/pages/recruit-match.js").read_text()
    recruit_css=(ROOT/"assets/css/recruit-match.css").read_text()
    check("lockTeamSelection" in recruit_js and "state.lockTeamSelection && !preferred" in recruit_js, "Configured-club recruitment enforcement is missing")
    check("recruitment-pool.php" in recruit_js and "stored-rating" in recruit_js.lower(), "Stored-rating recruitment pool integration is missing")
    check("lowestOpponentRating" in recruit_js and "opponentMemberSet" in recruit_js and "significantTarget" in recruit_js, "Recruitment candidate filters or significant-advantage model are missing")
    check((ROOT/"server/team-points/public/recruitment-pool.php").is_file(), "Recruitment rating-pool endpoint is missing")
    check(".p2k-team-selector[hidden]" in recruit_css, "Locked team selector style is missing")
    upcoming_html=(ROOT/"AnalyzeMatches.htm").read_text()
    upcoming_core=(ROOT/"assets/js/shared/upcoming-analysis-core.js").read_text()
    upcoming_css=(ROOT/"assets/css/upcoming-matches-analyzer.css").read_text()
    check('id="p2kUpcomingMatchesTab"' in upcoming_html and 'id="p2kUpcomingPlayersTab"' in upcoming_html, "Upcoming subtabs are missing")
    check('id="p2kPlayerSearch"' in upcoming_html and 'id="p2kPlayerSort"' in upcoming_html, "Player search/sort controls are missing")
    check("aggregateRegisteredPlayers" in upcoming_core and "matchRuleCategory" in upcoming_core and "medianNumber" in upcoming_core, "Player aggregation is incomplete")
    player_section=upcoming_core[upcoming_core.index("function aggregateRegisteredPlayers"):upcoming_core.index("function setUpcomingSection")]
    check("api.chess.com/pub/player" not in player_section, "Players subtab accesses player endpoints")
    check(".p2k-upcoming-tabs" in upcoming_css and ".p2k-player-table" in upcoming_css, "Upcoming player styles are missing")
    challenge_js=(ROOT/"assets/js/pages/challenge-list-assistant.js").read_text()
    challenge_css=(ROOT/"assets/css/challenge-list-assistant.css").read_text()
    check("highlightBatchId" in challenge_js and "p2k-cla-recommendation-new" in challenge_js, "Newest recommendation batch tracking is missing")
    check(".p2k-cla-recommendation-new" in challenge_css and "border-left-color: #5fbf6b" in challenge_css, "Newest recommendation styling is missing")
    creation_core=(ROOT/"assets/js/pages/match-creation-analyzer.js").read_text()
    creation_charts=(ROOT/"assets/js/pages/match-creation-charts.js").read_text()
    creation_css=(ROOT/"assets/css/match-creation-analyzer.css").read_text()
    check("P2K_MATCH_CREATION_CHART_BUCKETS" in creation_core and 'data-chart-scope="started"' in creation_core, "Started chart drill-down missing")
    check('data-chart-scope="registration"' in creation_charts and "openChartMatchOverlay" in creation_charts, "Registration chart drill-down missing")
    check(".p2k-chart-match-overlay" in creation_css and ".p2k-chart-match-table" in creation_css, "Chart match overlay styles missing")
    check('aria-label="Close match list">Close</button>' in creation_charts, "Chart match overlay Close button missing")
    check("rgba(246, 183, 60, .52)" in creation_css and ".p2k-chart-match-close:focus-visible" in creation_css, "Chart match close button is not aligned with project modal controls")
    check(index.index('data-log-tab="match"') < index.index('data-log-tab="scheduled"'), "Log subtab order is wrong")
    challenge=(ROOT/"ChallengeListAssistant.html").read_text()
    check(challenge.index('data-p2k-tab="recommendation"') < challenge.index('data-p2k-tab="checker"'), "Challenge recommendation is not first")
    check('data-p2k-tab="recommendation"' in challenge and 'aria-selected="true"' in challenge, "Challenge recommendation is not selected")

    failure_ui=(ROOT/"assets/js/shared/analysis-failure-ui.js").read_text()
    failure_css=(ROOT/"assets/css/analysis-failure-ui.css").read_text()
    analyze_match_js=(ROOT/"assets/js/pages/analyze-match.js").read_text()
    check(upcoming_html.index('id="p2kAnalyzeButton"') < upcoming_html.index('class="p2k-upcoming-tabs"'), "Upcoming Analyze button is not above subtabs")
    check('id="p2kAnalyzeButton" type="button">Analyze</button>' in upcoming_html, "Upcoming Analyze label is wrong")
    check("data-player-sort-field" in upcoming_core and "openPlayerMatches" in upcoming_core, "Player table interaction is missing")
    check("classicalTimeoutRates" in upcoming_core and "chess960TimeoutRates" in upcoming_core, "Separate timeout statistics are missing")
    check("value > 0 && value <= 1 ? value * 100 : value" not in upcoming_core, "Upcoming timeout values are incorrectly rescaled")
    check("value > 0 && value <= 1 ? value * 100 : value" not in history_js and "value > 0 && value <= 1 ? value * 100 : value" not in admin_js, "Archived timeout values are incorrectly rescaled")
    check("CLUB_ANALYSIS_FAILURE_UI" in failure_ui and "Copy plain text" in failure_ui and "API call" in failure_ui, "Failure detail UI is incomplete")
    check(".club-analysis-failure-modal" in failure_css, "Failure detail styles are missing")
    check("visibleModalRegion" in failure_ui and "positionModalInVisibleViewport" in failure_ui and 'window.parent.addEventListener("scroll"' in failure_ui, "Failure modal is not parent-viewport aware")
    check("align-items: flex-start" in failure_css and "overscroll-behavior: contain" in failure_css, "Failure modal visible-area styles are missing")
    check("attachAnalysisFailures(analysisFailureItems()" in creation_core, "Match Creation failure drill-down is missing")
    check("failedDetails" in upcoming_core and "Upcoming Matches analysis failures" in upcoming_core, "Upcoming failure drill-down is missing")
    check("Open Match Analyzer failure" in analyze_match_js and "matchApiUrl" in analyze_match_js, "Open Match failure drill-down is missing")
    check("authenticatedClubAdmin" in site_tabs and "configuredAdminUsernames" in site_tabs and "oauthSessionClaimsAdmin" in site_tabs, "Local/OAuth club-admin detection is missing")
    check("club-admin-access-ready" in site_tabs and "club-admin-access-ready" in admin_js, "Async admin initialization is missing")
    admin_guard=(ROOT/"assets/js/shared/admin-page-guard.js").read_text()
    tab_activity=(ROOT/"assets/js/shared/tab-activity.js").read_text()
    coordinator=(ROOT/"assets/js/shared/analysis-coordinator.js").read_text()
    api_client=(ROOT/"assets/js/shared/api-client.js").read_text()
    check("Lineup evolution" in history_js and "data-p2k-history-previous" in history_js and "data-p2k-history-next" in history_js, "Lineup recording navigation is missing")
    check('id="snapshotChangesPrevious"' in index and 'id="snapshotChangesNext"' in index, "Management lineup navigation is missing")
    check("p2kWinProbability = 0" in upcoming_core and "minimumEligibilityForfeit" in upcoming_core, "Eligibility-forfeit probability rule is missing")
    check('data-recommendation-mode="rematch"' in challenge and 'id="p2kOldestRematch"' in challenge, "Rematch controls are missing")
    check("function rematchEntries" in challenge_js and 'matchesArray(index, "finished")' in challenge_js, "Rematch scanning is missing")
    check('id="taskLogTaskType"' in index and '<th>Run ID</th>' in index, "Task log type filter/identifier display is missing")
    check('taskType: byId("taskLogTaskType").value' in admin_js and "manual-record-active-matches" in admin_js and "storedMatchIds" in admin_js, "Manual task identifiers/data are missing")
    check("task_identifier" in common_php and "taskType" in common_php and "runId" in common_php, "PHP task identifiers are missing")
    check("task_identifier" in local_py and "task_type_filter" in local_py and '"runId": run_id' in local_py, "Python task identifiers/filter are missing")
    check("p2k-tool-activity" in tab_activity and "activityAwareOptions" in api_client, "Focus-aware acquisition cancellation is missing")
    check("!window.P2K_TAB_ACTIVITY.isActive()" in coordinator, "Inactive synchronization is not blocked")
    check('if (!oauthEnabled() || window.P2K_AUTH?.enabled !== true) return false;' in site_tabs, "Index admin detection does not require current OAuth enablement")
    check("oauthAdminAuthorized" in admin_guard and "clubAdminUsernames" in admin_guard and "configuredAdminUsernames" in admin_guard and "oauthSessionClaimsAdmin" in admin_guard, "Standalone API-primary/OAuth admin verification is incomplete")
    check("P2K_TEAM_POINTS_CLIENT?.connect" in admin_guard and "!displayOverride && !simulatedOverride" in admin_guard and "api.chess.com/pub/club" in admin_guard and "P2K_API_CLIENT" in admin_guard, "Standalone admin gating must use server-authoritative real-admin bootstrap while preserving viewed-identity fallback")
    for page_name in ("AnalyzeMatches.htm", "MatchCreationAnalyzer.htm", "RecruitMatch.html", "ChallengeListAssistant.html", "RecruitmentDemandPlanner.html", "LeagueSeasonCenter.html", "InsightsHealth.html", "TeamPointsMigration.html"):
        page=(ROOT/page_name).read_text()
        check("admin-page-guard.js" in page and "admin-access-pending" in page, f"{page_name} is not standalone-admin guarded")

    check("admin-page-guard.js" not in (ROOT/"AnalyzeMatch.html").read_text(), "Public Open Match Analyzer must not be admin-guarded")
    check("admin-page-guard.js" not in (ROOT/"AnalyzeMatchModal.html").read_text(), "Public detailed-analysis modal must not be admin-guarded")
    detailed_host=(ROOT/"assets/js/shared/detailed-analysis-host.js").read_text()
    check("p2k-open-detailed-analysis" in detailed_host and "p2kShellDetailedAnalysisModal" in detailed_host, "Visible-shell detailed analysis host is missing")

    # v2.9.22.10 task telemetry restoration invariants.
    task_control=(ROOT/"assets/js/pages/task-control.js").read_text()
    control_api=(ROOT/"server/control/public/api.php").read_text()
    team_worker=(ROOT/"server/team-points/src/Worker.php").read_text()
    team_cron=(ROOT/"server/team-points/public/cron.php").read_text()
    check("state.taskDetails.clear()" not in task_control, "Selected task detail is still destructively cleared on status refresh")
    check("taskDetailLoads: new Map()" in task_control and "loadSelectedTaskDetail({ quiet: true })" in task_control, "Selected-only detail persistence/refresh is missing")
    check(".slice(0, 32)" in task_control, "Task detail scalar telemetry is still truncated to the old limit")
    check("lane-local-telemetry-v292210" in control_api and "club_index_verified_age_seconds" in control_api and "active_detail_checks_due" in control_api, "Club Points lane-local telemetry is incomplete")
    check("player_matches_operational_fresh_percent" in control_api and "player_matches_server_verified_percent" in control_api and "player_stats_server_verified_percent" in control_api, "Member Points operational/server-verified telemetry is incomplete")
    check("No runnable job — " in team_worker and "idle_reason" in team_worker, "Qualified Team Points idle reason is missing")
    check("worker_idle_reason" in team_cron and "worker_idle_reason" in control_api, "Worker idle/job telemetry is not persisted through both controller paths")

    # v2.10.4.6 recovered-standalone Green runtime invariants.
    green_worker=(ROOT/"server/team-points-green/src/GreenWorker.php").read_text()
    green_cron=(ROOT/"server/team-points-green/public/cron.php").read_text()
    migration_html=(ROOT/"TeamPointsMigration.html").read_text()
    quick_progress=ROOT/"assets/js/pages/quick-matches-progress-v21046.js"
    check("min(55,$hardBudgetSeconds" in green_worker, "v2.10.4.6 GreenWorker hard clamp 55 is missing")
    check("min(48,$hardBudgetSeconds" not in green_worker, "Legacy GreenWorker hard clamp 48 remains")
    check("new GreenWorker(null,55,50,[" in green_cron, "Active Green CRON worker is not 55/50")
    check("new GreenWorker(null,24,18,[" not in green_cron, "Legacy active Green CRON 24/18 remains")
    check("every minute via 5 interlaced /5 CRON entries" in green_cron, "v2.10.4.6 Green CRON cadence telemetry is missing")
    check(quick_progress.is_file(), "v2.10.4.6 quick_matches observability adapter is missing")
    check(migration_html.count("quick-matches-progress-v21046.js?v=2.10.6")==1, "quick_matches observability adapter must be linked exactly once for v2.10.6")

    # v2.10.6 Green production cutover / GAB / GFFL invariants.
    task_html=(ROOT/"TaskControl.html").read_text()
    task_js=(ROOT/"assets/js/pages/task-control.js").read_text()
    green_repo=(ROOT/"server/team-points-green/src/GreenRepository.php").read_text()
    green_api=(ROOT/"server/team-points-green/public/api.php").read_text()
    read_router=(ROOT/"server/team-points/src/PublicReadDatabase.php").read_text()
    check('data-task-tab="green"' in task_html and 'Green Team Points control' in task_html and 'greenAcceleratorMetrics' in task_html and 'greenGabMetrics' in task_html and 'greenGfflMetrics' in task_html, "Green operational Task Control tab is incomplete")
    check('client-continuous-refresh.js' not in task_html and 'green-accelerator.js?v=2.10.6.20' in task_html, "Task Control still exposes Client Continuous Refresh instead of Green Accelerator")
    check('team-points-maintenance' not in task_html and all(x in task_js for x in ['team-points','team-points-club','team-points-player']), "Blue Team Points controls/cards remain in Scheduled Task Control")
    check("gab_status" in green_repo and "priority_lane" in green_repo and "gffl_match_detail" in green_repo and "status='completed' THEN VALUES(due_at)" in green_repo, "GAB/GFFL priority/factorization invariants are incomplete")
    check('Green public reads were selected before GAB reached ready state.' not in read_router and 'SELECT public_read_target FROM p2k_g_state' in read_router and 'fail-closed' in read_router and 'p2k_core_schema_version' in read_router and 'p2k_analytics_schema_version' in read_router, "Green public-read router is not technical-only gated/fail-closed")
    check("if($action==='switch-green-reads')" in green_api and "if($action==='make-green-primary')" in green_api and "if($action==='rollback-blue-reads')" in green_api and "'allowed'=>(bool)$technical['ready']" in green_api and 'Green validation is not ready; migration phase was not changed.' not in green_api, "Operational Green cutover actions/technical-only gate are incomplete")

    generated_artifacts=[p.relative_to(ROOT).as_posix() for p in ROOT.rglob("*") if p.is_file() and ("__pycache__" in p.parts or ".pytest_cache" in p.parts or p.suffix==".pyc")]
    check(not generated_artifacts, f"Generated Python test-cache artifacts must not ship: {generated_artifacts[:5]}")

    check(manifest.get("version")=="2.10.6.23" and manifest.get("releaseVersion")=="2.10.6.23" and manifest.get("migrationVersion")=="2.10.6.23" and manifest.get("blueBaselineVersion")=="2.9.22.10" and manifest.get("publicDataSource")=="green_authoritative_blue_rollback", "Manifest release/migration/baseline identity is wrong")
    release=manifest.get("v21052Release") or {}
    check(release.get("greenWorkerHardBudgetSeconds")==55 and release.get("greenWorkerSoftTargetSeconds")==50 and release.get("schemaChange")=="additive_green_only" and release.get("databaseReset") is False and release.get("reseed") is False and release.get("publicDefault")=="blue" and release.get("greenPublicCutover")=="validation_and_gab_gated" and release.get("GAB") is True and release.get("GFFL") is True and release.get("scheduledTaskGreenOperationsTab") is True, "v2.10.5.4 release metadata is incomplete")
    previous_release=manifest.get("v21054Release") or {}
    check(previous_release.get("zonedDensityHeatmap") is True and previous_release.get("dashboardAssistantDedicatedFilteredFrame") is True and previous_release.get("automaticCronTrackingStopAfterStartSeconds")==86400 and previous_release.get("lastCycleDuration") is True and previous_release.get("averageLast10CycleDuration") is True and previous_release.get("schemaChange")=="none" and previous_release.get("cronChange") is False, "v2.10.5.4 corrective metadata is incomplete")
    current_release=manifest.get("v21055Release") or {}
    check(current_release.get("gabDuplicateLineupProjectionCanonicalized") is True and current_release.get("canonicalIdentityGameProjection") is True and current_release.get("quickCompleteSelfHealing") is True and current_release.get("cycleNextStageDurableBeforeAnalyticsRebuild") is True and current_release.get("zonedDensityBilinearInterpolation") is True and current_release.get("zonedDensityPaletteChanged") is False and current_release.get("zonedDensityThresholdsChanged") is False and current_release.get("schemaChange")=="none" and current_release.get("cronChange") is False and current_release.get("imagesChanged") is False, "v2.10.6 corrective metadata is incomplete")
    release2106=manifest.get("v2106Release") or {}
    check(release2106.get("version")=="2.10.6" and release2106.get("sourceBaseline")=="2.10.5.5" and release2106.get("mcaResultsAutoSync") is True and release2106.get("mcaDateBackfill") is True and release2106.get("adminRecentMatchNetworkFirstRefresh") is True and release2106.get("opponentPlayerIntelligence") is True and release2106.get("GABCRF") is True and release2106.get("coreSchema")==17 and release2106.get("analyticsSchema")==9 and release2106.get("databaseReset") is False and release2106.get("sourceFilesDeleted")==0, "v2.10.6 release metadata is incomplete")
    release21062=manifest.get("v21062Release") or {}
    check(release21062.get("version")=="2.10.6.2" and set(release21062.get("compatibleSources") or [])=={"2.10.6","2.10.6.1"} and release21062.get("inventedDotCsvRemoved") is True and release21062.get("pageFallbackRequiresCompleteAdvertisedPlayerCount") is True and release21062.get("syncErrorDetails") is True and release21062.get("retryFailedEvents") is True and release21062.get("mcaBlueToGreenAdminSync") is True and release21062.get("mcaBlueToGreenTransactional") is True and release21062.get("databaseSchemaChange") is False and release21062.get("sourceFilesDeleted")==0, "v2.10.6.2 release metadata is incomplete")
    release21063=manifest.get("v21063Release") or {}
    check(release21063.get("version")=="2.10.6.3" and release21063.get("sourceBaseline")=="2.10.6.2" and release21063.get("mcaIndexPaginationDiscovery") is True and release21063.get("mcaArenaResultsPaginationDiscovery") is True and release21063.get("paginationDurableScratchState") is True and release21063.get("paginationOneHttpRequestPerWorkerStep") is True and release21063.get("playerCountIntegrityGatePreserved") is True and release21063.get("futureDateSanityRepair") is True and release21063.get("databaseSchemaChange") is False and release21063.get("sourceFilesDeleted")==0, "v2.10.6.3 release metadata is incomplete")
    release21064=manifest.get("v21064Release") or {}
    check(release21064.get("version")=="2.10.6.4" and release21064.get("sourceBaseline")=="2.10.6.3" and release21064.get("mcaIndexCsvPrimary") is True and release21064.get("mcaIndexPaginationDiscovery") is True and release21064.get("csvUrlCapturedFromIndex") is True and release21064.get("csvUrlPreservedAcrossQueueMergeAndRetry") is True and release21064.get("directCsvStageWhenDateKnown") is True and release21064.get("arenaPageUsedForMissingDateOrFallback") is True and release21064.get("databaseSchemaChange") is False and release21064.get("sourceFilesDeleted")==0, "v2.10.6.4 release metadata is incomplete")
    release21065=manifest.get("v21065Release") or {}
    check(release21065.get("version")=="2.10.6.5" and release21065.get("sourceBaseline")=="2.10.6.4" and release21065.get("mcaIndexDiscoveryOnly") is True and release21065.get("frontPageFirst") is True and release21065.get("indexPaginationOnlyWhenLatestStoredArenaMissingFromFrontPage") is True and release21065.get("indexPaginationStopsAtStoredBoundary") is True and release21065.get("deterministicArenaUrlFromSlugId") is True and release21065.get("deterministicCsvUrlFromSlugId") is True and release21065.get("routineRecentCsvRefreshRemoved") is True and release21065.get("missingDateBackfillIndependentOfIndex") is True and release21065.get("possibleRenameRefreshPreserved") is True and release21065.get("legacyResultsPaginationSelfHealing") is True and release21065.get("databaseSchemaChange") is False and release21065.get("sourceFilesDeleted")==0, "v2.10.6.5 release metadata is incomplete")
    release21066=manifest.get("v21066Release") or {}
    check(release21066.get("version")=="2.10.6.6" and release21066.get("sourceBaseline")=="2.10.6.5" and release21066.get("mcaTimestampOnly") is True and release21066.get("mcaAutomaticArenaDiscoveryRemoved") is True and release21066.get("mcaIndexRequestsRemoved") is True and release21066.get("mcaAutomaticCsvAcquisitionRemoved") is True and release21066.get("mcaPlayerResultsScrapingRemoved") is True and release21066.get("manualMcaCsvUploadPreserved") is True and release21066.get("knownArenaUrlDerivedFromStoredFilename") is True and release21066.get("missingDateBackfillOnly") is True and release21066.get("zeroRequestsWhenNoStoredDatesMissing") is True and release21066.get("automaticDatasetRebuildRemovedFromMcaCron") is True and release21066.get("databaseSchemaChange") is False and release21066.get("sourceFilesDeleted")==0, "v2.10.6.6 release metadata is incomplete")
    release21067=manifest.get("v21067Release") or {}
    check(release21067.get("version")=="2.10.6.7" and release21067.get("sourceBaseline")=="2.10.6.6" and release21067.get("greenTimestampUtcToBrowserLocal") is True and release21067.get("gabReconciliationAttemptsSeparatedFromUniqueTarget") is True and release21067.get("gabLastFullPassRemainingTelemetry") is True and release21067.get("gabNoFalseHundredPercentWhileIncomplete") is True and release21067.get("gabImmediateReadyFinalization") is True and release21067.get("readParityDynamicCheckDenominator") is True and release21067.get("cutoverBlockersVisible") is True and release21067.get("gqacWaitingReasonVisible") is True and release21067.get("readinessGateWeakened") is False and release21067.get("recommendedFirstCutoverPhase")=="green_reads_both_writing" and release21067.get("databaseSchemaChange") is False and release21067.get("cronChange") is False, "v2.10.6.7 release metadata is incomplete")
    release21068=manifest.get("v21068Release") or {}
    check(release21068.get("version")=="2.10.6.8" and release21068.get("sourceBaseline")=="2.10.6.7" and release21068.get("simpleGreenCutoverButtons") is True and release21068.get("switchReadsToGreenKeepsBothMaintained") is True and release21068.get("makeGreenPrimarySeparate") is True and release21068.get("rollbackBlueRestoresBothMaintained") is True and release21068.get("continuousQueueReadinessAdvisory") is True and release21068.get("technicalCutoverOnlyHardGate") is True and release21068.get("technicalCoreSchemaMinimum")==17 and release21068.get("technicalAnalyticsSchemaMinimum")==9 and release21068.get("publicRouterRequiresGabReady") is False and release21068.get("publicRouterStillFailClosedOnTechnicalFailure") is True and release21068.get("cutoverUsefulMetrics") is True and release21068.get("advancedRoutingCollapsed") is True and release21068.get("databaseSchemaChange") is False and release21068.get("cronChange") is False, "v2.10.6.8 release metadata is incomplete")
    release21069=manifest.get("v21069Release") or {}
    check(release21069.get("version")=="2.10.6.9" and release21069.get("sourceBaseline")=="2.10.6.8" and release21069.get("publicGreenAuthoritative") is True and release21069.get("blueRollbackOnly") is True and release21069.get("greenNativeClubDashboard") is True and release21069.get("greenNativePlayerSummary") is True and release21069.get("liveCompatibilityProjectionIndependentOfGab") is True and release21069.get("browserObservationProjectionIdentityReturned") is True and release21069.get("compatibilityAnalyticsRefreshDuringGab") is True and release21069.get("greenDashboardHealthReplacesBlueTeamPointsHealthAfterCutover") is True and release21069.get("databaseSchemaChange") is False and release21069.get("cronChange") is False, "v2.10.6.9 release metadata is incomplete")
    release210610=manifest.get("v210610Release") or {}
    check(release210610.get("version")=="2.10.6.10" and release210610.get("sourceBaseline")=="2.10.6.9" and release210610.get("registrationChartCanonicalRecordSource") is True and release210610.get("registrationChartRenderedDateParsingRemoved") is True and release210610.get("trackingExpiryAllSources") is True and release210610.get("trackingExpiryAppliedOnExplorerRead") is True and release210610.get("trackingExpiryAppliedBeforeScheduledSelection") is True and release210610.get("expiredMatchOneOffRecordingPreserved") is True and release210610.get("dashboardAssistantSingleLoaderStateAuthority") is True and release210610.get("dashboardAssistantFilteredLoaderRaceFixed") is True and release210610.get("databaseSchemaChange") is False and release210610.get("cronChange") is False, "v2.10.6.10 release metadata is incomplete")
    release210611=manifest.get("v210611Release") or {}
    check(release210611.get("version")=="2.10.6.11" and release210611.get("sourceBaseline")=="2.10.6.10" and release210611.get("dashboardGreenCoreAllHeadlineMetrics") is True and release210611.get("dashboardGreenCoreMatchLists") is True and release210611.get("dashboardChessClubMatchesOverwriteRemoved") is True and release210611.get("teamPointsPublicNoStore") is True and release210611.get("teamPointsBrowserMemoryCacheBypassed") is True and release210611.get("migrationGreenHeadlineTotalsLiveCore") is True and release210611.get("migrationGreenTopLiveCore") is True and release210611.get("greenAnalyticsMinimumRefreshSeconds")==30 and release210611.get("registrationAnalyzerCurrentCacheGeneration") is True and release210611.get("dashboardAssistantShellReadySeparatedFromFullReady") is True and release210611.get("findMatchCurrentCacheGeneration") is True and release210611.get("databaseSchemaChange") is False and release210611.get("cronChange") is False, "v2.10.6.11 release metadata is incomplete")
    release210612=manifest.get("v210612Release") or {}
    check(release210612.get("version")=="2.10.6.12" and release210612.get("sourceBaseline")=="2.10.6.11" and release210612.get("scope")=="authenticated_admin_toggle_shell_only" and release210612.get("adminTopLevelTabs")==["competitions","members","team","opponents","maintenance","misc"] and release210612.get("liveMetricCards") is True and release210612.get("statusFreshnessSourceMetadata") is True and release210612.get("deepLinksReuseExistingAdminTools") is True and release210612.get("lostAndFoundPreservesExistingToolCatalogue") is True and release210612.get("existingAdminToolCountPreserved")==36 and release210612.get("publicDashboardRegressionLocked") is True and release210612.get("publicHallOfFameRegressionLocked") is True and release210612.get("publicInsightsRegressionLocked") is True and release210612.get("databaseSchemaChange") is False and release210612.get("cronChange") is False, "v2.10.6.12 release metadata is incomplete")
    release210613=manifest.get("v210613Release") or {}
    check(release210613.get("version")=="2.10.6.13" and release210613.get("sourceBaseline")=="2.10.6.12" and release210613.get("adminCardDetailViews") is True and release210613.get("canonicalPublicPageUrls") is True and release210613.get("canonicalAdminCategoryDetailUrls") is True and release210613.get("embeddedToolTabUrlPropagation") is True and release210613.get("browserBackForwardRefreshRestoresView") is True and release210613.get("adminDeepLinkSurvivesAuthResolution") is True and release210613.get("dashboardAssistantDedicatedFullFrame") is True and release210613.get("dashboardAssistantAtomicRevealAfterReady") is True and release210613.get("utcTimestampDisplayRestored") is True and release210613.get("databaseSchemaChange") is False and release210613.get("cronChange") is False, "v2.10.6.13 release metadata is incomplete")
    release210614=manifest.get("v210614Release") or {}
    check(release210614.get("version")=="2.10.6.14" and release210614.get("sourceBaseline")=="2.10.6.13" and release210614.get("adminDetailDynamicHeight") is True and release210614.get("adminDetailActivityLifecycleRestored") is True and release210614.get("embeddedLegacyChromeReduced") is True and release210614.get("mcaGlobalTaskControlEmbeddingRemoved") is True and release210614.get("publicDomLayoutRegressionLocked") is True and release210614.get("databaseSchemaChange") is False and release210614.get("cronChange") is False, "v2.10.6.14 release metadata is incomplete")
    release210615=manifest.get("v210615Release") or {}
    check(release210615.get("version")=="2.10.6.15" and release210615.get("sourceBaseline")=="2.10.6.14" and release210615.get("taskControlOwnsScheduledAndGreenNavigation") is True and release210615.get("greenManagementReachableFromNewAdminShell") is True and release210615.get("greenManagementReachableFromLegacyAdministration") is True and release210615.get("greenManagementReachableFromLostAndFound") is True and release210615.get("greenManagementBackForwardRefreshPersistent") is True and release210615.get("greenGabControlsPreserved") is True and release210615.get("greenGfflControlsPreserved") is True and release210615.get("greenAcceleratorControlsPreserved") is True and release210615.get("publicDomLayoutRegressionLocked") is True and release210615.get("databaseSchemaChange") is False and release210615.get("cronChange") is False, "v2.10.6.15 release metadata is incomplete")
    release210616=manifest.get("v210616Release") or {}
    check(release210616.get("version")=="2.10.6.16" and release210616.get("sourceBaseline")=="2.10.6.15" and release210616.get("quickTailPassCycleBoundaryGuard") is True and release210616.get("missingGqacSelfHeal") is True and release210616.get("periodicTerminalProfileStatsCheckpoint") is True and release210616.get("quickProfileStatsRealProgress") is True and release210616.get("analyticsOutsideGreenWorkerLock") is True and release210616.get("completeCycleAnalyticsRebuildRemoved") is True and release210616.get("analyticsMaterializationMinimumSeconds")==300 and release210616.get("postLockAnalyticsFailureIsolated") is True and release210616.get("liveCompatibilityProjectionRemainsImmediate") is True and release210616.get("coreVsPostLockTimingTelemetry") is True and release210616.get("publicDomLayoutRegressionLocked") is True and release210616.get("databaseSchemaChange") is False and release210616.get("cronChange") is False, "v2.10.6.16 release metadata is incomplete")
    release210617=manifest.get("v210617Release") or {}
    check(release210617.get("version")=="2.10.6.17" and release210617.get("sourceBaseline")=="2.10.6.16" and set(release210617.get("compatibleInstallerSources") or [])=={"2.10.6.15","2.10.6.16"} and release210617.get("canonicalGameSequenceProjection") is True and release210617.get("aliasPointEventGameDeduplication") is True and release210617.get("gabcrfGameCountDriftDetection") is True and release210617.get("canonicalGameParityCount") is True and release210617.get("resumeErroredGabLaneInPlace") is True and release210617.get("v210616CycleRuntimeCorrectivePreserved") is True and release210617.get("databaseSchemaChange") is False and release210617.get("cronChange") is False, "v2.10.6.17 release metadata is incomplete")
    release210618=manifest.get("v210618Release") or {}
    check(release210618.get("version")=="2.10.6.18" and release210618.get("sourceBaseline")=="2.10.6.17" and release210618.get("registrationLineupCurrentSnapshot") is True and release210618.get("canonicalMemberPerMatchProjection") is True and release210618.get("acceleratedHistoricalHeatmapBackfill") is True and release210618.get("knownFinishedMatchesOnly") is True and release210618.get("deepDiscoveryRequired") is False and release210618.get("heatmapBrowserCacheMinutes")==5 and release210618.get("databaseSchemaChange") is False and release210618.get("cronChange") is False, "v2.10.6.18 release metadata is incomplete")
    release210619=manifest.get("v210619Release") or {}
    check(release210619.get("version")=="2.10.6.19" and release210619.get("sourceBaseline")=="2.10.6.18" and release210619.get("heatmapUsesStoredGameRatings") is True and release210619.get("residualBackfillUsesBoardDetails") is True and release210619.get("acceleratorBackgroundTraffic") is True and release210619.get("acceleratorLogicalFeederConcurrency")==16 and release210619.get("acceleratorJsonpFallback") is False and release210619.get("databaseSchemaChange") is False and release210619.get("cronChange") is False, "v2.10.6.19 release metadata is incomplete")
    gab=(ROOT/"server/team-points-green/src/GreenAnalyticsBootstrap.php").read_text()
    check("setLaneTotal('read_parity',13)" not in gab and "last_full_pass_remaining" in gab and "progress_mode']='convergence'" in gab and "elseif($percent>=100.0)$percent=99.9" in gab, "v2.10.6.7 GAB observability corrective is incomplete")
    check("greenCutoverMetrics" in task_html and "localGreenTimestamp" in task_js and "renderCutoverReadiness" in task_js and "waiting for quick_boards" in task_js, "v2.10.6.7 Green cutover UI corrective is incomplete")
    check(all(x in task_html for x in ['greenSwitchReads','greenMakePrimary','greenRollbackReads','Advanced routing controls']) and all(x in task_js for x in ['Switch is allowed. Advisories (do not block)','switch-green-reads','make-green-primary','rollback-blue-reads','Current facts over SLO','Compatibility smoke']), "v2.10.6.9 simplified Green cutover UI is incomplete")
    public_repo=(ROOT/"server/team-points/src/Repository.php").read_text()
    green_observe=(ROOT/"server/team-points-green/public/observe.php").read_text()
    green_compat=(ROOT/"server/team-points-green/src/GreenCompatibility.php").read_text()
    dash_js=(ROOT/"assets/js/pages/dashboard-v2.js").read_text()
    check("adminDetail:" in dash_js and "adminDetailTab:" in dash_js and "adminToolTab:" in dash_js and "ADMIN_DETAIL_DEFS" in dash_js and "dashboard-admin-shell-detail" in dash_js, "v2.10.6.13 admin detail router is incomplete")
    check('url.searchParams.set("page", state.publicPage || "dashboard")' in dash_js and 'url.searchParams.set("adminCategory", state.category || "competitions")' in dash_js and 'adminSubtab: ["upcoming","creation","challenge","open","live-ranks","tasks","logs","diagnostics","storage","intelligence","reconciliation","members","migration"]' in dash_js, "v2.10.6.13 canonical URL state is incomplete")
    check('const frame=ensureDedicatedMatchAssistant(state.pendingAssistantFilter);' in dash_js and 'if(byId("recommendationsDefaultView"))byId("recommendationsDefaultView").hidden=true;' in dash_js and 'if(updateHistory)writeNavigationState();' in dash_js, "v2.10.6.13 Match Assistant atomic dedicated-frame reveal is incomplete")
    check('if (state.view === "public" && state.publicPage !== "administration") showPublicPage' in dash_js, "v2.10.6.13 direct Admin deep links are still vulnerable to startup reset")
    compat_refresh_block=green_compat[green_compat.index('public function maybeRebuildAnalytics'):green_compat.index('/**\n     * Public-read contract audit')]
    check('greenNativeClubDashboard' in public_repo and 'greenNativePlayerSummary' in public_repo and "'green_native_core_live'" in public_repo, "v2.10.6.9 native Green public totals/player reads are incomplete")
    check("gab_status']??'')==='ready'" not in green_worker and "gab_status']??'')==='ready'" not in green_observe and 'projectMatch($mid,false)' in green_observe, "v2.10.6.9 live Green compatibility projection is still GAB-gated")
    check("$result['match_id']=$matchId" in green_repo and "$result['board_no']=$boardNo" in green_repo and "$result['username']=$username" in green_repo, "v2.10.6.9 browser observation projection identity is incomplete")
    check('gab_status' not in compat_refresh_block and '$this->rebuildAnalytics();return true;' in compat_refresh_block, "v2.10.6.9 compatibility analytics are still frozen by GAB")
    check("action==='runtime-health'" in green_api and 'name: "Green Team Points"' in dash_js and '["team-points-club", "team-points-player", "team-points"]' in dash_js, "v2.10.6.9 Dashboard health still treats Blue Team Points as production health after Green cutover")
    match_creation=(ROOT/"MatchCreationAnalyzer.htm").read_text()
    find_match=(ROOT/"FindMatch.htm").read_text()
    team_client=(ROOT/"assets/js/shared/team-points-client.js").read_text()
    check('public function publicDashboardMatches' in public_repo and 'dashboard-matches' in dash_js and 'clubMatchesAPI' not in dash_js[dash_js.index('async function loadTeamData()'):dash_js.index('function renderGauge')], "v2.10.6.11 Dashboard still mixes live Green with Chess.com match lists")
    check('GREEN · live database' in dash_js and 'isTeamPointsDatabase' in dash_js and 'cache: "no-store"' in team_client, "v2.10.6.11 DB freshness/cache policy is incomplete")
    check('match-creation-charts.js?v=2.10.6.11' in match_creation and 'match-creation-analyzer.js?v=2.10.6.11' in match_creation and 'MatchCreationAnalyzer.htm?embedded=1&release=2.10.6.11' in (ROOT/"ui-v2.html").read_text(), "v2.10.6.11 Match Creation cache generation is stale")
    ready_block=dash_js[dash_js.index('if (event.data?.type === "p2k-dashboard-assistant-ready")'):dash_js.index('if (!state.recommendationReady')]
    check('state.assistantFullReady = false;' in ready_block and 'p2k-dashboard-show-full-assistant' in ready_block and 'find-match.js?v=2.10.6.11' in find_match, "v2.10.6.11 assistant full-ready/cache corrective is incomplete")
    live_ranks=(ROOT/"server/team-points/src/LiveRanksService.php").read_text()
    for retired in ("clubs/pastevents","discoverArenaEntries","discoverPaginationLinks","advanceIndexPagination","download-results","storeFetchedCsv","possibleRenameArenaIdentities","results_pages"):
        check(retired not in live_ranks, f"Retired MCA discovery/download path returned: {retired}")
    check("seedTimestampQueue" in live_ranks and "needs_csv=0" in live_ranks and "storedFileRecords()" in live_ranks, "MCA timestamp-only queue contract is incomplete")
    compat=(ROOT/"server/team-points-green/src/GreenCompatibility.php").read_text()
    check("canonicalBoardProjection" in compat and "lineup_duplicates_resolved" in compat and "COALESCE(im.canonical_username_key,e.username_key)=?" in compat, "v2.10.6 GAB duplicate-lineup projection corrective is missing")
    check("canonicalGameProjection" in compat and "game_duplicates_resolved" in compat and "if(isset($bySequence[$seq])){$duplicates++;continue;}" in compat, "v2.10.6.17 GABCRF duplicate-game projection corrective is missing")
    check("COUNT(DISTINCT CONCAT(gx.board_no,':',GREATEST(1,gx.game_index)))" in compat and "COUNT(DISTINCT CONCAT(g.match_id,':',g.board_no,':',GREATEST(1,g.game_index)))" in compat, "v2.10.6.17 canonical game convergence/parity checks are missing")
    check("member_duplicates_resolved" in compat and "$usedMembers=[]" in compat and "COUNT(DISTINCT COALESCE(im2.canonical_username_key,mp2.username_key))" in compat, "v2.10.6.18 canonical member-per-match projection is missing")
    check("startHeatmapBackfill" in green_repo and "heatmapBackfillPlan" in green_repo and ("heatmap_match_detail" in green_repo or "heatmap_board_detail" in green_repo) and "greenHeatmapStart" in task_html and "opponents-balance-v4" in (ROOT/"assets/js/pages/dashboard-insights.js").read_text(), "v2.10.6.18 accelerated heatmap backfill is incomplete")
    check("heatmap_board_detail" in green_repo and "storedGameRatingPair" in compat and "FEEDER_CONCURRENCY=16" in (ROOT/"assets/js/shared/green-accelerator.js").read_text() and 'trafficClass:"background"' in (ROOT/"assets/js/shared/green-accelerator.js").read_text() and 'jsonpFallback:false' in (ROOT/"assets/js/shared/green-accelerator.js").read_text(), "v2.10.6.19 heatmap transport/provenance corrective is incomplete")

    release210620=manifest.get("v210620Release") or {}
    check(release210620.get("version")=="2.10.6.20" and release210620.get("sourceBaseline")=="2.10.6.19" and release210620.get("registrationCancellation404Terminal") is True and release210620.get("gfflTerminal404410ClosesDebt") is True and release210620.get("gfflBrowserSuccessClosesDebt") is True and release210620.get("trustedLegacyFactsPreserved") is True and release210620.get("authoritativeIndexReappearanceRearms") is True and release210620.get("databaseSchemaChange") is False and release210620.get("cronChange") is False, "v2.10.6.20 release metadata is incomplete")
    check("retireIneligibleGfflDebt" in green_repo and "terminal_closed" in green_repo and "last_http_status IN (404,410)" in green_repo and '"gffl_match_detail"' in (ROOT/"assets/js/shared/green-accelerator.js").read_text() and "declared==='gffl_match_detail'" in (ROOT/"server/team-points-green/public/observe.php").read_text(), "v2.10.6.20 GFFL terminal-debt corrective is incomplete")
    release210621=manifest.get("v210621Release") or {}
    check(release210621.get("version")=="2.10.6.21" and release210621.get("sourceBaseline")=="2.10.6.20" and release210621.get("adminCategoryMenuUsesPublicTabComponent") is True and release210621.get("adminCategoryDesktopColumns")==6 and release210621.get("adminCategoryMobileColumns")==2 and release210621.get("adminCategoryMobileBreakpointPx")==620 and release210621.get("adminCategorySvgIcons") is True and release210621.get("adminIntroHeadingRemoved") is True and release210621.get("adminOAuthRefreshRowBelowMenu") is True and release210621.get("publicNavigationChanged") is False and release210621.get("databaseSchemaChange") is False and release210621.get("cronChange") is False, "v2.10.6.21 release metadata is incomplete")
    admin_nav=(ROOT/"assets/js/pages/dashboard-v2.js").read_text()
    admin_css=(ROOT/"assets/css/dashboard-v2.css").read_text()
    check('class="dashboard-page-tabs dashboard-admin-category-tabs"' in admin_nav and admin_nav.count('data-admin-category=')>=6 and admin_nav[admin_nav.index('return `<section aria-label="Administrator dashboard"'):].count('class="dashboard-tab-icon"')>=6 and 'Operational views grouped around the way Promote to King is administered.' not in admin_nav and 'dashboard-admin-shell-tabs' not in admin_css and '.dashboard-admin-category-tabs{grid-template-columns:repeat(6,minmax(0,1fr));margin:0}' in admin_css and '@media (max-width:620px){.dashboard-admin-category-tabs{grid-template-columns:repeat(2,minmax(0,1fr))}}' in admin_css, "v2.10.6.21 Admin navigation parity corrective is incomplete")
    release210622=manifest.get("v210622Release") or {}
    gab_bootstrap=(ROOT/"server/team-points-green/src/GreenAnalyticsBootstrap.php").read_text()
    green_worker=(ROOT/"server/team-points-green/src/GreenWorker.php").read_text()
    dashboard_insights=(ROOT/"assets/js/pages/dashboard-insights.js").read_text()
    check(release210622.get("version")=="2.10.6.22" and release210622.get("sourceBaseline")=="2.10.6.21" and release210622.get("gabcrfDeadlockTransientRetry") is True and release210622.get("gabcrfDeadlockYieldPreservesCursor") is True and release210622.get("gabcrfErroredResumePreservesCounters") is True and release210622.get("gabcrfStoredDeadlockAutoRecovery") is True and release210622.get("discoveredMatchTransientModalReplacement") is True and release210622.get("adminMaintenanceLabel") is True and release210622.get("databaseSchemaChange") is False and release210622.get("cronChange") is False, "v2.10.6.22 release metadata is incomplete")
    check("isTransientSerializationFailure" in gab_bootstrap and "for($attempt=1;$attempt<=3;$attempt++)" in gab_bootstrap and "gabcrf_transient_retry" in gab_bootstrap and "resumeTransientErrorIfSafe" in gab_bootstrap and "status='pending',error_rows=0,last_error=NULL,completed_at=NULL" in gab_bootstrap and "if($gabState==='error')$gabBootstrap->resumeTransientErrorIfSafe()" in green_worker, "v2.10.6.22 GABCRF deadlock-safe resume/retry corrective is incomplete")
    check("await openMatchDetail(id,{replaceInitial:true});" in admin_nav and "replace: options.replaceInitial === true" in dashboard_insights, "v2.10.6.22 discovered-match transient modal replacement is incomplete")
    check('<span>Maintenance</span>' in admin_nav and 'Admin &amp; maintenance' not in admin_nav and 'eyebrow:"Admin & maintenance"' not in admin_nav, "v2.10.6.22 Maintenance label corrective is incomplete")

    # v2.10.6.23 member chronology / tournament / admin lookup invariants.
    release210623=manifest.get("v210623Release") or {}
    tournament_management=(ROOT/"TournamentManagement.html").read_text()
    check(release210623.get("version")=="2.10.6.23" and release210623.get("sourceBaseline")=="2.10.6.22" and release210623.get("renameChronologyCollapsed") is True and release210623.get("knownTournamentStatusTable") is True and release210623.get("newMatches24hUnknownDash") is True and release210623.get("adminMemberLookup") is True and release210623.get("databaseSchemaChange") is False and release210623.get("cronChange") is False, "v2.10.6.23 release metadata is incomplete")
    check("private function collapseMemberEvents" in green_repo and "recent_false_departure" in green_repo and "return $this->collapseMemberEvents($events,$limit);" in green_repo, "v2.10.6.23 rename chronology consolidation is incomplete")
    check("if($action==='member-lookup')" in green_api and "public function memberLookup(string $username): array" in green_repo and "adminMemberLookupForm" in admin_nav and "action','member-lookup'" in admin_nav, "v2.10.6.23 admin member lookup is incomplete")
    check("Known tournaments" in tournament_management and 'id="knownTournaments"' in tournament_management and "renderKnownTournaments" in tournament_management, "v2.10.6.23 known tournament status table is incomplete")
    check("adminRecentMatches: null" in admin_nav and 'state.adminRecentMatches = Array.isArray(recent?.matches) ? recent.matches : []' in admin_nav and 'catch (_) { state.adminRecentMatches = null; }' in admin_nav and 'state.adminRecentMatches ? number(state.adminRecentMatches.length) : "—"' in admin_nav, "v2.10.6.23 New matches 24 h unknown/zero distinction is incomplete")
    check("JOIN p2k_g_match_players mp ON mp.match_id=gb.match_id AND mp.board_no=gb.board_no" not in compat, "Multiplicative compatibility board-lineup join returned")
    check("recoverQuickCompleteTransition" in green_repo and "cycle_started_at=NULL,cycle_kind=NULL,stage=?" in green_repo, "v2.10.6 quick_complete recovery is incomplete")
    check("$this->repo->stage('quick_complete')" not in green_worker and "$this->repo->completeCycle($this->achieved,'quick_index_roster')" in green_worker, "Quick cycles can still persist the transient quick_complete marker")
    complete_cycle_block=green_repo[green_repo.index('public function completeCycle'):green_repo.index('public function recoverQuickCompleteTransition')]
    post_analytics_block=green_worker[green_worker.index('private function runPostLockAnalytics'):green_worker.index('public function run()')]
    check('$this->rebuildAnalytics($no);' not in complete_cycle_block, "v2.10.6.16 completeCycle still rebuilds analytics under the Green worker lock")
    check('maybeRebuildAnalytics($this->cycle,300)' in post_analytics_block and 'maybeRebuildAnalytics(300)' in post_analytics_block and 'green_analytics_errors' in post_analytics_block, "v2.10.6.16 post-lock analytics cadence/error isolation is incomplete")
    actual=sorted(p.relative_to(ROOT).as_posix() for p in ROOT.rglob("*") if p.is_file() and "__pycache__" not in p.parts and ".pytest_cache" not in p.parts and p.suffix != ".pyc")
    declared=set(manifest.get("files") or []); actual_set=set(actual)
    unexpected=sorted(actual_set-declared); missing=sorted(declared-actual_set)
    check(not unexpected, f"Package contains undeclared files: {unexpected[:5]}")
    check(not missing or all(x.startswith("assets/images/") for x in missing), f"Manifest non-asset files are missing: {[x for x in missing if not x.startswith('assets/images/')][:5]}")

    source="\n".join(p.read_text(encoding="utf-8",errors="ignore") for p in ROOT.rglob("*") if p.is_file() and p.suffix.lower() in {".html",".htm",".js",".css",".php",".py",".md",".txt",".json"})
    check(not re.search(r"cdn\.jsdelivr\.net|raw\.githubusercontent\.com|github\.io", source, re.I), "GitHub-backed runtime/source reference found")

    subprocess.run(["node", str(ROOT/"tests/run-tests.js")], check=True)
    for js in ROOT.rglob("*.js"): subprocess.run(["node","--check",str(js)],check=True,stdout=subprocess.DEVNULL)
    compile((ROOT/"serve_local.py").read_text(encoding="utf-8"), str(ROOT/"serve_local.py"), "exec")
    retryable_probe = (
        "require 'server/team-points/src/bootstrap.php'; "
        "$e = new \\P2K\\TeamPoints\\RetryableException('probe', 7); "
        "if ($e->retryAfterSeconds !== 7) { exit(1); }"
    )
    subprocess.run(["php", "-r", retryable_probe], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
    for php in ROOT.rglob("*.php"): subprocess.run(["php","-l",str(php)],check=True,stdout=subprocess.DEVNULL)
    print("Package validation passed.")

if __name__=="__main__": main()
