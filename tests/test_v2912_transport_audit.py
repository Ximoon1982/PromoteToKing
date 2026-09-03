from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(rel):
    return (ROOT / rel).read_text(encoding="utf-8", errors="ignore")


def test_server_shared_rate_coordinator_contract():
    src = text("server/team-points/src/OAuthRateCoordinator.php")
    assert "class OAuthRateCoordinator" in src
    assert ("INITIAL_RATE = 8.0" in src) or ("INITIAL_RATE = 30.0" in src)
    assert "MAX_RATE = 120.0" in src
    assert "MAX_RESERVATION_AHEAD_SECONDS = 0.025" in src
    assert "unsafe * 0.92" in src
    assert "reserveLaunch" in src and "feedback(" in src and "snapshot()" in src
    assert "foreground_until" in src
    assert "permanent4xx" in src
    # Permanent application 4xx responses are evidence of a reachable upstream,
    # not transport pressure / a rate-limit boundary.
    assert "Clean application results (including permanent 4xx)" in src
    assert "do not create an unsafe rate boundary" in src
    # Never persist the bearer token itself in coordinator state.
    assert "hash('sha256'" in src


def test_oauth_session_uses_one_shared_launch_authority_and_continuous_feedback():
    src = text("server/team-points/src/OAuthSession.php")
    assert "new OAuthRateCoordinator($token)" in src
    assert "reserveLaunch($trafficClass)" in src
    assert "trafficClass" in src
    assert "launch_cps" in src and "controller" in src
    # Feedback is emitted in bounded completion windows, not only after an
    # entire slow batch drains.
    assert "count($feedbackStatuses)>=8" in src
    # 429 feedback is immediate so other workers see shared cooldown quickly.
    assert "if($status===429)" in src
    assert "$coordinator->feedback" in src


def test_browser_gateway_is_multi_post_but_server_rate_authoritative():
    # v2.11.3 keeps OAuth gateway policy in the facade while low-level request
    # execution lives in its explicitly loaded transport dependency.
    src = text("assets/js/shared/api-client.js") + "\n" + text("assets/js/shared/api-transport.js")
    assert "OAUTH_GATEWAY_BATCH_SIZE = 32" in src
    assert "OAUTH_GATEWAY_MAX_POSTS = 6" in src
    assert "oauthGatewayActivePosts" in src
    assert "batch?.controller" in src
    assert "traffic_class: trafficClass" in src
    assert "oauthEndpointClass(url)" in src
    # Endpoint-homogeneous / traffic-class-homogeneous batches prevent a slow
    # endpoint class from poisoning an unrelated fast class.
    assert "entry.trafficClass === trafficClass && entry.endpointClass === endpointClass" in src
    # OAuth queue wait is not governed by the ordinary browser request timeout;
    # the PHP transfer timeout begins when cURL really launches the request.
    oauth_pos = src.index("if (oauthSessionActive())")
    combine_pos = src.index("const combined = combineSignal", oauth_pos)
    assert oauth_pos < combine_pos
    assert "return await executeOAuthGateway" in src[oauth_pos:combine_pos]
    # Avoid browser-side duplicate global punishment; the server owns cooldown.
    assert "shared server coordinator already applies Retry-After/cooldown" in src


def test_priority_feeder_is_linear_and_bulk_warms_cache():
    src = text("assets/js/shared/api-client.js")
    start = src.index("async function processPriority")
    end = src.index("function diagnostics", start)
    block = src[start:end]
    assert ".sort(" in block
    assert "let cursor = 0" in block
    assert "P2K_API_CACHE?.getMany" in block
    # The old repeated full remaining-queue best-item scan must not return.
    assert "bestIndex" not in block


def test_cache_finished_matches_do_not_create_daily_refresh_storms_and_io_is_batched():
    src = text("assets/js/shared/api-cache.js")
    assert "finishedMatchMaximumAgeMs" in src
    assert "freshFor: CALIBRATION.finishedMatchMaximumAgeMs" in src
    assert "usableFor: CALIBRATION.finishedMatchMaximumAgeMs" in src
    assert "async function getMany" in src
    assert "pendingWrites = new Map()" in src
    assert "entries = [...pendingWrites.values()].slice(0, 128)" in src
    assert "pendingWrites.size >= 64" in src
    assert "writeBatches" in src and "maximumWriteBatch" in src


def test_match_creation_large_run_progress_work_is_bounded():
    src = text("assets/js/pages/match-creation-analyzer.js")
    assert "pendingByStage" in src
    assert "accountSettledRecord" in src
    assert "now - lastProgressPaintAt >= 100" in src
    assert "pendingByStage.all === 0" in src
    assert "pendingByStage.registration === 0" in src


def test_all_known_production_api_surfaces_load_shared_client_and_real_oauth():
    pages = [
        "AnalyzeMatch.html", "AnalyzeMatchModal.html", "AnalyzeMatches.htm",
        "ChallengeListAssistant.html", "DataReconciliation.html", "FindMatch.htm",
        "MatchCreationAnalyzer.htm", "RecruitMatch.html", "RecruitmentDemandPlanner.html",
        "TaskControl.html", "TaskLogs.html", "TeamPointsAdmin.html",
        "TournamentAchievementBadgesDemo.html", "TournamentManagement.html",
        "index.html", "ui-v2.html",
    ]
    for page in pages:
        src = text(page)
        assert "assets/js/shared/api-client.js" in src, page
        assert "assets/js/shared/real-oauth.js" in src, page


def test_no_obvious_naked_chess_api_fetch_in_production_page_modules():
    # Direct Chess.com fallback may remain for standalone/no-client operation,
    # but every module that contains such a fetch must explicitly prefer the
    # shared P2K client in the same module.
    for path in (ROOT / "assets/js").rglob("*.js"):
        src = path.read_text(encoding="utf-8", errors="ignore")
        if "api.chess.com" not in src:
            continue
        if "fetch(" in src:
            assert "P2K_API_CLIENT" in src, str(path.relative_to(ROOT))
