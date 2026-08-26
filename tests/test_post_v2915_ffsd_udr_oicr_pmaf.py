from pathlib import Path
import json
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def text(rel):
    return (ROOT / rel).read_text(encoding="utf-8")


def test_pmaf_primary_first_and_transient_failures_do_not_fan_out():
    worker = text("server/team-points/src/Worker.php")
    api = text("server/team-points/src/ChessApi.php")
    config = text("server/team-points/config/config.example.php")
    assert "'/matches'" in worker or "'/matches\"" in worker
    assert "catch(RetryableException $exception)" in worker
    assert "PMAF_INVALID_PLAYER_MATCHES_PAYLOAD" in worker
    assert "http\\s+(404|410)" in worker
    assert "http\\s+(401|403|429)" in worker
    assert "/games/archives" in worker
    assert "player_matches_fallback_archive_batch" in worker
    assert "player_matches_fallback_reprobe_seconds" in config
    assert "~/pub/player/[^/]+/games/archives$~" in api


def test_pmaf_archive_discovery_is_bounded_to_known_p2k_matches_and_replayed_after_verification():
    worker = text("server/team-points/src/Worker.php")
    assert "$this->repository->isKnownMatch($this->clubSlug,$matchId)" in worker
    assert "pmaf-archive-match:" in worker
    assert "pmaf_username" in worker and "pmaf_month" in worker
    assert "pmaf_archive_replay_enqueued" in worker
    # Unknown personal-history match IDs must not be blindly expanded into sync_match work.
    fragment = worker[worker.index("A personal game archive contains all clubs"):]
    assert "isKnownMatch" in fragment[:1200]


def test_pmaf_state_is_single_bounded_protected_ledger_and_operates_incrementally():
    state_src = text("server/team-points/src/PlayerMatchesFallbackState.php")
    assert "/player-matches-fallback" in state_src
    assert "/state.json" in state_src
    assert "player_matches_fallback_max_entries" in state_src
    assert "pending_months" in state_src and "scheduled_months" in state_src
    with tempfile.TemporaryDirectory() as tmp:
        script = f'''<?php
        function p2k_tp_username_key(string $u): string {{ return strtolower(trim($u)); }}
        require {json.dumps(str(ROOT / "server/shared/FilesystemCache.php"))};
        require {json.dumps(str(ROOT / "server/team-points/src/PlayerMatchesFallbackState.php"))};
        $s=new \\P2K\\TeamPoints\\PlayerMatchesFallbackState(['runtime_dir'=>{json.dumps(tmp)}],['player_matches_fallback_reprobe_seconds'=>3600]);
        $a=$s->activate('Ximoon','HTTP 404');
        $s->recordArchiveIndex('Ximoon',['2026-08','2026-07','2026-06']);
        $take=$s->takePendingMonths('Ximoon',2);
        $s->markMonthsScheduled('Ximoon',$take);
        $e=$s->entry('Ximoon');
        echo json_encode(['take'=>$take,'remaining'=>count($e['pending_months']??[]),'active'=>$e['active']??false]);
        '''
        result = subprocess.run(["php"], input=script, text=True, capture_output=True, check=True)
        payload = json.loads(result.stdout)
        assert payload["take"] == ["2026-08", "2026-07"]
        assert payload["remaining"] == 1
        assert payload["active"] is True


def test_ffsd_failure_reason_telemetry_liveness_and_semantics():
    refresh = text("assets/js/shared/client-continuous-refresh.js")
    api_client = text("assets/js/shared/api-client.js")
    control = text("server/control/public/api.php")
    ui = text("assets/js/pages/task-control.js")
    assert "failure_reasons" in refresh
    for token in ["http_${status}", "timeout", "gateway_failure", "cache_or_lease", "abort", "observation_rejected:"]:
        assert token in refresh
    assert "p2k-api-observation-results" in api_client and "p2k-api-observation-results" in refresh
    assert "browser_claimable_now" in control and "canonical_server_checks_due" in control
    assert "Canonical server checks due" in ui and "Browser work claimable now" in ui
    assert "No browser-acquisition work is currently claimable" in refresh
    assert "last-resort liveness guard" in refresh
    assert 'persisted.last_error = ""' in refresh


def test_udr_preserves_ui_v2_deep_links_until_admin_resolution():
    dash = text("assets/js/pages/dashboard-v2.js")
    oauth = text("server/team-points/public/oauth.php")
    # Alias routes no longer early-return partial navigation objects.
    assert 'if (requestedPage === "team-insights") { publicPage = "insights"' in dash
    assert 'if (requestedPage === "tournaments") { publicPage = "hall"' in dash
    assert 'return { view: requestedView, publicPage: "insights"' not in dash
    assert "adminResolved: false" in dash
    assert 'page !== "administration" || state.admin || !state.adminResolved' in dash
    assert 'state.publicPage !== "administration"' in dash
    assert 'searchParams.set("ui","v2")' in dash.replace(' ', '')
    assert "/ui-v2.html?ui=v2&oauth=2" in oauth


def test_oicr_full_history_coverage_board_rating_fallback_and_loss_mapping():
    repo = text("server/team-points/src/Repository.php")
    analytics = text("server/team-points/src/AnalyticsBuilder.php")
    assert "result_coverage_percent" in repo
    assert "match_list_complete" in repo and "LIMIT 200" in repo
    assert "canonical_results" in repo and "missing_finished_results" in repo
    assert "['win'=>'wins','draw'=>'draws','loss'=>'losses']" in repo
    assert "$group[$result . 's']" not in repo
    assert "AVG(boards.p2k_rating)" in analytics
    assert "AVG(boards.opponent_rating)" in analytics
    assert "rated_board_count" in analytics
    assert "logic:opponent-coverage-results-v1" in analytics


def test_opponent_urls_are_human_facing_at_read_and_write_boundaries():
    repo = text("server/team-points/src/Repository.php")
    intel = text("server/team-points/src/ClubIntelligenceService.php")
    assert repo.count("chessClubHumanUrl") >= 5
    assert "Repository::chessClubHumanUrl" in intel
    script = f'''<?php
    require {json.dumps(str(ROOT / "server/team-points/src/Repository.php"))};
    echo \\P2K\\TeamPoints\\Repository::chessClubHumanUrl('https://api.chess.com/pub/club/example-club','');
    '''
    result = subprocess.run(["php"], input=script, text=True, capture_output=True, check=True)
    assert result.stdout == "https://www.chess.com/club/example-club"
