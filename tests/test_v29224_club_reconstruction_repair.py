from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8',errors='ignore')

def test_identity_and_schema_unchanged():
    assert text('VERSION').strip() in {'2.9.22.4','2.9.22.5','2.9.22.6','2.9.22.7'}
    repo=text('server/team-points/src/Repository.php')
    assert ('CORE_SCHEMA_VERSION = 15;' in repo) or ('CORE_SCHEMA_VERSION = 16;' in repo)
    assert 'ANALYTICS_SCHEMA_VERSION = 7;' in repo

def test_club_match_normalization_uses_endpoint_boards_only():
    js=text('assets/js/pages/fresh-points-reconstruction.js')
    assert 'const boards=Number(payload?.boards||0);' in js
    block=js[js.index('function normalizedMatchRow'):js.index('async function resolveMatchRows')]
    assert 'players.length' not in block
    assert 'payload?.board_count' not in block
    assert 'boards<=0' in block
    php=text('server/team-points/src/FreshPointsReconstruction.php')
    assert "isset($payload['boards'])" in php
    assert "$payload['board_count']" not in php
    assert "if($boards<=0" in php

def test_club_scoring_is_only_wdl_times_endpoint_boards_and_zero_zero_excluded():
    php=text('server/team-points/src/FreshPointsReconstruction.php')
    assert "5*board_count" in php
    assert "2*board_count" in php
    assert "excluded_zero_zero=0" in php
    assert "$result==='win'?5*$boards:($result==='draw'?2*$boards:0)" in php
    for metric in ["'wins'=>", "'draws'=>", "'losses'=>", "'valid'=>"]:
        assert metric in php

def test_ready_run_has_repair_actions_without_refetching_successes():
    php=text('server/team-points/src/FreshPointsReconstruction.php')
    api=text('server/control/public/api.php')
    js=text('assets/js/pages/fresh-points-reconstruction.js')
    ui=text('TaskControl.html')+text('assets/js/pages/task-control.js')
    assert 'recalculateClub' in php
    assert "payload_json IS NOT NULL" in php
    assert "reconstruction-recalculate-club" in api
    assert "reconstruction-club-issues" in api
    assert 'retryClubIssues' in js and 'command:"repair"' in js
    for marker in ['reconstructionRecalculateClub','reconstructionRetryClubIssues','reconstructionViewClubIssues','reconstructionClubIssueRows']:
        assert marker in ui

def test_apply_controls_use_track_specific_or_incremental_reconciliation():
    js=text('assets/js/pages/task-control.js')
    if 'reconstructionApplyClub' in js:
        assert 'clubClean' in js and 'playerClean' in js
    else:
        assert 'data-reconciliation-apply' in js
        assert 'reconstructionFinalizeClub' in js
        assert 'reconstructionFinalizePlayer' in js
