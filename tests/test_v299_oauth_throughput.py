from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding="utf-8", errors="ignore")


def test_real_oauth_shared_transport_has_deep_adaptive_reservoir():
    api = text("assets/js/shared/api-client.js") + text("assets/js/shared/api-oauth-context.js")
    real = text("assets/js/shared/real-oauth.js")
    assert "const OAUTH_LOGICAL_CONCURRENCY = 256;" in api
    assert ("const OAUTH_INITIAL_RATE_CPS = 8;" in api) or ("const OAUTH_INITIAL_RATE_CPS = 30;" in api)
    assert ("const OAUTH_MIN_CONNECTION_CAP = 32;" in api) or ("const OAUTH_MIN_CONNECTION_CAP = 3;" in api)
    if (ROOT / 'server/team-points/src/OAuthRateCoordinator.php').exists():
        assert "const OAUTH_GATEWAY_BATCH_SIZE = 32;" in api
        assert "const OAUTH_GATEWAY_MAX_POSTS = 6;" in api
        assert "oauthGatewayActivePosts" in api
    else:
        assert "Math.max(32, Math.min(512, Math.ceil(oauthGatewayRateTarget * 4)))" in api
        assert "Math.max(24, Math.min(480, Math.ceil(requestedRate * 3)))" in api
    assert "const concurrency = oauthContext.isBearerMode() ? requestedConcurrency" in api
    assert "configuredConcurrency = oauthBearerMode ? oauthContext.logicalConcurrency : 1" in api
    assert "observeOAuthBatch" in api
    assert ("p2k-oauth-gateway-tuning-v2" in api) or ("p2k-oauth-gateway-tuning-v3" in api) or ("p2k-oauth-gateway-tuning-v4" in api)
    assert "waitForRealOAuthDecision" in api
    assert "window.P2K_REAL_OAUTH_READY" in api
    assert "window.P2K_REAL_OAUTH_READY = readyPromise" in real
    assert "readyResolve?.(session ? { ...session } : null)" in real


def test_server_oauth_gateway_is_curl_multi_and_pressure_adaptive():
    oauth = text("server/team-points/src/OAuthSession.php")
    assert "array_slice($requests,0,512)" in oauth
    assert "curl_multi_init" in oauth
    assert "CURLMOPT_MAX_TOTAL_CONNECTIONS" in oauth
    assert "CURLMOPT_MAX_HOST_CONNECTIONS" in oauth
    assert "Authorization: Bearer " in oauth
    assert "if($status===429)" in oauth
    if (ROOT / 'server/team-points/src/OAuthRateCoordinator.php').exists():
        coordinator = text('server/team-points/src/OAuthRateCoordinator.php')
        assert "OAuthRateCoordinator" in oauth
        assert "rate-limit-boundary" in coordinator
        assert "transient-pressure" in coordinator
    else:
        assert "$currentLimit=max(1,(int)floor($currentLimit/2))" in oauth
        assert "elseif($status===0||$status>=500)" in oauth
    assert "RuntimeTelemetry::record('chess_api_batch'" in oauth


def test_speed_fetch_card_feeds_authenticated_transport_deeply():
    js = text("assets/js/shared/client-continuous-refresh.js")
    api = text("server/control/public/api.php")
    task = text("assets/js/pages/task-control.js")
    assert "max_tasks: apiMode().configured > 1 ? 256 : 48" in js
    assert "Promise.allSettled(tasks.map(task => runTask" in js
    assert "min(512, (int)($body['max_tasks'] ?? 48))" in api
    assert "max1600" not in api  # source is PHP; verify by exact wider scan below
    assert "api_concurrency_controlled_elsewhere'=>true" in api
    assert "keeps the due-work reservoir fed" in task
    assert "learns a paced calls-per-second target" in task


def test_acamr_has_real_oauth_high_feed_policy_but_public_remains_small():
    php = text("server/team-points/public/acamr-plan.php")
    cfg = text("server/team-points/config/config.example.php")
    assert "acamr_claims_per_pulse']??3" in php
    assert "acamr_oauth_claims_per_pulse']??48" in php
    assert "acamr_oauth_pulse_ms']??5000" in php
    assert "acamr_oauth_scan_batch_size']??600" in php
    assert "min(96" in php and "min(1200" in php
    assert "'acamr_oauth_claims_per_pulse' => 48" in cfg
    assert "'acamr_oauth_pulse_ms' => 5000" in cfg
    assert "'acamr_oauth_scan_batch_size' => 600" in cfg


def test_router_preserves_real_oauth_two_for_all_tools():
    js = text("assets/js/pages/site-tabs.js")
    assert 'parameters.get("oauth") === "2"' in js
    assert "function oauthMode()" in js
    assert 'url.searchParams.set("oauth", String(mode))' in js
    assert 'url.searchParams.set("oauth", "1")' not in js
    assert "if (key === \"find\")" not in js[js.index("function pageURL"):js.index("function selectedKey")]


def test_every_api_client_html_surface_loads_real_oauth_and_member_refresh():
    offenders_real = []
    offenders_refresh = []
    for p in list(ROOT.glob("*.html")) + list(ROOT.glob("*.htm")):
        s = p.read_text(encoding="utf-8", errors="ignore")
        if "assets/js/shared/api-client.js" in s and "assets/js/shared/real-oauth.js" not in s:
            offenders_real.append(p.name)
        if "assets/js/shared/simulated-oauth.js" in s and "assets/js/shared/authenticated-member-refresh.js" not in s:
            offenders_refresh.append(p.name)
    assert offenders_real == []
    assert offenders_refresh == []


def test_match_analysis_surfaces_no_longer_hard_cap_oauth_workers():
    find = text("assets/js/pages/find-match.js")
    upcoming = text("assets/js/shared/upcoming-analysis-core.js")
    creation = text("assets/js/pages/match-creation-analyzer.js")
    assert find.count("P2K_API_CLIENT.processPriority") >= 2
    assert "concurrency: 4" not in find and "concurrency:4" not in find
    assert upcoming.count("P2K_API_CLIENT.processPriority") >= 2
    assert "Sequential requests are intentional" not in upcoming
    assert creation.count("P2K_API_CLIENT.processPriority") >= 2


def test_challenge_recruitment_and_tournament_ingest_use_shared_scheduler():
    challenge = text("assets/js/pages/challenge-list-assistant.js")
    recruit = text("RecruitmentDemandPlanner.html")
    tournament = text("TournamentManagement.html")
    assert challenge.count("P2K_API_CLIENT.processPriority") >= 3
    assert "Promise.all([" in challenge
    assert "window.P2K_API_CLIENT?.processPriority" in recruit
    assert "Math.min(4, urls.length)" not in recruit
    assert "window.P2K_API_CLIENT?.json" in recruit
    assert "adaptiveProcess" in tournament
    assert "client?.processPriority" in tournament
    assert "client.jsonDetailed" in tournament
    # 550ms pacing survives only inside the explicit no-shared-client legacy fallback.
    assert "const client=sharedApiClient();" in tournament
    assert "if(client){" in tournament
    assert "await paced();" in tournament


def test_feature_pages_do_not_pass_fixed_numeric_processpriority_concurrency():
    candidates = []
    for base in [ROOT / "assets/js/pages", ROOT / "assets/js/shared"]:
        for p in base.glob("*.js"):
            if p.name == "api-client.js":
                continue
            s = p.read_text(encoding="utf-8", errors="ignore")
            if re.search(r"processPriority\([\s\S]{0,1400}?concurrency\s*:\s*\d+", s):
                candidates.append(p.relative_to(ROOT).as_posix())
    for p in list(ROOT.glob("*.html")) + list(ROOT.glob("*.htm")):
        s = p.read_text(encoding="utf-8", errors="ignore")
        if re.search(r"processPriority\([\s\S]{0,1400}?concurrency\s*:\s*\d+", s):
            candidates.append(p.name)
    assert candidates == []


def test_live_ranks_and_opponent_admin_server_steps_can_use_session_bearer():
    oauth = text("server/team-points/src/OAuthSession.php")
    live_ep = text("server/team-points/public/live-ranks-admin.php")
    live = text("server/team-points/src/LiveRanksService.php")
    opp = text("server/team-points/public/opponents-admin.php")
    js = text("assets/js/pages/team-points-features.js")
    assert "batchForAuthorizedRequest" in oauth
    assert "PHP_SAPI==='cli'||empty($_COOKIE[self::SESSION_NAME])" in oauth
    assert "OAuthSession::batchForAuthorizedRequest" in live_ep
    assert "oauth_concurrency" in live_ep
    assert "pending_profile" in live and "batchFetcher" in live
    assert "OAuthSession::batchForAuthorizedRequest" in opp
    assert "retry_error" in opp
    assert "observeOAuthBatch" in js
    assert "Math.ceil(rate * 4)" in js
    assert "oauth_concurrency: oauth.target" in js


def test_fake_oauth_and_public_mode_remain_serial():
    fake = text("assets/js/shared/simulated-oauth.js")
    api = text("assets/js/shared/api-client.js") + text("assets/js/shared/api-oauth-context.js")
    assert "apiConcurrent: false" in fake
    assert "setConcurrentMode?.(false)" in fake
    assert "configuredConcurrency = oauthBearerMode ? oauthContext.logicalConcurrency : 1" in api
    assert "if (oauthContext.isBearerMode()) return" in api
    assert "Math.min(maxConcurrency, configuredConcurrency(), adaptiveConcurrency())" in api


def test_guest_copy_points_to_real_oauth_not_fake_mode():
    ui = text("ui-v2.html")
    dash = text("assets/js/pages/dashboard-v2.js")
    assert "?oauth=2" in ui
    assert "?oauth=2" in dash
    assert "Enable the existing Chess.com login with ?oauth=1" not in dash


def test_v299_release_contract_preserves_schema_and_serial_fallbacks():
    import json
    assert text("VERSION").strip() in {"2.9.9","2.9.10","2.9.11","2.9.12","2.9.12","2.9.13","2.9.14", '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    manifest = json.loads(text("site-manifest.json"))
    assert manifest["version"] in {"2.9.9","2.9.10","2.9.11","2.9.12","2.9.12","2.9.13","2.9.14", '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'} and manifest["releaseVersion"] == manifest["version"]
    release = manifest["v299Release"]
    assert release["base"] == "2.9.8"
    assert release["coreSchema"] == 11 and release["analyticsSchema"] == 6
    assert release["schemaChange"] is False and release["catalogTotal"] == 162
    assert release["oauthInitialTarget"] == 8
    assert release["oauthLogicalConcurrency"] == 256
    assert release["gatewayRequestReservoir"] == 512
    assert release["clientRefreshAuthenticatedMaxTasks"] == 256
    assert release["fakeOAuthSerial"] is True and release["loggedOutSerial"] is True
    assert release["cronUsesUserOAuthToken"] is False
    assert release["cronCadenceChanged"] is False
