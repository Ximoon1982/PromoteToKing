from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]
def text(rel): return (ROOT / rel).read_text(encoding='utf-8', errors='ignore')

REJECTED = {'first-match','first-point','matches-10','matches-50','matches-100','matches-250','matches-500'}


def test_acsr_pack_is_promoted_in_v2914():
    assert text('VERSION').strip() in {'2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    acsr = text('assets/js/shared/active-convergence-refresh.js')
    assert 'Active Convergence & Self-Refresh Pack (ACSR)' in acsr
    assert 'P2K_ACSR' in acsr and 'pulseControllers' in acsr
    assert 'navigator?.locks?.request' in acsr and 'localStorage' in acsr
    assert 'visibilitychange' in acsr and "window.addEventListener('online'" in acsr
    assert "continuous.pulse?.(reason)" in acsr
    assert "acamr.pulse || acamr.restart" in acsr


def test_acsr_preserves_server_authority_and_canonical_queue_coalescing():
    api = text('server/control/public/api.php')
    continuous = text('assets/js/shared/client-continuous-refresh.js')
    assert ("'mode'=>'acsr-aggressive'" in api or "'mode'=>'acsr-canonical-drain'" in api)
    assert "'canonical_facts_server_verified'=>true" in api
    assert "'canonical_queue_coalescing'=>true" in api
    assert "'cron_authoritative_fallback'=>true" in api
    assert "client-refresh-worker-pulse" in api
    assert "new Worker($pdo, $repository, new ChessApi($repository), $lane)" in api
    assert "$minimumViableSeconds" in api and "min(36" in api
    assert "if ($registry->isPaused($taskKey))" in api
    assert 'AUTHORITATIVE_PULSE_MIN_MS' in continuous
    assert 'maybePulseAuthoritativeWorker' in continuous


def test_acsr_diagnostics_expose_controller_planner_and_two_freshness_views():
    repo = text('server/team-points/src/Repository.php')
    continuous = text('assets/js/shared/client-continuous-refresh.js')
    task = text('assets/js/pages/task-control.js')
    for token in (
        "'canonical_due_now'", "'canonical_scheduled_later'",
        "'operational_due_now'", "'operational_scheduled_later'",
        "'canonical_fresh_percent'", "'operational_fresh_percent'",
        "'canonical_converged'", "'operational_converged'",
    ):
        assert token in repo
    assert 'controller_state' in continuous and 'planner' in continuous and 'convergence' in continuous
    for label in ('ACSR controller','Canonical server checks due','Browser work claimable now','Canonical scheduled later','Operational convergence','Canonical convergence','Authoritative pulses'):
        assert label in task


def test_acamr_and_aggressive_refresh_publish_leader_standby_and_pulse_state():
    acamr = text('assets/js/shared/authenticated-member-refresh.js')
    continuous = text('assets/js/shared/client-continuous-refresh.js')
    assert 'controller_state' in acamr and 'standby' in acamr
    assert 'planned_claims' in acamr and 'planned_tasks' in acamr and 'next_pulse_ms' in acamr
    assert 'pulse,' in acamr
    assert 'controller_state' in continuous and 'standby:' in continuous
    assert 'worker_pulses' in continuous and 'last_worker_pulse_lane' in continuous


def test_foreground_surfaces_opt_into_acsr_without_parent_child_double_refresh():
    ui = text('ui-v2.html'); dash = text('assets/js/pages/dashboard-v2.js'); achievements = text('TournamentAchievementBadgesDemo.html'); tournaments = text('Tournaments.html'); insights = text('TeamInsights.html'); task = text('TaskControl.html')
    assert any(f'active-convergence-refresh.js?v={v}' in ui for v in ['2.9.16','2.9.17','2.9.18','2.9.19','2.9.20','2.9.21','2.9.22'])
    assert any(f'active-convergence-refresh.js?v={v}' in task for v in ['2.9.16','2.9.17','2.9.18','2.9.19','2.9.20','2.9.21','2.9.22'])
    for key in ('dashboard-team','dashboard-personal','dashboard-hall-active'): assert f'acsr.register("{key}",' in dash
    assert "state.hallSubtab === \"daily\"" in dash and "state.hallSubtab === \"live\"" in dash
    assert 'hall-achievements' in achievements and 'hall-tournaments' in tournaments and 'team-insights' in insights
    assert 'id="achievementsFrame"' in ui and 'id="tournamentsFrame"' in ui


def test_achievement_families_are_only_folded_level_and_groups_are_static():
    cat = text('server/team-points/src/AchievementCatalog.php')
    dash = text('assets/js/pages/dashboard-v2.js')
    standalone = text('TournamentAchievementBadgesDemo.html')
    assert 'familyDefinitions' in cat and "'family'" in cat and "'family_label'" in cat
    assert '<details class="p2k-achievement-family"' in dash
    assert 'p2k-achievement-group-static' in dash
    assert '<details class="p2k-achievement-group"' not in dash
    assert '<details class="achievement-family"' in standalone
    assert '<section class="achievement-group"' in standalone
    assert '<details class="achievement-group"' not in standalone


def test_achievement_display_bars_use_progressive_track_above_ten():
    dash = text('assets/js/pages/dashboard-v2.js')
    standalone = text('TournamentAchievementBadgesDemo.html')
    assert 'if(count>10)' in dash.replace(' ', '')
    assert 'p2k-achievement-display-track' in dash
    assert 'p2k-achievement-segments' in dash
    assert 'if(count>10)' in standalone.replace(' ', '')
    assert 'display-track' in standalone and 'display-segments' in standalone


def test_rejected_matches_and_team_points_artwork_is_placeholder_only():
    cat = text('server/team-points/src/AchievementCatalog.php')
    provenance = json.loads(text('assets/images/achievements/ARTWORK_PROVENANCE_v2.9.2.json'))
    entries = {row['key']: row for row in provenance['items']}
    assert {key for key,row in entries.items() if row.get('status') == 'rejected-placeholder'} == REJECTED
    for key in REJECTED:
        assert f"self::placeholder('{key}')" in cat
        assert (ROOT / f'assets/images/achievements/placeholders/{key}.svg').is_file()
        assert not (ROOT / f'assets/images/achievements/masters/{key}.png').exists()
        assert not (ROOT / f'assets/images/achievements/thumbs/128/{key}.webp').exists()
        assert not (ROOT / f'assets/images/achievements/mini/64/{key}.webp').exists()


def test_achievement_challenges_are_compact_fixed_width_and_explain_criterion_on_hover():
    dash = text('assets/js/pages/dashboard-v2.js')
    css = text('assets/css/dashboard-v2.css')
    assert 'const criterion=String(c.criteria||c.description' in dash
    assert 'title="${escapeHTML(criterion)}"' in dash
    assert ' remaining' not in dash[dash.index('data-home-challenge'):dash.index('data-home-challenge') + 1200]
    assert '.p2k-challenge-row{grid-template-columns:minmax(0,1fr) 150px' in css
    assert 'border:0!important' in css
    assert '.p2k-challenge-row progress{width:150px!important' in css


def test_dashboard_action_and_hall_layout_cleanup():
    ui = text('ui-v2.html')
    assert 'id="openUnifiedProfile" type="button">profile →</button>' in ui
    assert 'id="openUnifiedProfileDaily"' not in ui and 'id="openUnifiedProfileLive"' not in ui
    assert 'id="openHallOfFame" type="button">View Daily Ranks →</button><button class="dashboard-rank-link dashboard-player-games-link" id="explorePlayerGames" type="button">Games →</button>' in ui
    daily = ui[ui.index('data-hall-panel="daily"'):ui.index('data-hall-panel="live"')]
    live = ui[ui.index('data-hall-panel="live"'):ui.index('data-hall-panel="tournaments"')]
    assert daily.index('dashboard-hall-summary') < daily.index('dashboard-hall-page-card')
    assert live.index('dashboard-hall-summary') < live.index('dashboard-hall-page-card')
    assert 'dashboard-modal-head' not in daily and 'dashboard-modal-head' not in live


def test_achievement_tournament_and_team_insights_width_cleanup():
    achievements = text('TournamentAchievementBadgesDemo.html')
    tournaments = text('Tournaments.html')
    insights = text('TeamInsights.html')
    css = text('assets/css/dashboard-v2.css')
    assert '.p2k-embedded main{width:100%;max-width:none;margin:0;padding:0}' in achievements
    assert '<section class="summary"' not in achievements
    assert 'id="status"' not in achievements
    assert '<section class="hero"' not in achievements
    assert '.toolbar .catalog-action{margin-left:auto}' in achievements
    assert '<section class="hero"' not in tournaments and 'Internal tournament history' not in tournaments
    assert '<section class="metrics">' in tournaments
    assert 'html.p2k-embedded .page{width:100%;max-width:none;margin:0;padding:0}' in insights
    assert '#teamInsightsPage,#teamInsightsPage>[data-insights-panel="team"],#teamInsightsPage .dashboard-integrated-frame{width:100%;max-width:none;min-width:0}' in css


def test_live_rank_rename_warning_is_removed():
    hall = text('assets/js/pages/dashboard-hall.js')
    live = text('assets/js/pages/live-ranks-page.js')
    for source in (hall, live):
        assert 'possible renamed usernames remain included pending correction' not in source
        assert 'Live ranks loaded. 14 possible renamed usernames remain included pending correction.' not in source
