from pathlib import Path

BUILDER = Path("server/team-points/src/AnalyticsBuilder.php")

def test_achievement_maintenance_uses_persistent_bounded_user_queue():
    text = BUILDER.read_text(encoding="utf-8")
    start = text.index("public function refreshAchievementsIfDue")
    end = text.index("public function refreshIfNeeded", start)
    block = text[start:end]
    assert "achievementUsersForSweep" in text
    assert "-queue.json" in block
    assert "array_slice($targets,$cursor,20)" in block
    assert "rebuildAchievements($clubSlug,$batch)" in block
    assert "'partial'=>true" in block
    assert "refreshAchievementsIfNeeded($clubSlug)" not in block

def test_targeted_rebuild_filters_heavy_sources_and_mca_cleanup():
    text = BUILDER.read_text(encoding="utf-8")
    start = text.index("private function rebuildAchievements")
    block = text[start:]
    assert "?array $targetUsers = null" in block
    assert "$coreTargetClause" in block
    assert "$mcaTargetClause" in block
    assert "$analyticsTargetClause" in block
    assert "DELETE FROM p2k_an_achievement_unlocks WHERE club_slug=?{$analyticsTargetClause} AND achievement_key IN ($placeholders)" in block
    assert "$q->execute($coreArgs)" in block
    assert "$q->execute($analyticsArgs)" in block
