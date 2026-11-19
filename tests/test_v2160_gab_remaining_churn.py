"""Regression guards: unchanged Green facts must not retrigger GAB projection.

No database writes or network access. The candidate capture records only
IDs already selected by GAB's normal bounded reconciliation batches.
"""
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
GREEN=(ROOT/"server/team-points-green/src/GreenRepository.php").read_text()
COMPAT=(ROOT/"server/team-points-green/src/GreenCompatibility.php").read_text()
GAB=(ROOT/"server/team-points-green/src/GreenAnalyticsBootstrap.php").read_text()


def test_match_factual_timestamp_stable_on_reobservation():
    part=GREEN.split("public function storeMatch(",1)[1].split("private function ratingCapObservation(",1)[0]
    assert "UPDATE p2k_g_matches SET updated_at=IF(NOT (payload_hash <=> ?),UTC_TIMESTAMP(),updated_at)" in part
    assert "$q->execute([$hash,(string)($payload['url']??'')" in part
    assert "NOT (hint_hash <=> VALUES(hint_hash))" in part
    assert "needs_refresh=GREATEST(needs_refresh,VALUES(needs_refresh))" in part
    assert "updated_at=UTC_TIMESTAMP()" not in part.split("ON DUPLICATE KEY UPDATE updated_at=IF(NOT (board_url",1)[1].split('")->execute',1)[0]


def test_board_and_game_substantive_timestamps():
    part=GREEN.split("public function storeBoard(",1)[1].split("public function markBoardHttp(",1)[0]
    assert "ON DUPLICATE KEY UPDATE updated_at=IF(NOT (game_index <=> VALUES(game_index))" in part
    assert "NOT (end_epoch <=> VALUES(end_epoch))" in part
    assert part.count("UPDATE p2k_g_boards SET updated_at=IF(NOT (payload_hash <=> ?)")==2
    assert part.count("->execute([$hash,$state,$whiteName?:null")==2
    assert "last_verified_at=UTC_TIMESTAMP()" in part


def test_rating_backfill_timestamp():
    part=GREEN.split("public function storeMaxRatingBackfill(",1)[1].split("public function maxRatingBackfillSnapshot(",1)[0]
    assert "updated_at=IF(NOT (max_rating <=> ?) OR NOT (max_rating_state <=> ?)" in part


def test_bounded_candidate_capture():
    recon=COMPAT.split("public function reconcileCompatibilityBatch(",1)[1].split("public function rebuildAnalytics(",1)[0]
    assert "'candidate_ids'=>$ids" in recon
    lane=GAB.split("if($key==='compat_reconciliation'){",1)[1].split("if($key==='analytics_build')",1)[0]
    assert "'pass_candidate_ids'=>$passCandidates" in lane
    assert "'last_full_pass_candidate_ids'=>$lastFullCandidates" in lane
    assert "count($passCandidates)>128" in lane
    assert "if(!empty($r['pass_drained']))" in lane


def test_exceptions_remain_visible_and_not_counted_as_parity():
    recon=COMPAT.split("public function reconcileCompatibilityBatch(",1)[1].split("public function rebuildAnalytics(",1)[0]
    assert "'remaining'=>$remaining+$blocked" in recon
    assert "'drained'=>$passDrained&&$remaining===0&&$blocked===0" in recon
