from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def read(p): return (ROOT/p).read_text(encoding="utf-8")

def test_v2130_opponent_rating_cap_and_unbounded_summary_contracts():
    schema=read("server/team-points-green/sql/core-schema.sql")
    green=read("server/team-points-green/src/GreenRepository.php")
    compat=read("server/team-points-green/src/GreenCompatibility.php")
    repo=read("server/team-points/src/Repository.php")
    ui=read("assets/js/pages/dashboard-insights.js")
    assert "max_rating SMALLINT UNSIGNED NULL" in schema
    assert "$maxRatingRaw=$settings['max_rating']" in green
    assert "time_control=?,max_rating=?,start_epoch=?" in green
    assert "'max_rating'=>is_numeric($m['max_rating']??null)" in compat
    block=repo[repo.index("public function publicOpponentProfile"):repo.index("/** League and season roll-up")]
    assert "JOIN p2k_g_matches g ON g.match_id=metadata.match_id" in block
    for label in ["'Open'","'U1800'","'U1600'","'U1400'","'U1200'","'U1000'"]:
        assert label in block
    assert 'FROM (".$playerScopeSql.") opponent_players' in block
    assert "LIMIT 250" in block
    assert "'unique_players'=>count($players)" not in block
    assert "Win rate by match rating cap" in ui
