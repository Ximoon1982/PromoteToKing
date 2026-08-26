from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
CAT=ROOT/'server/team-points/src/AchievementCatalog.php'
AN=ROOT/'server/team-points/src/AnalyticsBuilder.php'
REPO=ROOT/'server/team-points/src/Repository.php'
WORKER=ROOT/'server/team-points/src/Worker.php'
CORE=ROOT/'server/team-points/sql/core-schema.sql'
MIG=ROOT/'server/team-points/sql/core-migration-v2.9.5.sql'
OAUTH=ROOT/'OAuthTest.php'

NEW_KEYS=[
'collector-25','collector-50','collector-75','collector-100','collector-125',
'rivalry-5','rivalry-10','rivalry-25','rivalry-50',
'opponent-countries-10','opponent-countries-25','opponent-countries-50',
'chess960-matches-10','chess960-matches-50','chess960-matches-100',
'active-months-3','active-months-6','active-months-12',
'large-match-100','large-match-200','large-match-500',
'upset-100','upset-200','upset-400',
'match-score-15','match-score-20','match-two-draws','tournament-medal-set','rivalry-turnaround']

def catalogue_keys():
    return re.findall(r"self::item\('([^']+)'",CAT.read_text())

def test_catalogue_preserves_v294_and_adds_exact_29():
    old=(ROOT/'tests/fixtures/v294_achievement_keys.txt').read_text().splitlines()
    now=catalogue_keys()
    assert len(old)==133 and len(now)==162 and len(now)==len(set(now))
    assert now[:133]==old
    assert now[133:]==NEW_KEYS

def test_collector_is_not_self_referential_or_breadth_input():
    cat=CAT.read_text(); an=AN.read_text()
    assert "'achievement-collector'" in cat
    assert "'achievement-collector'" in cat.split('eligibleBreadthCategories',1)[1]
    assert "!== 'achievement-collector'" in an or "==='achievement-collector'" in an
    assert "collector-125" in an and "achievement-count-threshold" in an

def test_all_new_families_have_authoritative_evaluators():
    s=AN.read_text()
    for key in NEW_KEYS:
        assert key in s, key
    assert 'o.country_code' in s
    assert 'b.p2k_rating' in s and 'b.opponent_rating' in s
    assert "stored-paired-rating" in s
    assert "HAVING stored_games>=2" in s
    assert "$medals[$u]['silver']" in s and "$medals[$u]['bronze']" in s

def test_core10_is_additive_and_stores_country_and_board_rating_provenance():
    repo=REPO.read_text(); core=CORE.read_text(); mig=MIG.read_text()
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [10,11,12,13,14,15])
    assert 'core-migration-v2.9.5.sql' in repo
    for token in ['country_code','p2k_rating','opponent_rating','rating_source','rating_captured_at']:
        assert token in core and token in mig

def test_worker_prefers_lineup_and_can_backfill_board_game_ratings():
    repo=REPO.read_text(); worker=WORKER.read_text()
    assert "rating_source' => 'match_lineup'" in worker
    assert "rating_source'=>'board_game'" in worker or "rating_source' => 'board_game'" in worker
    assert 'ratingPairForSide' in worker
    assert 'sync_opponent_profile' in worker
    assert 'opponentProfileRefreshCandidates' in repo
    assert 'sync_opponent_profile' in repo
    assert "source === 'match_lineup'" in repo or "source==='match_lineup'" in repo

def test_oauth_poc_is_retained_and_still_only_real_flag_2():
    s=OAUTH.read_text()
    assert "(string)$_GET['oauth'] === '2'" in s
    assert 'Log in with Chess.com' in s and 'Log out' in s
    assert "oauth_extract_username" in s
    assert 'oauth=1' not in s

def test_progress_metrics_cover_incremental_new_families():
    s=(ROOT/'server/team-points/src/ClubIntelligenceService.php').read_text()
    for token in ['earned_non_collector_count','rivalry_max','opponent_countries','chess960_matches','active_month_streak','largest_match_boards','max_upset_delta','best_board_points_x2','two_draws']:
        assert token in s

def test_legacy_breadth_scope_is_frozen_to_original_21_categories():
    cat=CAT.read_text(); an=AN.read_text()
    assert 'legacyBreadthCategories' in cat
    legacy=re.search(r"legacyBreadthCategories\(\): array \{.*?return \[(.*?)\];",cat,re.S)
    assert legacy
    cats=re.findall(r"'([^']+)'",legacy.group(1))
    assert len(cats)==21 and cats[-3:]==['same-day-match-starts','concurrent-games','tournaments']
    assert 'legacyAllTarget' in an and "'groups-all'" in an
