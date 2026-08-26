from pathlib import Path
import hashlib
import re

ROOT = Path(__file__).resolve().parents[1]
HTML = (ROOT / "ui-v2.html").read_text(encoding="utf-8")
JS = (ROOT / "assets/js/pages/dashboard-v2.js").read_text(encoding="utf-8")
CSS = (ROOT / "assets/css/dashboard-v2.css").read_text(encoding="utf-8")

PUBLIC_SECTION_HASHES = {
    "publicDashboardPanel": "11c42b900a8424a5f511e444745a536a5724594e92cf9e41d4a3cea2ffe74a1a",
    "hallOfFamePage": "1e2fcbad5df9e82da416f1a318dcd451fbc5500c577cf2466a714c5c6e576ba9",
    "teamInsightsPage": "955a1faf96000cec4cd795dd2e11527ec2810e712c4af1ed29364876ae7387ad",
}
TOOLS_BLOCK_HASH = "3459e4f1006163a21aef0635894729205e0674bcc9bda3f0d85c170474f7cb51"


def section_by_id(source: str, element_id: str) -> str:
    start_match = re.search(rf'<section\b[^>]*\bid=["\']{re.escape(element_id)}["\'][^>]*>', source)
    assert start_match, f"section {element_id} missing"
    start = start_match.start()
    pos = start_match.end()
    depth = 1
    token = re.compile(r"<section\b[^>]*>|</section\s*>", re.I)
    for match in token.finditer(source, pos):
        if match.group(0).lower().startswith("<section"):
            depth += 1
        else:
            depth -= 1
            if depth == 0:
                return source[start:match.end()]
    raise AssertionError(f"section {element_id} not closed")


def tools_block(source: str) -> str:
    start = source.index("const tools = [")
    end = source.index("const categoryLabels", start)
    return source[start:end]


def test_release_identity():
    assert (ROOT / "VERSION").read_text().strip() == "2.10.6.12"
    assert (ROOT / "MIGRATION_VERSION").read_text().strip() == "2.10.6.12"
    assert 'version: "2.10.6.12"' in (ROOT / "assets/js/site-config.js").read_text(encoding="utf-8")


def test_public_surfaces_regression_locked():
    for element_id, expected in PUBLIC_SECTION_HASHES.items():
        actual = hashlib.sha256(section_by_id(HTML, element_id).encode("utf-8")).hexdigest()
        assert actual == expected, f"public surface changed: {element_id}"


def test_admin_shell_six_tabs_and_agreed_cards():
    categories = re.findall(r'data-admin-category="([^"]+)"', JS[JS.index("function adminPanelMarkup"):JS.index("function ensureAdminInterface")])
    assert categories == ["competitions", "members", "team", "opponents", "maintenance", "misc"]
    for label in [
        "Competitions", "Members", "Team", "Opponents", "Admin &amp; maintenance", "Misc",
        "Daily Matches", "Multi Club Arenas", "Tournaments", "Team Depth", "Chronology",
        "Aliases & name changes", "Club intelligence", "Opponent intelligence",
        "Diagnostics", "Scheduled Task Control", "Logs", "Storage & Capacity", "Performance",
        "Freshness", "Traffic & visitors", "Lost &amp; found tools",
    ]:
        assert label in JS
    assert "Status · freshness · source are shown on every card." in JS


def test_existing_admin_tool_catalogue_is_unchanged_and_complete():
    block = tools_block(JS)
    assert hashlib.sha256(block.encode("utf-8")).hexdigest() == TOOLS_BLOCK_HASH
    assert block.count("{ category:") == 36
    assert "tools.forEach(tool =>" in JS
    assert 'id="adminToolGrid"' in JS


def test_deep_links_reuse_existing_tools():
    required = [
        'integratedAdminHref("upcoming")',
        'integratedAdminHref("creation")',
        'integratedAdminHref("live-ranks")',
        'integratedAdminHref("intelligence",{adminContext:"depth"})',
        'integratedAdminHref("members")',
        'integratedAdminHref("intelligence",{adminContext:"aliases"})',
        'integratedAdminHref("intelligence",{adminContext:"overview"})',
        'integratedAdminHref("intelligence",{adminContext:"opponents"})',
        'integratedAdminHref("diagnostics")',
        'integratedAdminHref("tasks")',
        'integratedAdminHref("logs")',
        'integratedAdminHref("storage")',
        'integratedAdminHref("intelligence",{adminContext:"performance"})',
        'integratedAdminHref("intelligence",{adminContext:"freshness"})',
        'integratedAdminHref("intelligence",{adminContext:"traffic"})',
        'integratedAdminHref("migration")',
    ]
    for marker in required:
        assert marker in JS, marker


def test_admin_shell_is_admin_only_code_path():
    assert 'if (showAdmin) { adminShellActivate(); loadAdminShellMetrics(); }' in JS
    assert 'if(!host||!byId("adminDashboardPanel")||!state.admin)return;' in JS
    assert "v2.10.6.12 — authenticated Admin-toggle shell only" in CSS
