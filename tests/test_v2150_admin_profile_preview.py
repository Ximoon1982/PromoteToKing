from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_profile_menu_exposes_admin_preview_controls():
    js = read("assets/js/shared/real-oauth.js")
    assert 'params.get("memberView") === "1"' in js
    assert 'getMemberView: () => requestedMemberView' in js
    assert '"Member view"' in js
    assert '"View as member"' in js
    assert 'url.searchParams.set("memberView", "1")' in js
    assert 'url.searchParams.set("name", clean)' in js
    assert 'Signed in as ${session.username}' in js


def test_view_as_member_keeps_real_session_identity():
    js = read("assets/js/shared/real-oauth.js")
    assert 'getSession: () => session ? { ...session } : null' in js
    assert 'getDisplayUsername: () => session ? (requestedDisplayName || session.username) : ""' in js
    assert 'authenticatedUsername: session.username' in js
    assert 'displayOnly: Boolean(requestedDisplayName)' in js


def test_member_view_hides_admin_surfaces_without_revoking_admin_state():
    js = read("assets/js/pages/dashboard-v2.js")
    assert 'const memberView = memberViewRequested();' in js
    assert 'window.P2K_ADMIN_MODE = state.admin;' in js
    assert 'const adminVisible = state.admin && !memberView;' in js
    assert 'if (state.admin && !memberView)' in js
    assert 'removeAdminInterface();' in js
    assert 'removeAdminPriorityCard();' in js
    assert 'dashboardAdministrationTab' in js
    assert 'hidden = !adminVisible' in js


def test_member_view_cleans_admin_route_when_enabled():
    js = read("assets/js/shared/real-oauth.js")
    for key in ["view", "adminCategory", "adminDetail", "adminDetailTab", "adminTool", "adminToolTab", "adminContext"]:
        assert f'url.searchParams.delete("{key}")' in js
    assert 'if (url.searchParams.get("page") === "administration") url.searchParams.set("page", "dashboard")' in js


def test_admin_preview_assets_are_cache_busted():
    html = read("ui-v2.html")
    key = "p2k-2.15.0-admin-preview-1"
    assert f"assets/css/simulated-oauth.css?v={key}" in html
    assert f"assets/js/shared/real-oauth.js?v={key}" in html
    assert f"assets/js/pages/dashboard-v2.js?v={key}" in html
