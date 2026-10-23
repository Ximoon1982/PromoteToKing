from pathlib import Path

AUDIT = Path("server/team-points/bin/post-cutover-audit.php")

def test_insights_checks_compare_materialization_to_analytics_refresh():
    text = AUDIT.read_text(encoding="utf-8")
    assert "'analytics_refreshed_at'=>$all['refreshed_at']??null" in text
    assert "latest_computed_at" in text
    assert "latest_updated_at" in text
    assert "+300>=(strtotime((string)($all['refreshed_at']??''))?:0)" in text

def test_watermark_audit_accepts_recent_live_lag_but_reports_exactness():
    text = AUDIT.read_text(encoding="utf-8")
    assert "$allFresh=$allMatch||$allAge<=900" in text
    assert "$achFresh=$achMatch||$achAge<=28800" in text
    assert "'exact'=>$allMatch" in text
    assert "'exact'=>$achMatch" in text
    assert "'freshness_limit_seconds'=>900" in text
    assert "'freshness_limit_seconds'=>28800" in text
