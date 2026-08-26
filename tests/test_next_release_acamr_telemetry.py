from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')


def test_acamr_has_separate_persistent_client_and_browsing_session_ids_with_safe_fallback():
    js=text('assets/js/shared/authenticated-member-refresh.js')
    assert 'CLIENT_KEY = "p2k.acamr.client.v1"' in js
    assert 'SESSION_KEY = "p2k.acamr.session.v1"' in js
    assert 'persistedId("localStorage", CLIENT_KEY' in js
    assert 'persistedId("sessionStorage", SESSION_KEY' in js
    assert 'catch (_) { return randomId(prefix); }' in js


def test_acamr_plan_request_reports_identity_and_previous_fetch_results_only_after_success():
    js=text('assets/js/shared/authenticated-member-refresh.js')
    assert 'client_id: clientId' in js and 'session_id: browsingSessionId' in js
    assert 'result_report: pendingResultReport' in js
    assert 'if (pendingResultReport) pendingResultReport = null;' in js
    post=js[js.index('async function requestPlan'):js.index('function safeTask')]
    assert post.index('if (!response.ok)') < post.index('pendingResultReport = null')


def test_acamr_browser_fetch_results_are_counted_by_work_class():
    js=text('assets/js/shared/authenticated-member-refresh.js')
    for kind in ['matches','stats','archive','roster']:
        assert f'{kind}: {{ fetched_ok: 0, fetch_failed: 0 }}' in js
    assert 'result.status === "fulfilled" ? "fetched_ok" : "fetch_failed"' in js
    assert 'pendingResultReport = { id: randomId("acamr-result"), work_classes: workClasses }' in js


def test_acamr_server_hashes_telemetry_ids_and_accounts_for_all_claimed_members_and_tasks():
    php=text('server/team-points/public/acamr-plan.php')
    assert "'client_hash'=>$clientId!==''?substr(hash('sha256',$clientId),0,24):''" in php
    assert "'session_hash'=>$sessionId!==''?substr(hash('sha256',$sessionId),0,24):''" in php
    assert "'actor_hash'=>substr(hash('sha256',strtolower($actor)),0,16)" in php
    assert "'member_hashes'=>$memberHashes" in php
    assert "'claims'=>count($claims)" in php
    assert "'task_kinds'=>$taskKinds" in php
    assert "INVALID_TELEMETRY_ID" in php


def test_acamr_observation_telemetry_is_explicitly_acamr_only_and_work_classed():
    php=text('server/team-points/public/observe.php')
    assert "if($source==='acamr')" in php
    assert "RuntimeTelemetry::record('acamr_observation'" in php
    assert "$acamr=['observations'=>0" in php and "'work_classes'=>[]" in php
    assert "RuntimeTelemetry::record('acamr_observation',$acamr)" in php
    for kind in ['matches','stats','archive','roster']:
        assert kind in php


def test_acamr_runtime_summary_distinguishes_clients_sessions_users_claimed_members_and_work_classes():
    php=text('server/team-points/src/RuntimeTelemetry.php')
    for field in ['distinct_clients','active_clients_30m','distinct_browsing_sessions','active_browsing_sessions_30m','distinct_actors','distinct_members_claimed','work_classes']:
        assert field in php
    assert "$acamr['claims']+=$claims" in php
    assert "$memberHashes=is_array($r['member_hashes']??null)?$r['member_hashes']:[]" in php
    assert "foreach($memberHashes as $member)" in php
    for field in ['handed_out','browser_fetched_ok','browser_fetch_failed','observations_accepted','authoritative_queued']:
        assert field in php


def test_acamr_intelligence_cross_checks_freshness_and_emits_starvation_failure_stall_warnings():
    php=text('server/team-points/public/intelligence.php')
    assert "RuntimeTelemetry::summary(7)['acamr']" in php
    assert '$s->freshnessCoverage()' in php
    for code in ['POSSIBLE_WORK_CLASS_STARVATION','WORK_CLASS_FETCH_FAILURE','WORK_CLASS_OBSERVATION_STALL']:
        assert code in php
    assert "'depth'=>$s->teamDepthReport()" in php  # preserve newer Team Depth integration


def test_acamr_ui_separates_browser_fetch_success_from_authoritative_convergence():
    js=text('assets/js/pages/club-intelligence.js')
    for label in ['Active ACAMR clients · 30m','Browsing sessions','Authenticated users','Member claims issued','Client observations accepted','Authoritative work queued']:
        assert label in js
    assert '“Fetched OK” is successful browser retrieval, not canonical completion.' in js
    assert 'canonical facts still require authoritative server verification' in js
    assert 'Freshness cross-check' in js
