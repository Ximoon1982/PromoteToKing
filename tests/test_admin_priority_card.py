from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ui = (ROOT / 'ui-v2.html').read_text(encoding='utf-8')
dashboard = (ROOT / 'assets/js/pages/dashboard-v2.js').read_text(encoding='utf-8')
finder = (ROOT / 'assets/js/pages/find-match.js').read_text(encoding='utf-8')
css = (ROOT / 'assets/css/dashboard-v2.css').read_text(encoding='utf-8')

assert 'id="dashboardAdminPriorityCard"' not in ui, 'Administrator card content must not ship in public HTML.'
assert 'function ensureAdminPriorityCard()' in dashboard
assert 'if (!state.admin) return null' in dashboard
assert 'ensureAdminPriorityCard();' in dashboard and 'removeAdminPriorityCard();' in dashboard
assert 'adminFlagEnabled' not in dashboard and 'oauthSessionClaimsAdmin' in dashboard, 'v2.10.4 must remove the ?admin UI bypass while retaining OAuth/admin-list verification.'
assert ('displayOnly !== true' in dashboard or 'displayOnly!==true' in dashboard), 'Display-only ?name= previews must not inherit real-account OAuth admin claims.'
assert 'await window.P2K_TEAM_POINTS_CLIENT.connect(username)' not in dashboard, 'Dashboard administrator admission must not open the Team Points database.'
assert 'server/control/public/api.php' in dashboard
assert 'function integratedAdminHref(tool, extra = {})' in dashboard and 'url.searchParams.set("page", "administration")' in dashboard
assert 'expected === 300' in dashboard and 'expected === 3600' in dashboard and 'expected === 86400' in dashboard
assert 'dashboardAdminQueue(processedCache?.entries || matches)' in finder, 'Priority queue must reuse the existing Match Assistant scan.'
for label in ('Below minimum', 'Start within 48 h', 'League recruitment', 'Operational exceptions'):
    assert label in dashboard
assert '.dashboard-admin-priority-card' in css
assert '#ef5c4d' in css or '#ff6152' in css
print('Validated-admin priority queue card tests passed.')
