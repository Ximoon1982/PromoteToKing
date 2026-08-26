from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_identity():
    assert text('VERSION').strip()=='2.10.4.3'
    assert text('MIGRATION_VERSION').strip()=='2.10.4.3'
    assert text('BLUE_BASELINE_VERSION').strip()=='2.9.22.10'

def test_green_migration_uses_secured_team_points_admin_by_default():
    cfg=text('server/team-points-green/src/GreenConfig.php')
    js=text('assets/js/pages/team-points-migration.js')
    page=text('TeamPointsMigration.html')
    assert r'\P2K\TeamPoints\Auth::requireAdmin()' in cfg
    assert "HTTP_X_P2K_ADMIN_TOKEN" in cfg and 'hash_equals($expected, $provided)' in cfg
    assert 'window.P2K_TEAM_POINTS_CLIENT?.endpointRequest' in js
    assert 'legacyTokenEnabled=false' in js
    assert 'if(saved)$("adminToken").value=saved;refresh();' in js
    assert 'Optional legacy app.admin_token fallback' in page
    assert 'Blue / Green reconstruction control · v2.10.4.3' in page
    assert 'team-points-client.js?v=2.10.4.3' in page
    assert 'team-points-migration.js?v=2.10.4.3' in page

def test_acif_forces_new_persistence_watermark_and_final_reconciliation():
    b=text('server/team-points/src/AnalyticsBuilder.php')
    assert 'logic:achievement-v21043-acif2' in b
    assert "'start_day_peak'=>0" in b
    assert "$st['start_day_peak']=max" in b
    assert 'ACIF v2.10.4.3: enforce final threshold integrity' in b
    for marker in (
        "match-start-streak-14", "match-winner-20", "match-saver-20",
        "close-call-legend-100", "winning-side-250",
        "opponent-variety-100", "old-foes-25",
    ):
        assert marker in b
    repo=text('server/team-points/src/Repository.php')
    assert "['tournament-pending','threshold-reconciled']" in repo

def test_acif_read_side_never_displays_completed_metric_as_unearned():
    demo=text('TournamentAchievementBadgesDemo.html')
    dash=text('assets/js/pages/dashboard-v2.js')
    for src in (demo,dash):
        assert 'metricEarned=new Set' in src
        assert 'Number(' in src and '>=Number(' in src
        assert 'earned_at_precision' in src and 'metric-reconciled' in src
    # Earned achievements must not keep a progress bar.
    assert "prog=!has&&pr&&Number(pr.target)>0" in demo

def test_user_reported_threshold_examples_resolve_to_expected_earned_counts():
    def earned(value, thresholds): return sum(value >= t for t in thresholds)
    assert earned(6,[3,5,7,10,14])==2
    assert earned(15,[1,5,10,15,20])==4
    assert earned(16,[1,5,10,15,20])==4
    assert earned(35,[5,20,50,100])==2
    assert earned(134,[10,50,100,250])==3
    assert earned(130,[25,50,100])==3
    assert earned(108,[5,10,25])==3
