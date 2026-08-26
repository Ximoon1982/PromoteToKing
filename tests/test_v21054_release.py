from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def read(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_zoned_density_heatmap_contract():
    s=read('assets/js/pages/opponent-balance-analyzer.js')
    assert 'ci-zoned-density-heatmap' in s
    assert 'innerWidth / 9.5' in s and 'innerHeight / 8' in s
    assert 'const kernel = [[1,2,1],[2,4,2],[1,2,1]]' in s
    assert 'const bilinearDensity = (gx, gy) =>' in s
    assert 'const rasterScale = 1.6' in s
    assert 'zoneFor(bilinearDensity(gx, gy))' in s
    assert 'densityCanvas.toDataURL("image/png")' in s
    for label in ['Very low','Low','Medium','High','Very high','Peak']:
        assert label in s
    for color in ['#163c9a','#22b7e6','#5bcf6a','#f3df46','#f08a28','#d92525']:
        assert color in s
    assert 'Math.log10(Math.max(1, Number(row.boards)))' in s
    assert 'ci-balance-ref' in s
    assert '[[28,25,22],[83,55,24]' not in s

def test_dashboard_filtered_assistant_has_dedicated_url_state():
    dash=read('assets/js/pages/dashboard-v2.js')
    finder=read('assets/js/pages/find-match.js')
    assert 'ensureDedicatedMatchAssistant' in dash
    assert 'dashboardAssistant", "1"' in dash
    assert 'dashboardFilter", normalized' in dash
    assert 'assistantFilter' in dash
    assert 'url.hash = ""' in dash
    assert 'if (!state.assistantDedicated)' in dash
    assert 'dashboardPresetFilter = String(dashboardParams.get("dashboardFilter")' in finder
    assert 'dashboardPresetFilter === "next7"' in finder
    assert 'dashboardPresetFilter === "priority"' in finder

def test_auto_cron_tracking_expires_after_24h_without_deleting_history():
    s=read('api/_common.php')
    assert 'MATCH_MONITORING_AUTO_STOP_AFTER_START_SECONDS = 86400' in s
    assert 'function expire_started_automatic_tracking' in s
    assert "(string)($entry['source'] ?? '') !== 'automatic-league'" in s
    assert "$entry['followed'] = false" in s
    assert "$entry['autoStopReason'] = 'started-over-24h'" in s
    assert 'write_follow_registry($registry)' in s
    # Stop future tracking only; snapshot files are deliberately untouched.
    body=s[s.index('function expire_started_automatic_tracking'):s.index('function tracking_references')]
    assert 'unlink(' not in body and 'rmdir(' not in body

def test_green_cycle_duration_and_quick_progress_observability():
    repo=read('server/team-points-green/src/GreenRepository.php')
    ui=read('assets/js/pages/task-control.js')
    assert 'recentCycleDurations(int $limit=10)' in repo
    assert "FROM p2k_g_cycles WHERE status='completed'" in repo
    assert "'cycle_durations'=>$this->recentCycleDurations(10)" in repo
    assert 'greenMetric("Last cycle",formatDuration(dur.last_duration_seconds)' in ui
    assert 'greenMetric("Average · last 10",formatDuration(dur.average_duration_seconds)' in ui
    assert 'quickMatchesEstimate' in ui and 'quickMatchesProgressCard' in ui
    assert 'isQuickMatches&&String(r.phase_key||"")==="quick_matches"' in ui
    assert 'The Green read adapter is installed' in ui

def test_migration_card_mobile_and_adapter_wording():
    css=read('assets/css/task-control.css')
    mig=read('assets/js/pages/team-points-migration.js')
    assert '.green-control-surface .task-controls{display:grid;grid-template-columns:1fr}' in css
    assert 'The Green compatibility adapter is installed' in mig
    assert 'compatibility read adapter is not enabled' not in mig
