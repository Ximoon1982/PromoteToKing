from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = (ROOT / 'server/team-points/src/FreshPointsReconstruction.php').read_text(encoding='utf-8')
JS = (ROOT / 'assets/js/pages/task-control.js').read_text(encoding='utf-8')
HTML = (ROOT / 'TaskControl.html').read_text(encoding='utf-8')


def test_reference_iteration_cleanup_does_not_null_last_result_row():
    assert '}$r=null;' not in PHP
    assert '}$row=null;' not in PHP
    assert 'unset($r);' in PHP
    assert 'unset($row);' in PHP


def test_browser_defensively_filters_malformed_reconciliation_rows():
    assert 'clubRows=(Array.isArray(clubRec.rows)?clubRec.rows:[]).filter(row=>row&&typeof row==="object")' in JS
    assert 'playerRows=(Array.isArray(playerRec.rows)?playerRec.rows:[]).filter(row=>row&&typeof row==="object")' in JS
    assert 'actions=[...(Array.isArray(clubRec.applied)?clubRec.applied:[]),...(Array.isArray(playerRec.applied)?playerRec.applied:[])].filter(row=>row&&typeof row==="object")' in JS


def test_zero_zero_draw_is_cancelled_excluded_even_without_boards():
    assert 'Chess.com uses a 0-0 draw for cancelled team matches' in PHP
    assert "if($zeroZero&&($status==='finished'||$oursDraw||$theirsDraw))" in PHP
    # Board validation must occur after the cancellation return.
    assert PHP.index("if($zeroZero&&($status==='finished'||$oursDraw||$theirsDraw))") < PHP.index("if($boards<=0||!is_array($ours)||!is_array($theirs))")
    assert "'excluded'=>true" in PHP


def test_cancelled_matches_do_not_create_board_count_only_differences():
    assert '(r.excluded_zero_zero=0 AND COALESCE(m.board_count,-1)<>r.board_count)' in PHP
    assert "(!(bool)$row['excluded_zero_zero']&&(int)($db['board_count']??-1)!==(int)$row['board_count'])" in PHP


def test_current_run_can_be_reclassified_without_refetch():
    assert 'id="reconstructionRecalculateClub"' in HTML
    assert 'Reclassify staged Club data' in HTML
    assert 'recalculateClub' in JS


def test_difference_payload_reports_true_issue_total_separately_from_window():
    assert "'issue_total'=>$this->clubIssueCount($runId)" in PHP
    assert "'issue_total'=>$this->playerIssueCount($runId)" in PHP
    assert 'clubRec.issue_total' in JS
    assert 'playerRec.issue_total' in JS
