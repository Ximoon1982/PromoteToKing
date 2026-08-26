from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = (ROOT / "server/team-points/src/FreshPointsReconstruction.php").read_text(encoding="utf-8")
API = (ROOT / "server/control/public/api.php").read_text(encoding="utf-8")
TASK = (ROOT / "assets/js/pages/task-control.js").read_text(encoding="utf-8")
FRESH = (ROOT / "assets/js/pages/fresh-points-reconstruction.js").read_text(encoding="utf-8")
HTML = (ROOT / "TaskControl.html").read_text(encoding="utf-8")


def test_existing_ongoing_matches_are_not_actionable_result_differences():
    where = PHP[PHP.index("private function clubDifferenceWhere"):PHP.index("private function clubDifferenceOrder")]
    assert "m.match_id IS NULL OR (r.status='finished'" in where
    assert "m.result<>" not in where
    assert "m.is_void<>" not in where


def test_finished_comparator_uses_only_authoritative_scoring_facts():
    assert "COALESCE(m.status,'')<>'finished'" in PHP
    assert "ABS(COALESCE(m.p2k_score,-1000000)-r.p2k_score)>=0.001" in PHP
    assert "ABS(COALESCE(m.opponent_score,-1000000)-r.opponent_score)>=0.001" in PHP
    assert "r.excluded_zero_zero=0 AND COALESCE(m.board_count,-1)<>r.board_count" in PHP
    assert "COALESCE(m.competition_points,-1000000)" in PHP


def test_difference_types_distinguish_result_from_points_only():
    helper = PHP[PHP.index("private function clubDifferenceType"):PHP.index("private function clubRowDiffers")]
    assert "return 'missing_match'" in helper
    assert "return 'final_result_mismatch'" in helper
    assert "return 'points_mismatch'" in helper
    assert "if((string)$row['status']!=='finished')return null" in helper


def test_reconciliation_difference_api_supports_whitelisted_sorting():
    assert "string $sort='point_delta',string $direction='desc'" in PHP
    assert "clubDifferenceOrder" in PHP and "playerDifferenceOrder" in PHP
    assert "$_GET['sort']" in API and "$_GET['direction']" in API
    assert "sort,direction" in FRESH


def test_club_and_player_tables_have_clickable_server_backed_sort_headers():
    for scope, keys in {"club":["match","difference","fresh","core","point_delta"],"player":["member","fresh","core","point_delta"]}.items():
        for key in keys:
            assert f'data-reconciliation-sort="{scope}" data-sort-key="{key}"' in HTML
    assert '[data-reconciliation-sort]' in TASK
    assert 'state.reconciliationSort[scope]={key,direction}' in TASK
    assert 'refreshDifferences?.(scope,100,0,sort.key,sort.direction,effect)' in TASK


def test_ui_labels_only_three_club_difference_categories():
    assert '"Missing match"' in TASK
    assert '"Points differ"' in TASK
    assert '"Final result differs"' in TASK
    assert '"Different result"' not in TASK


def test_club_difference_balance_and_effect_filter_are_server_wide():
    assert "string $effect='all'" in PHP
    assert "positive_delta" in PHP and "negative_delta" in PHP and "net_delta" in PHP
    assert "positive_count" in PHP and "negative_count" in PHP and "zero_count" in PHP
    assert "'adds'=>" in PHP and "'removes'=>" in PHP and "'zero'=>" in PHP
    assert "$_GET['effect']" in API
    assert 'effect="all"' in FRESH and 'params:{run_id:runId(),scope,limit,offset,sort,direction,effect}' in FRESH
    assert 'id="reconstructionClubDeltaFilter"' in HTML
    assert 'Adds points' in HTML and 'Removes points' in HTML and 'Zero-point changes' in HTML
    assert 'reconstructionClubDeltaSummary' in TASK
    assert 'state.reconciliationEffect.club' in TASK


def test_point_delta_sort_is_signed_so_ascending_surfaces_losses():
    order = PHP[PHP.index("private function clubDifferenceOrder"):PHP.index("private function playerDifferences")]
    assert "'point_delta'=>\"({$points}-COALESCE(m.competition_points,0))\"" in order
    assert "'point_delta'=>\"ABS({$points}-COALESCE(m.competition_points,0))\"" not in order
