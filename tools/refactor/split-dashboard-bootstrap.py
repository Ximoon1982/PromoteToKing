#!/usr/bin/env python3
"""Extract dashboard event wiring/startup without altering statement order."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "assets/js/pages/dashboard-v2.js"
TARGET = ROOT / "assets/js/dashboard/dashboard-bootstrap.js"
HTML = ROOT / "ui-v2.html"

source = SOURCE.read_text()
start = source.index("function bindControls() {")
end = source.rindex("})();")
body = source[start:end]

names = """state byId writeNavigationState renderView openUnifiedPlayerProfile ensureIntegratedFrame
openAchievementCatalog showPublicPage selectInsightsSubtab openHallOfFame selectHallSubtab
openMatchAssistant closeMatchAssistant openMatchAssistantWithFilter viewed closeDashboardMatchList
openDashboardMatchList openPlayerGames closePlayerGames selectPersonalMode searchHallUnified resetHallRanks
closeInsightsModal loadTeamData loadPersonalData loadHall loadLiveRanksNative applyBranding applySession
currentSession handleRecommendationMessage getAchievementNavigation openAchievementNav navigationFromURL
renderPersonalCard ensureAdminInterface adminShellActivate renderTools openAchievementDetail""".split()
header = """(function registerDashboardBootstrap(global) {
const modules = global.P2K_DASHBOARD_MODULES = global.P2K_DASHBOARD_MODULES || {};
modules.dashboardBootstrap = {
create(context) {
const {\n  %s\n} = context;
""" % ",\n  ".join(names)
footer = """return { bindControls, configureAcsr, initialize };
}
};
})(window);
"""
TARGET.parent.mkdir(parents=True, exist_ok=True)
TARGET.write_text(header + body + footer)

context = "const dashboardBootstrap = window.P2K_DASHBOARD_MODULES.dashboardBootstrap.create({\n  " + ",\n  ".join(names) + "\n});\n"
SOURCE.write_text(source[:start] + context + source[end:])

html = HTML.read_text()
needle = '<script defer src="assets/js/dashboard/match-list-dialog.js?v=2.11.2"></script>\n'
insertion = needle + '  <script src="assets/js/dashboard/dashboard-bootstrap.js?v=2.11.2" defer></script>\n'
if needle not in html:
    raise SystemExit("dashboard insertion point missing")
HTML.write_text(html.replace(needle, insertion, 1))

for gate_path in (ROOT / "tests/browser_startup_gate_v2810.py", ROOT / "tests/browser_startup_gate_v289.py"):
    gate = gate_path.read_text()
    needle = "page.add_script_tag(path=str(ROOT/'assets/js/dashboard/match-list-dialog.js'))"
    if needle not in gate:
        raise SystemExit(f"browser gate insertion point missing: {gate_path}")
    gate_path.write_text(gate.replace(needle, needle + ";page.add_script_tag(path=str(ROOT/'assets/js/dashboard/dashboard-bootstrap.js'))", 1))
