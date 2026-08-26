from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
JS = (ROOT / 'assets/js/pages/dashboard-v2.js').read_text(encoding='utf-8')
CSS = (ROOT / 'assets/css/dashboard-v2.css').read_text(encoding='utf-8')
UI = (ROOT / 'ui-v2.html').read_text(encoding='utf-8')


def admin_markup_slice():
    start = JS.index('return `<section aria-label="Administrator dashboard"')
    end = JS.index('</section>`;', start)
    return JS[start:end]


def test_admin_menu_reuses_public_tab_component():
    block = admin_markup_slice()
    assert 'class="dashboard-page-tabs dashboard-admin-category-tabs"' in block
    assert 'dashboard-admin-shell-tabs' not in block
    assert 'dashboard-admin-shell-heading' not in block


def test_admin_intro_copy_removed():
    block = admin_markup_slice()
    assert '<p class="dashboard-eyebrow">Administration</p>' not in block
    assert '<h2 id="adminDashboardTitle">Administrator dashboard</h2>' not in block
    assert 'Operational views grouped around the way Promote to King is administered.' not in block


def test_six_admin_tabs_have_public_style_icons():
    block = admin_markup_slice()
    categories = re.findall(r'data-admin-category="([^"]+)"', block)
    assert categories == ['competitions', 'members', 'team', 'opponents', 'maintenance', 'misc']
    assert block.count('class="dashboard-tab-icon"') == 6
    for label in ['Competitions', 'Members', 'Team', 'Opponents', 'Admin &amp; maintenance', 'Misc']:
        assert f'<span>{label}</span>' in block


def test_admin_actions_are_immediately_below_menu():
    block = admin_markup_slice()
    nav_end = block.index('</nav>')
    actions = block.index('dashboard-admin-shell-menu-actions')
    status = block.index('dashboard-admin-shell-updated')
    assert nav_end < actions < status
    between = block[nav_end:actions]
    assert '<section' not in between
    assert 'OAuth/local admin' in block[actions:status]
    assert 'Refresh live cards' in block[actions:status]


def test_admin_menu_desktop_and_mobile_geometry_matches_public_contract():
    assert '.dashboard-admin-category-tabs{grid-template-columns:repeat(6,minmax(0,1fr));margin:0}' in CSS
    assert '@media (max-width:620px){.dashboard-admin-category-tabs{grid-template-columns:repeat(2,minmax(0,1fr))}}' in CSS
    assert '.dashboard-page-tabs {' in CSS
    assert 'grid-template-columns: repeat(4,minmax(0,1fr));' in CSS
    assert '.dashboard-tab-icon {' in CSS
    assert 'dashboard-admin-shell-tabs' not in CSS


def test_public_navigation_remains_four_items():
    start = UI.index('<nav aria-label="Public sections"')
    end = UI.index('</nav>', start)
    block = UI[start:end]
    assert block.count('data-public-page=') == 4
    assert block.count('class="dashboard-tab-icon"') == 4
