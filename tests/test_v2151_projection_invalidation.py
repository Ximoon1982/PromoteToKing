from pathlib import Path

BUILDER = Path("server/team-points/src/AnalyticsBuilder.php")

def test_projection_watermark_tracks_content_not_coarse_generation():
    text = BUILDER.read_text(encoding="utf-8")
    start = text.index("public function sourceWatermark")
    end = text.index("public function achievementSourceWatermark", start)
    block = text[start:end]
    assert "core_generation" not in block
    assert "p2k_tp_members" in block
    assert "p2k_tp_match_metadata" in block
    assert "p2k_tp_boards" in block
    assert "p2k_tp_games" in block
    assert "p2k_tp_opponents" in block
    assert "p2k_tp_opponent_aliases" in block
    assert "verified_at" not in block
    assert "last_discovered_at" not in block

def test_general_refresh_coalesces_relevant_source_changes():
    text = BUILDER.read_text(encoding="utf-8")
    assert "'reason'=>'source_coalescing'" in text
    assert "'coalesce_seconds'=>$minimumSeconds" in text
