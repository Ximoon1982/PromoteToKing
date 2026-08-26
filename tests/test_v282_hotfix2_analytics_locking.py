from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BUILDER = (ROOT / 'server/team-points/src/AnalyticsBuilder.php').read_text(encoding='utf-8')
REPO = (ROOT / 'server/team-points/src/Repository.php').read_text(encoding='utf-8')


def test_insights_refresh_is_single_writer_and_achievements_are_decoupled():
    # General Insights refresh must never wait behind another PHP worker's rebuild.
    assert 'LOCK_EX | LOCK_NB' in BUILDER
    assert "'refresh_in_progress'=>true" in BUILDER
    assert "'refresh_deferred'=>true" in BUILDER
    assert "'reason'=>'database_lock'" in BUILDER
    assert 'isLockWaitTimeout' in BUILDER

    # Team/Opponent projection watermark is Core-only again; Live/tournaments belong to achievements.
    source = BUILDER.split('public function sourceWatermark', 1)[1].split('public function achievementSourceWatermark', 1)[0]
    assert 'p2k_lr_players' not in source
    assert 'tournaments/archive.json' not in source
    achievement_source = BUILDER.split('public function achievementSourceWatermark', 1)[1].split('private function runtimeRefreshPaths', 1)[0]
    assert 'p2k_lr_players' in achievement_source
    assert 'tournaments/archive.json' in achievement_source

    # The long achievement history scan must not run inside rebuildAll's Insights transaction.
    rebuild_all = BUILDER.split('public function rebuildAll', 1)[1].split('private function rebuildMatchFacts', 1)[0]
    assert 'rebuildAchievements(' not in rebuild_all
    assert 'achievementRows' not in rebuild_all

    # Achievement persistence still has its own refresh domain and remains reachable from profiles/catalogue.
    assert "domain_key='achievements'" in BUILDER
    assert 'refreshAchievementsIfDue' in BUILDER
    assert 'refreshAchievementsForRead' in REPO


def test_match_facts_insert_has_matching_26_columns_26_placeholders():
    block = BUILDER.split('INSERT INTO p2k_an_match_facts(', 1)[1].split(');', 1)[0]
    columns, values = block.split('VALUES', 1)
    column_list = columns.split(')')[0]
    assert len([x for x in column_list.replace('\n', '').split(',') if x.strip()]) == 26
    assert values.count('?') == 26
