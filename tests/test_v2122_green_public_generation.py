from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def block(source,start_marker,end_marker):
    start=source.index(start_marker)
    end=source.index(end_marker,start+len(start_marker))
    return source[start:end]

def test_green_public_cache_generation_uses_native_state_without_noisy_updated_at():
    source=(ROOT/'server/team-points/src/Repository.php').read_text(encoding='utf-8')
    section=block(source,'public function publicReadMeta','public function publicReadGenerationToken')
    assert "PublicReadDatabase::source() === 'green'" in section
    assert 'FROM p2k_g_state WHERE club_slug=?' in section
    assert "greenState['cycle_no']" in section
    assert "greenState['discovery_high_watermark']" in section
    assert "greenState['last_analytics_rebuild']" in section
    generation=section[section.index("$coreGeneration='g'"):section.index("$membersObserved=",section.index("$coreGeneration='g'"))]
    assert "updated_at" not in generation
    assert "readState($clubSlug)" in section

def test_response_cache_ttls_are_not_shortened_by_green_generation_migration():
    for rel in [
        'server/team-points/public/matches-insights.php',
        'server/team-points/public/members-insights.php',
        'server/team-points/public/player-profile.php',
        'server/team-points/public/match-detail.php',
        'server/team-points/public/opponent-profile.php',
        'server/team-points/public/league-seasons.php',
    ]:
        source=(ROOT/rel).read_text(encoding='utf-8')
        assert ",120," in source or ", 120," in source, rel
        assert "jsonCacheable" in source, rel
