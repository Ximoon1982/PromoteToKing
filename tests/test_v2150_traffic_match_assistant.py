from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
INTEL=(ROOT/"server/team-points/public/intelligence.php").read_text(encoding="utf-8")
UI=(ROOT/"assets/js/pages/club-intelligence.js").read_text(encoding="utf-8")
BOOT=(ROOT/"server/team-points/src/bootstrap.php").read_text(encoding="utf-8")
HELPER=(ROOT/"server/shared/MatchAssistantUsage.php").read_text(encoding="utf-8")
BUILDER=(ROOT/"tools/release/v2150/build-incremental.sh").read_text(encoding="utf-8")

def test_traffic_response_contains_match_assistant_usage():
    assert "$data['match_assistant']=MatchAssistantUsage::report($root,$days);" in INTEL
    assert "use P2K\\Shared\\MatchAssistantUsage;" in INTEL

def test_usage_reads_existing_shared_log():
    assert "/logs/match-assistant" in HELPER
    assert "matchesFound" in HELPER
    assert "distinctUsers" in HELPER
    assert "lastSearchAt" in HELPER
    assert "UI v1 and UI v2" in HELPER

def test_traffic_ui_exposes_usage_detail():
    for label in ["Match Assistant usage","Match Assistant trend","Match Assistant searched usernames","Recent Match Assistant searches","Avg matches / analysis"]:
        assert label in UI

def test_helper_is_loaded_and_packaged():
    assert "shared/MatchAssistantUsage.php" in BOOT
    for path in ["assets/js/pages/club-intelligence.js","server/team-points/public/intelligence.php","server/team-points/src/bootstrap.php","server/shared/MatchAssistantUsage.php"]:
        assert path in BUILDER
