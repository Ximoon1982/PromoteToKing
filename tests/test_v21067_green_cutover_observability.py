from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(path): return (ROOT/path).read_text(encoding="utf-8")

def test_release_identity_and_no_schema_change():
    assert text("VERSION").strip()=="2.10.6.9"
    assert text("MIGRATION_VERSION").strip()=="2.10.6.9"
    assert 'version: "2.10.6.9"' in text("assets/js/site-config.js")

def test_green_local_time_and_cutover_blockers_are_visible():
    js=text("assets/js/pages/task-control.js")
    html=text("TaskControl.html")
    assert "function serverUtcDate" in js
    assert "function localGreenTimestamp" in js
    assert "greenCutoverMetrics" in html and "greenCutoverBlockers" in html
    assert "renderCutoverReadiness" in js and "cutoverWarnings" in js
    assert "waiting for quick_boards" in js

def test_gab_reconciliation_is_convergence_not_fake_fraction():
    gab=text("server/team-points-green/src/GreenAnalyticsBootstrap.php")
    js=text("assets/js/pages/task-control.js")
    assert "progress_mode']='convergence'" in gab
    assert "attempted_rows" in gab and "last_full_pass_remaining" in gab and "pass_no" in gab
    assert 'badge="Converging"' in js
    assert "projection attempts" in js
    assert "elseif($percent>=100.0)$percent=99.9" in gab

def test_read_parity_denominator_is_dynamic_and_gab_finalizes_immediately():
    gab=text("server/team-points-green/src/GreenAnalyticsBootstrap.php")
    assert "setLaneTotal('read_parity',13)" not in gab
    assert "exact check count determined when audit runs" in text("assets/js/pages/task-control.js")
    assert "$count=count($r['checks']??[])" in gab and "$this->setLaneTotal($key,$count)" in gab
    assert "if(!$this->lane())$this->green->core->prepare" in gab
    assert "gab_status='ready',gab_phase='complete'" in gab

def test_status_preserves_full_readiness_as_advisory_after_operational_cutover_change():
    api=text("server/team-points-green/public/api.php")
    assert "'read_cutover_ready'=>(bool)$cutover['clean']" in api
    assert "'read_cutover_allowed'=>(bool)$cutover['allowed']" in api
    assert "'validation'=>$validation" in api and "'adapter'=>$adapter" in api
    assert "Green validation is not ready; migration phase was not changed." not in api
    assert "Green public-read adapter/GAB is not ready; migration phase was not changed." not in api
