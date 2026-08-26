from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8')


def test_release_identity():
    assert text('VERSION').strip() == '2.10.6.10'
    assert text('MIGRATION_VERSION').strip() == '2.10.6.10'
    assert 'version: "2.10.6.10"' in text('assets/js/site-config.js')


def test_registration_chart_uses_canonical_registered_records_not_rendered_dates():
    charts = text('assets/js/pages/match-creation-charts.js')
    analyzer = text('assets/js/pages/match-creation-analyzer.js')
    assert 'function readUpcomingRegistrationData()' in charts
    assert 'window.P2K_MATCH_CREATION_CHART_BUCKETS' in charts
    assert 'const records = Array.isArray(buckets.registration) ? buckets.registration : [];' in charts
    assert 'aggregate.matches += 1;' in charts
    assert 'parseDisplayedDate' not in charts
    assert 'monthNumbers' not in charts
    assert 'P2K_MATCH_CREATION_CHART_BUCKETS = {' in analyzer
    assert 'registration: records.registered.map(chartRecord)' in analyzer
    assert 'dateKey: dateKeyFromTimestamp(record?.startTime)' in analyzer


def test_tracking_expires_every_source_after_start_plus_24h_and_explorer_applies_it():
    common = text('api/_common.php')
    assert 'MATCH_MONITORING_AUTO_STOP_AFTER_START_SECONDS = 86400' in common
    assert 'function continuous_tracking_expired' in common
    assert 'function apply_tracking_start_expiry' in common
    assert 'function expire_started_tracking' in common
    expiry = common[common.index('function expire_started_tracking'):common.index('function expire_started_automatic_tracking')]
    assert "['source']" not in expiry
    assert 'continuous_tracking_expired($entry, $now)' in expiry
    assert "['followed'] = false" in common
    assert "['autoStopReason'] = 'started-over-24h'" in common
    refs = common[common.index('function tracking_references'):common.index('function league_references')]
    assert 'expire_started_tracking($registry)' in refs
    assert 'continuous_tracking_expired($ref)' in refs
    explorer = common[common.index('function tracked_records'):common.index('function remove_match_data')]
    assert 'expire_started_tracking($registry)' in explorer
    schedule = common[common.index('function match_monitoring_schedule'):common.index('function decorate_monitoring_reference')]
    assert 'continuous_tracking_expired($entry, $now)' in schedule
    assert "'due' => false" in schedule
    assert "'nextCaptureAt' => null" in schedule


def test_old_matches_are_record_once_not_continuously_refollowed_in_task_control():
    task = text('assets/js/pages/task-control.js')
    admin = text('assets/js/pages/admin-features.js')
    assert 'm.autoStopReason==="started-over-24h"?"Record once":"Follow"' in task
    assert 'Snapshot recorded once. Continuous tracking remains stopped because the match started more than 24 hours ago.' in task
    assert 'expiredByStart ? "Record once" : "Follow"' in admin
    assert 'was recorded once. Continuous tracking remains stopped because it started more than 24 hours ago.' in admin


def test_dashboard_assistant_has_single_loader_visibility_authority():
    js = text('assets/js/pages/dashboard-v2.js')
    assert 'function syncMatchAssistantLoadingState()' in js
    sync = js[js.index('function syncMatchAssistantLoadingState()'):js.index('function revealMatchAssistantFrame')]
    assert 'state.assistantOpen' in sync
    assert 'state.assistantFullReady' in sync
    assert 'frame && !frame.hidden' in sync
    assert 'preparing.hidden = !assistantVisible || frameVisible' in sync
    assert 'recommendationsLoading.hidden = true' in sync
    reveal = js[js.index('function revealMatchAssistantFrame'):js.index('function handleRecommendationMessage')]
    assert 'syncMatchAssistantLoadingState();' in reveal
    opener = js[js.index('function openMatchAssistant('):js.index('function openMatchAssistantWithFilter')]
    assert 'syncMatchAssistantLoadingState();' in opener
    closer = js[js.index('function closeMatchAssistant('):js.index('let dashboardHallModulePromise')]
    assert 'syncMatchAssistantLoadingState();' in closer
    assert 'preparingEarly' not in js
