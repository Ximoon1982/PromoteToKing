from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')


def test_release_identity():
    assert text('VERSION').strip()=='2.10.6.9'
    assert text('MIGRATION_VERSION').strip()=='2.10.6.9'
    assert 'version: "2.10.6.9"' in text('assets/js/site-config.js')
    assert "public const VERSION = '2.10.6.9';" in text('server/team-points-green/src/GreenConfig.php')


def test_public_router_no_longer_requires_gab_completion():
    router=text('server/team-points/src/PublicReadDatabase.php')
    assert 'SELECT public_read_target FROM p2k_g_state' in router
    assert 'Green public reads were selected before GAB reached ready state.' not in router
    assert "p2k_core_schema_version" in router and ">= 17" in router
    assert "p2k_analytics_schema_version" in router and ">= 9" in router
    assert 'fail-closed' in router


def test_cutover_api_uses_only_technical_prerequisites_as_hard_gate():
    api=text('server/team-points-green/public/api.php')
    assert "if($action==='switch-green-reads')" in api
    assert "if($action==='make-green-primary')" in api
    assert "if($action==='rollback-blue-reads')" in api
    assert "'green_core_schema_17'" in api and "'green_analytics_schema_9'" in api
    assert "'allowed'=>(bool)$technical['ready']" in api
    assert 'Green validation is not ready; migration phase was not changed.' not in api
    assert 'Green public-read adapter/GAB is not ready; migration phase was not changed.' not in api
    assert "['public_read_target'=>'green','migration_phase'=>'green_reads_both_writing','worker_target'=>'both','client_ingest_target'=>'both']" in api
    assert "['public_read_target'=>'green','migration_phase'=>'green_primary','worker_target'=>'green','client_ingest_target'=>'green']" in api
    assert "['public_read_target'=>'blue','migration_phase'=>'shadow_writing','worker_target'=>'both','client_ingest_target'=>'both']" in api


def test_continuous_runtime_conditions_are_advisory_not_technical():
    api=text('server/team-points-green/public/api.php')
    technical=api[api.index('$technicalReadiness='):api.index('$cutoverStatus=')]
    validation=api[api.index('$greenValidation='):api.index('$adapterValidation=')]
    for token in ['no_unknown_matches','no_pending_boards','current_match_facts_within_gffl_slo']:
        assert token in validation
        assert token not in technical
    assert "warning_count" in api and "warnings" in api


def test_task_control_has_simple_cutover_buttons_and_useful_metrics():
    html=text('TaskControl.html')
    js=text('assets/js/pages/task-control.js')
    for button in ['greenSwitchReads','greenMakePrimary','greenRollbackReads']:
        assert f'id="{button}"' in html
    assert 'Advanced routing controls' in html
    for label in ['Green switch','Blue rollback','Advisories','Unknown / boards pending','Current facts over SLO','GABCRF','Compatibility smoke','Last completed cycle']:
        assert f'greenMetric("{label}"' in js
    assert 'Switch is allowed. Advisories (do not block)' in js
    assert 'switch-green-reads' in js and 'make-green-primary' in js and 'rollback-blue-reads' in js


def test_validate_green_runs_smoke_without_turning_it_into_a_gate():
    api=text('server/team-points-green/public/api.php')
    assert '$cutover=$cutoverStatus(null,true)' in api
    assert "'read_cutover_allowed'=>(bool)$cutover['allowed']" in api
    assert "'read_cutover_ready'=>(bool)$cutover['clean']" in api
    js=text('assets/js/pages/task-control.js')
    assert 'Compatibility smoke was run; advisories do not block the switch.' in js
