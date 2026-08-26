from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path):
    return (ROOT / path).read_text(encoding="utf-8")


def test_gabcrf_resume_preserves_convergence_bookkeeping():
    src = text("server/team-points-green/src/GreenAnalyticsBootstrap.php")
    assert "lane_key='compat_reconciliation'" in src
    assert "status='pending',error_rows=0,last_error=NULL,completed_at=NULL" in src
    # The special GABCRF resume statement must not reset these fields.
    special = src.split("status='pending',error_rows=0,last_error=NULL,completed_at=NULL", 1)[1].split(";", 1)[0]
    for forbidden in ("processed_rows=0", "changed_rows=0", "cursor_json=NULL"):
        assert forbidden not in special


def test_gabcrf_deadlock_is_transient_and_bounded():
    src = text("server/team-points-green/src/GreenAnalyticsBootstrap.php")
    assert "isTransientSerializationFailure" in src
    assert "40001" in src
    assert "1213" in src
    assert "for($attempt=1;$attempt<=3;$attempt++)" in src
    assert "Transient GABCRF database contention; batch yielded for retry." in src
    assert "gabcrf_transient_retry" in src
    assert "transient_retry'=>true" in src
    assert "last_error'=>null" in src


def test_existing_deadlock_state_self_heals_without_manual_reset():
    gab = text("server/team-points-green/src/GreenAnalyticsBootstrap.php")
    worker = text("server/team-points-green/src/GreenWorker.php")
    assert "resumeTransientErrorIfSafe" in gab
    assert "gab_status='running',gab_phase='compat_reconciliation'" in gab
    assert "if($gabState==='error')$gabBootstrap->resumeTransientErrorIfSafe()" in worker


def test_discovered_match_profile_replaces_only_transient_loading_state():
    shell = text("assets/js/pages/dashboard-v2.js")
    insights = text("assets/js/pages/dashboard-insights.js")
    assert "await openMatchDetail(id,{replaceInitial:true});" in shell
    assert "async function openMatchDetail(matchId, options = {})" in insights
    assert "replace: options.replaceInitial === true" in insights
    # The regular wrapper still defaults to normal stack semantics.
    assert "async function openMatchDetail(matchId, options = {})" in shell


def test_admin_maintenance_label_is_consistent():
    shell = text("assets/js/pages/dashboard-v2.js")
    assert '<span>Maintenance</span>' in shell
    assert 'eyebrow:"Maintenance"' in shell
    assert 'replace("maintenance","Maintenance")' in shell
    assert "Admin &amp; maintenance" not in shell
    assert 'eyebrow:"Admin & maintenance"' not in shell
