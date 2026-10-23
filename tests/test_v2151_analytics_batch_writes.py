from pathlib import Path

BUILDER = Path("server/team-points/src/AnalyticsBuilder.php")

def test_heavy_analytics_projections_use_batched_inserts():
    text = BUILDER.read_text(encoding="utf-8")
    assert "executeAnalyticsInsertBatch" in text
    assert "p2k_an_match_facts" in text
    assert "p2k_an_player_monthly" in text
    assert "p2k_an_player_totals" in text
    assert "p2k_tp_insight_daily" in text
    assert "p2k_an_opponent_stats" in text
    assert "if(count($batch)>=" in text
