from __future__ import annotations
import json,re
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
TARGET='2.10.6'

PUBLIC_READ_ENDPOINTS=[
 'achievement-players.php','achievements.php','hall-search.php','insights-health.php','intelligence.php',
 'league-seasons.php','live-ranks.php','match-detail.php','matches-insights.php','member-intelligence.php',
 'members-insights.php','opponent-icons.php','opponent-profile.php','opponents.php','player-cards.php',
 'player-profile.php','player-stats.php','public.php','recent-matches.php','recruitment-pool.php','team-insights.php',
]
AUX_WRITE_ENDPOINTS=['live-ranks-admin.php','miac.php','opponents-admin.php']

def text(rel:str)->str:return (ROOT/rel).read_text(encoding='utf-8')

def test_dashboard_assistant_open_intent_survives_recommendation_hydration():
    js=text('assets/js/pages/dashboard-v2.js')
    assert js.count('if (state.assistantOpen && state.publicPage === "dashboard") openMatchAssistant({ updateHistory: false });') >= 3
    assert 'state.pendingAssistantFilter = normalized' in js
    assert 'p2k-dashboard-full-assistant-ready' in js
    assert 'data-assistant-filter' in js

def test_task_control_is_green_operational_surface_not_blue_team_points_card():
    html=text('TaskControl.html');js=text('assets/js/pages/task-control.js')
    assert 'id="greenSchedulerControl"' in html
    assert 'Green Accelerator' in html and 'GAB' in html and 'GFFL' in html
    assert 'greenAcceleratorMetrics' in html and 'greenInvocationRows' in html and 'greenCycleProgress' in html
    assert 'greenMigrationPhase' in html and 'greenWorkerTarget' in html and 'greenClientTarget' in html and 'greenForceMode' in html
    assert 'greenGfflTarget' in html
    assert 'id="team-points-maintenance"' not in html
    assert 'teamPointsRoutineRefresh' not in js and 'teamPointsFullMemberRepair' not in js and 'teamPointsRawRepair' not in js
    assert 'client-continuous-refresh.js' not in html
    assert 'green-accelerator.js?v=2.10.6' in html
    assert 'set-migration-phase' in js and 'set-worker-target' in js and 'set-client-target' in js and 'set-force-mode' in js
    assert 'start-gab' in js and 'run-gab-now' in js and 'set-gffl' in js and 'run-green-now' in js and 'validate-green' in js
    assert 'last_error' in js and 'completed_at' in js

def test_gab_has_resumable_lanes_and_migrates_historical_domains():
    gab=text('server/team-points-green/src/GreenAnalyticsBootstrap.php')
    schema=text('server/team-points-green/sql/core-schema.sql')
    for lane in ['compat_schema','reference_core','live_ranks','achievement_history','core_projection_members','core_projection_matches','analytics_build','opponent_enrichment','read_parity']:
        assert lane in gab
    for table in ['p2k_tp_opponents','p2k_tp_opponent_aliases','p2k_miac_names','p2k_miac_edges','p2k_miac_canonical_map','p2k_lr_files','p2k_lr_source_rows','p2k_lr_attributions','p2k_an_achievement_unlocks']:
        assert table in gab
    assert 'p2k_g_gab_lanes' in schema and 'p2k_g_gab_external_work' in schema
    assert "status='failed'" in gab and 'failed' in gab
    assert 'smokeTest' in gab

def test_gab_has_priority_over_gffl_and_normal_accelerator_work():
    repo=text('server/team-points-green/src/GreenRepository.php')
    accel=text('assets/js/shared/green-accelerator.js')
    gab_idx=repo.index('planExternal($sidecarLimit')
    gffl_idx=repo.index('gfflPlan($sidecarLimit-count($urls))')
    ordinary_idx=repo.index("in_array($stage,['seed_index_roster','quick_index_roster']")
    assert gab_idx < gffl_idx < ordinary_idx
    assert 'ACCELERATOR_FINITE_RESERVE_MAX' in repo
    assert '$ordinaryLimit=max(0,$limit-count($urls));' in repo
    assert "priorityLane='gab'" in repo and "priorityLane='gffl'" in repo
    assert 'window.P2K_API_CLIENT' in accel
    assert 'GAB has background priority' in accel
    assert 'priority:PRIORITY' in accel
    assert 'Promise.all' in accel

def test_gffl_is_factorized_match_level_hot_lane_with_20_min_default():
    repo=text('server/team-points-green/src/GreenRepository.php')
    schema=text('server/team-points-green/sql/core-schema.sql')
    worker=text('server/team-points-green/src/GreenWorker.php')
    assert 'gffl_target_freshness_seconds' in schema and 'DEFAULT 1200' in schema
    assert 'p2k_g_gffl_match_debt' in schema
    assert 'JSON_ARRAY_APPEND' in repo and 'coalesced_count' in repo and 'obligation_count' in repo
    assert "armGfflMatch((int)$b['match_id'],'board_changed',95,true)" in worker
    assert 'completeGfflMatch' in worker
    assert 'gfflPlan' in worker
    assert 'current_maintenance_over_slo' in repo
    api=text('server/team-points-green/public/api.php')
    assert 'current_match_facts_within_gffl_slo' in api
    assert 'current_match_facts_fresh' not in api

def test_green_compatibility_stays_fresh_after_member_profile_stats_and_match_updates():
    compat=text('server/team-points-green/src/GreenCompatibility.php')
    worker=text('server/team-points-green/src/GreenWorker.php')
    observe=text('server/team-points-green/public/observe.php')
    assert 'public function projectMember(string $username)' in compat
    assert 'projectMemberIfReady' in worker
    assert worker.count('$this->projectMemberIfReady($username)') >= 6
    assert "['match_detail','board_detail','club_roster','player_profile','player_stats']" in observe
    assert 'projectMember((string)$result[\'username\'])' in observe

def test_existing_public_contracts_are_explicitly_green_routable_without_global_database_redirect():
    router=text('server/team-points/src/PublicReadDatabase.php')
    assert 'fail-closed' in router
    assert "public_read_target" in router
    assert "Green public reads were selected before GAB reached ready state." not in router
    assert 'Database::core()' in router and 'Database::analytics()' in router
    for name in PUBLIC_READ_ENDPOINTS:
        body=text('server/team-points/public/'+name)
        assert 'PublicReadDatabase::core()' in body, name
        assert 'PublicReadDatabase::analytics()' in body, name
    for name in AUX_WRITE_ENDPOINTS:
        body=text('server/team-points/public/'+name)
        assert 'PublicReadDatabase::core()' in body, name
        assert 'PublicReadDatabase::analytics()' in body, name
    # Mixed legacy admin/worker API remains explicitly Blue; cutover cannot redirect its writes by accident.
    mixed=text('server/team-points/public/api.php')
    assert 'Database::core()' in mixed and 'Database::analytics()' in mixed

def test_green_primary_disables_legacy_blue_browser_background_writer_and_planner():
    observe=text('server/team-points/public/observe.php')
    plan=text('server/team-points/public/acamr-plan.php')
    for body in [observe,plan]:
        assert 'client_ingest_target' in body
        assert 'green_primary_uses_green_accelerator' in body
    assert "Http::json(['ok'=>true,'accepted'=>0" in observe
    assert "'claims'=>[]" in plan

def test_migration_phase_is_operationally_switchable_and_controls_all_read_write_targets():
    api=text('server/team-points-green/public/api.php')
    assert "if($action==='switch-green-reads')" in api
    assert "if($action==='make-green-primary')" in api
    assert "if($action==='rollback-blue-reads')" in api
    assert "'allowed'=>(bool)$technical['ready']" in api
    assert 'Green validation is not ready; migration phase was not changed.' not in api
    assert "['public_read_target'=>'green','migration_phase'=>$phase,'worker_target'=>'both','client_ingest_target'=>'both']" in api
    assert "['public_read_target'=>'green','migration_phase'=>$phase,'worker_target'=>'green','client_ingest_target'=>'green']" in api
    assert "if($action==='set-client-target')" in api
    assert "if($action==='set-gffl')" in api and 'target_seconds' in api

def test_release_never_resets_or_reseeds_green_for_gab():
    gab=text('server/team-points-green/src/GreenAnalyticsBootstrap.php').lower()
    notes=text('RELEASE_NOTES_v2.10.5.4.md').lower()
    assert 'truncate p2k_g_matches' not in gab
    assert 'drop database' not in gab
    assert 'no green core reseed' in notes or 'does not reseed green core' in notes or 'no reseed' in notes
