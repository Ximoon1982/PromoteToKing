from pathlib import Path
import json, re

ROOT=Path(__file__).resolve().parents[1]

def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_identity_and_public_safety_boundary():
    assert text('VERSION').strip()=='2.10.5.4'
    assert text('MIGRATION_VERSION').strip()=='2.10.5.4'
    cfg=text('server/team-points-green/src/GreenConfig.php')
    api=text('server/team-points-green/public/api.php')
    assert "public const VERSION = '2.10.5.4'" in cfg
    assert "'release'=>'2.10.5.4'" in api
    assert "'effective_public_source'=>PublicReadDatabase::source()" in api
    assert "if(!$validation['ready'])GreenConfig::json" in api
    assert "if($target==='green'){$validation=$greenValidation();$adapter=$adapterValidation()" in api
    assert "Green reads are not ready; public source was not changed." in api


def test_gqac_retires_stale_work_and_terminal_boards_are_not_redirtied():
    repo=text('server/team-points-green/src/GreenRepository.php')
    worker=text('server/team-points-green/src/GreenWorker.php')
    schema=text('server/team-points-green/sql/core-schema.sql')
    assert 'retireIneligibleQuickBoardItems' in repo
    assert 'retireQuickBoardItemsForMatch' in repo
    assert "terminal_http_status=204" in repo
    assert "i.board_no>COALESCE(m.board_count,0)" in repo
    assert "m.time_class,'')<>'daily'" in repo
    assert "m.status NOT IN ('registered','in_progress','finished')" in repo
    assert "strtolower((string)($oldBoard['state']??''))==='finished'" in repo
    assert "$needs=$terminal?0:" in repo
    assert 'gqac_retired_ineligible' in worker
    assert re.search(r'claim_count\s+INT\s+NOT NULL DEFAULT 0', schema)
    assert 'claim_count INT UNSIGNED' not in schema


def test_member_chronology_and_nonblocking_departure_profile_checks():
    repo=text('server/team-points-green/src/GreenRepository.php')
    worker=text('server/team-points-green/src/GreenWorker.php')
    schema=text('server/team-points-green/sql/core-schema.sql')
    api=text('server/team-points-green/public/api.php')
    admin=text('TeamPointsAdmin.html')
    admin_js=text('assets/js/pages/team-points-admin.js')
    assert 'p2k_g_member_events' in schema
    for event in ('discovered','joined','left','name_changed','rejoined'):
        assert event in schema
    assert "i.username_key=p.username_key AND i.trusted=1" in repo
    assert "recordMemberEvent('left'" in repo
    assert "recordMemberEvent('name_changed'" in repo
    assert 'markDepartureProfileResult' in repo
    assert "in_array($httpStatus,[404,410],true)" in repo
    assert 'fetchDepartureProfile' in worker
    assert '$leaveChecks=8' in worker
    assert "if($action==='member-events')" in api
    assert 'Member chronology' in admin and 'memberChronologyRows' in admin
    assert "loadMemberChronology" in admin_js and "member-events" in admin_js


def test_dashboard_match_assistant_open_intent_survives_iframe_hydration():
    js=text('assets/js/pages/dashboard-v2.js')
    block=js[js.index('function openMatchAssistant('):js.index('function closeMatchAssistant(')]
    assert block.index('state.assistantOpen = true') < block.index('if (!frame || !state.assistantReady)')
    assert 'state.pendingAssistantFilter = normalized' in block
    assert 'if (state.assistantOpen && state.publicPage === "dashboard") openMatchAssistant({ updateHistory: false });' in js
    assert 'p2k-dashboard-apply-filter' in js
    assert 'data-assistant-filter="next7"' in text('ui-v2.html')
    assert 'data-assistant-filter="priority"' in text('ui-v2.html')


def test_admin_production_migration_and_scheduler_accelerator_integration():
    ui=text('ui-v2.html')
    mig=text('TeamPointsMigration.html')
    mig_js=text('assets/js/pages/team-points-migration.js')
    task=text('TaskControl.html')
    task_js=text('assets/js/pages/task-control.js')
    assert 'data-admin-subtab="members"' in ui
    assert 'data-admin-subtab="migration"' in ui
    assert 'adminMembersFrame' in ui and 'adminMigrationFrame' in ui
    for phase in ('blue_primary','shadow_writing','green_validated','green_reads_both_writing','green_primary'):
        assert phase in mig
    assert 'migrationReadiness' in mig and 'rollbackBlue' in mig
    assert 'renderProductionControl' in mig_js
    assert 'p2k-migration-accelerator' in mig_js
    assert 'Green Team Points control' in task
    assert 'data-task-tab="green"' in task
    for control_id in ('greenMigrationPhase','greenWorkerTarget','greenClientTarget','greenForceMode','greenGabMetrics','greenGfflMetrics','greenAcceleratorMetrics','greenCycleProgress','greenInvocationRows'):
        assert f'id="{control_id}"' in task
    assert 'client-continuous-refresh.js' not in task
    assert 'green-accelerator.js?v=2.10.5.4' in task
    assert 'greenAcceleratorStart' in task and 'greenAcceleratorStop' in task
    assert '5 staggered' in task or 'Five staggered' in task
    assert '50 s soft / 55 s hard' in task
    assert 'no scheduled next-run' in task
    assert 'greenAcceleratorStart' in task_js and 'P2K_GREEN_ACCELERATOR' in task_js


def test_green_schema_migration_is_additive_and_scheduler_is_canonical():
    migration=text('server/team-points-green/sql/core-migration-v2.10.5.sql')
    tool=text('server/team-points-green/tools/migrate-v2.10.5.php')
    cron=text('reset-install-green-cron-v2.10.5.sh')
    assert 'DROP TABLE' not in migration.upper() and 'TRUNCATE' not in migration.upper()
    assert 'p2k_g_member_events' in migration and 'claim_count INT NOT NULL DEFAULT 0' in migration
    assert 'initializeSchemas()' in tool
    for minute in range(5): assert f'{minute}-59/5 * * * *' in cron
    assert '--max-time 58' in cron
    assert 'P2K_GSCF_BEGIN' in cron and 'P2K_GSCF_END' in cron


def test_release_docs_are_explicit_about_no_partial_cutover_or_reseed():
    notes=text('RELEASE_NOTES_v2.10.5.md')
    install=text('INSTALL_v2.10.5.md')
    migration=text('MIGRATION_v2.10.5.md')
    assert 'Public reads remain Blue by default' in notes
    assert 'safety-gated' in notes
    assert 'No forced Green reseed' in notes
    assert 'No destructive database reset' in notes
    assert 'assets/images' in install
    assert 'complete public/API compatibility read adapter is not enabled' in migration
