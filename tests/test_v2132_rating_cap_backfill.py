from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def read(path):
    return (ROOT/path).read_text(encoding='utf-8')

def test_v2132_rating_cap_schema_and_runtime_contract():
    schema=read('server/team-points-green/sql/core-schema.sql')
    green=read('server/team-points-green/src/GreenRepository.php')
    assert "max_rating_state ENUM('unknown','capped','open','unavailable') NOT NULL DEFAULT 'unknown'" in schema
    assert 'idx_g_match_rating_cap (max_rating_state,club_verified,time_class,status,match_id)' in schema
    assert "'fact_source','max_rating','max_rating_state'" in green
    assert "'idx_g_match_rating_cap'" in green
    assert "UPDATE p2k_g_matches SET max_rating_state='capped'" in green
    assert 'private function ratingCapObservation' in green
    assert 'public function storeMaxRatingBackfill' in green
    assert 'public function maxRatingBackfillCandidates' in green
    assert 'public function maxRatingBackfillSnapshot' in green
    assert 'public function markMaxRatingUnavailable' in green

def test_v2132_opponent_profile_never_turns_unknown_into_open():
    repo=read('server/team-points/src/Repository.php')
    start=repo.index('public function publicOpponentProfile')
    end=repo.index('/** League and season roll-up',start)
    block=repo[start:end]
    assert "g.max_rating_state IN ('capped','open')" in block
    assert "g.max_rating IS NULL OR g.max_rating<=0 OR g.max_rating>1800" not in block
    assert "g.max_rating_state='open'" in block
    assert "'max_rating'=>$maxRatingCoverage" in block
    assert "'unknown_finished'" in block
    assert "'unavailable_finished'" in block

def test_v2132_standalone_backfill_is_unlinked_oauth_scheduled_and_scoped():
    page=read('MaxRatingBackfill.php')
    endpoint=read('server/team-points-green/public/max-rating-backfill.php')
    assert 'P2K_API_CLIENT' in page
    assert 'processPriority' in page
    assert 'jsonDetailed' in page
    assert 'cacheMode:"no-store"' in page
    assert 'P2K_TEAM_POINTS_CLIENT' in page
    assert 'Start / resume backfill' in page
    assert 'server/team-points-green/public/max-rating-backfill.php' in page
    assert 'GreenConfig::authorizeAdmin();' in endpoint
    assert 'storeMaxRatingBackfill' in endpoint
    assert 'markMaxRatingUnavailable' in endpoint
    # Standalone means no deliberate navigation integration.
    for path in ['ui-v2.html','assets/js/pages/dashboard-v2.js','assets/js/dashboard/admin-tools.js']:
        p=ROOT/path
        if p.exists():
            assert 'MaxRatingBackfill.php' not in p.read_text(encoding='utf-8')

def test_v2132_release_identity_and_qualification():
    assert read('VERSION').strip()=='2.13.2'
    workflow=read('.github/workflows/p2k-v2132-qualification.yml')
    assert 'release/v2.13.2' in workflow
    assert 'test_v2132_rating_cap_backfill.php' in workflow
    assert 'test_v2132_rating_cap_backfill.py' in workflow
    assert 'Full canonical P2K regression' in workflow
    assert 'Full canonical P2K browser regression' in workflow
