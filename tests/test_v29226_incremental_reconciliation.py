from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(path):
    return (ROOT / path).read_text(encoding='utf-8')


def test_task_detail_is_lane_local_and_avoids_heavy_queue_history():
    api = text('server/control/public/api.php')
    section = api[api.index("function p2k_control_work_details"):api.index("if ($key === 'match-tracking')")]
    assert 'Repository::summary' in section  # explanatory comment
    assert '$repository->summary(' not in section
    assert '$repository->synchronizationCoverage(' not in section
    assert '$repository->queueCounts(' not in section
    assert '$repository->taskBreakdown(' not in section
    assert 'idx_tp_job_queue' in section
    assert "task_breakdown_deferred" in section


def test_core16_reconciliation_audit_is_additive_and_nonunique_history():
    repo = text('server/team-points/src/Repository.php')
    schema = text('server/team-points/sql/core-schema.sql')
    migration = text('server/team-points/sql/core-migration-v2.9.22.6.sql')
    assert 'CORE_SCHEMA_VERSION = 16' in repo
    assert 'core-migration-v2.9.22.6.sql' in repo
    for source in (schema, migration):
        assert 'p2k_tp_reconstruction_actions' in source
        assert 'idx_tp_reconstruction_action_entity' in source
        assert 'UNIQUE KEY uq_tp_reconstruction_action' not in source


def test_club_reconciliation_is_per_match_and_uses_strict_authoritative_differences():
    src = text('server/team-points/src/FreshPointsReconstruction.php')
    assert "return 'missing_match'" in src
    assert "return 'final_result_mismatch'" in src
    assert "return 'points_mismatch'" in src
    assert 'applyClubDifference' in src
    assert 'skipClubMatchQueue' in src
    assert "i.canonical_key=?" in src
    assert "5*$boards" in src and "2*$boards" in src
    assert 'clubDifferenceWhere' in src


def test_player_reconciliation_is_per_current_member():
    src = text('server/team-points/src/FreshPointsReconstruction.php')
    assert 'applyPlayerDifference' in src
    assert "closing_member=1" in src
    assert 'DELETE FROM p2k_tp_boards WHERE member_id=?' in src
    assert 'skipPlayerMemberQueue' in src
    assert "player-matches:" in src and "player-archive:" in src and "board:" in src


def test_finalize_clears_only_verified_work_and_allows_acquisition_issues():
    src = text('server/team-points/src/FreshPointsReconstruction.php')
    assert "if($remaining>0)" in src
    assert 'finalizeClubQueue' in src and 'finalizePlayerQueue' in src
    assert "r.stage_state='resolved'" in src
    assert "bx.stage_state<>'resolved'" in src
    # Finalization gate is differences, not global unresolved count.
    final = src[src.index('public function finalizeReconciliation'):src.index('private function clubDifferences')]
    assert 'differenceCount' in final
    assert "['unresolved']" not in final


def test_player_archive_fallback_uses_account_age_and_2024_floor():
    js = text('assets/js/pages/fresh-points-reconstruction.js')
    assert 'Date.UTC(2024,0,1)' in js
    assert 'accountJoined' in js
    assert 'archive_months_skipped_by_account_age' in js
    assert '/games/archives' in js


def test_ongoing_games_are_not_reconstruction_issues():
    js = text('assets/js/pages/fresh-points-reconstruction.js')
    # A non-finished match is allowed to resolve even with fewer than two finished games.
    assert 'const complete=matchStatus==="finished"?finished>=2:true' in js
    assert 'stage_state:complete?"resolved":"unresolved"' in js


def test_task_control_has_live_per_entity_actions_not_whole_run_approval():
    html = text('TaskControl.html')
    js = text('assets/js/pages/task-control.js')
    assert 'reconstructionClubDifferenceRows' in html
    assert 'reconstructionPlayerDifferenceRows' in html
    assert 'Finalize Club reconciliation' in html
    assert 'Finalize Player reconciliation' in html
    assert 'Approve &amp; apply Club Points' not in html
    assert 'data-reconciliation-apply' in js
    assert 'applyDifference(scope,entityKey)' in js
    assert 'finalizeReconciliationScope' in js


def test_control_api_exposes_difference_apply_finalize_endpoints():
    api = text('server/control/public/api.php')
    for action in ['reconstruction-differences','reconstruction-actions','reconstruction-apply-difference','reconstruction-finalize']:
        assert action in api


def test_archive_fallback_verifies_candidate_matches_are_p2k_before_staging():
    js = text('assets/js/pages/fresh-points-reconstruction.js')
    assert 'archive_candidate_matches' in js
    assert 'archive_non_p2k_ignored' in js
    assert 'normalizedMatchRow(item.match_id,data,"player-archive-match")' in js
    assert 'findPlayerInMatch(data,u)' in js
    assert 'ignored_non_p2k' in js


def test_player_differences_can_surface_progressively_but_finalize_uses_closing_roster():
    src = text('server/team-points/src/FreshPointsReconstruction.php')
    assert "(rm.opening_member=1 OR rm.closing_member=1) AND rm.stage_state IN ('matches_done','boards_done','complete')" in src
    assert "(opening_member=1 OR closing_member=1) AND stage_state IN ('matches_done','boards_done','complete')" in src
    mark = src[src.index('private function markVerifiedPlayerRows'):src.index('private function finalizeClubQueue')]
    assert 'rm.closing_member=1' in mark
    finalq = src[src.index('private function finalizePlayerQueue'):src.index('public function apply(')]
    assert 'rm.closing_member=1' in finalq
