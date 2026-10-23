from pathlib import Path

AUDIT = Path("server/team-points/bin/post-cutover-audit.php")

def test_insights_checks_compare_materialization_to_analytics_refresh():
    text = AUDIT.read_text(encoding="utf-8")
    assert "'analytics_refreshed_at'=>$all['refreshed_at']??null" in text
    assert "latest_computed_at" in text
    assert "latest_updated_at" in text
    assert "+300>=(strtotime((string)($all['refreshed_at']??''))?:0)" in text
