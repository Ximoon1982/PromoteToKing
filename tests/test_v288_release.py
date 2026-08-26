from pathlib import Path
import os, re

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')


def test_v288_identity_schema_migration_and_cron_contract():
    assert text('VERSION').strip() in {'2.8.8','2.8.8.1','2.8.8.2','2.8.8.3','2.8.9','2.8.10','2.8.11','2.9.0','2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    repo=text('server/team-points/src/Repository.php')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [6,7,8,9,10,11,12,13,14,15])
    assert any(f'ANALYTICS_SCHEMA_VERSION = {v}' in repo for v in [5,6,7])
    assert 'core-migration-v2.8.8.sql' in repo
    migration=text('server/team-points/sql/core-migration-v2.8.8.sql')
    for col in ['icon_url','icon_checked_at','profile_updated_at']:
        assert col in migration
    assert 'VALUES(6)' in migration
    docs=text('MIGRATION_v2.8.8.md').lower()
    assert 'core schema moves from **5 to 6**' in docs and 'analytics remains schema **5**' in docs and 'no database reset' in docs
    helper=ROOT/'reset-install-cron-v2.8.8.sh'
    assert helper.is_file() and os.access(helper,os.X_OK)
    src=text('reset-install-cron-v2.8.8.sh')
    for token in ['*/5 * * * *','2-59/10 * * * *','7,37 * * * *','17 * * * *']:
        assert token in src


def test_v288_achievement_trigger_links_are_human_chess_urls():
    bootstrap=text('server/team-points/src/bootstrap.php')
    builder=text('server/team-points/src/AnalyticsBuilder.php')
    repo=text('server/team-points/src/Repository.php')
    dashboard=text('assets/js/pages/dashboard-v2.js')
    assert 'function p2k_tp_chess_web_url' in bootstrap
    assert "https://www.chess.com/club/matches/" in bootstrap
    assert "https://www.chess.com/tournament/" in bootstrap
    assert '$sourceUrl=\\p2k_tp_chess_web_url' in builder
    assert "'source_url'=>\\p2k_tp_chess_web_url" in repo
    assert 'function achievementWebURL' in dashboard and 'api.chess.com' in dashboard


def test_v288_hall_default_profile_actions_and_mobile_rank_containment():
    hall=text('assets/js/pages/dashboard-hall.js')
    ui=text('ui-v2.html')
    css=text('assets/css/dashboard-v2.css')
    section=hall[hall.index('function openHallOfFame'):hall.index('return Object.freeze')]
    assert '"achievements"' in section
    assert 'id="openUnifiedProfile"' in ui and '>profile →</button>' in ui
    assert '@media (max-width:520px)' in css or '@media(max-width:520px)' in css
    assert '.dashboard-hall-rank-opener' in css and 'overflow-wrap:anywhere' in css


def test_v288_nested_modal_stack_preserves_dom_and_interactivity():
    js=text('assets/js/pages/dashboard-v2.js')
    section=js[js.index('const insightsModalStack'):js.index('function normalizeTournamentName')]
    assert 'document.createDocumentFragment()' in section
    assert 'nodes=Array.from(body.childNodes)' in section
    assert 'body.replaceChildren(...previous.nodes)' in section
    assert 'previous.focus?.isConnected' in section
    assert 'previous.html' not in section


def test_v288_club_intelligence_implements_requested_recommendations():
    service=text('server/team-points/src/ClubIntelligenceService.php')
    endpoint=text('server/team-points/public/intelligence.php')
    page=text('ClubIntelligence.html')
    js=text('assets/js/pages/club-intelligence.js')
    for symbol in ['teamDepth','memberActivity','freshnessCoverage','anomalies','adminActions','forecasts','captureDailySnapshot','automaticAnomalyScan','opponentProfiles','memberProfile','achievementChallenges']:
        assert symbol in service
    for scope in ['depth','activity','freshness','anomalies','forecast','opponents','snapshots','performance','acamr']:
        assert f"'{scope}'" in endpoint
    for tab in ['depth','acamr','freshness','anomalies','members','opponents','snapshots','forecast','performance']:
        assert f'data-tab="{tab}"' in page
    for phrase in ['Team depth','ACAMR effectiveness','Automatic anomaly detector','Member activity','Opponent intelligence profiles','Historical snapshots / time travel','Explainable forecast','Endpoint performance telemetry']:
        assert phrase in js


def test_v288_intelligence_maintenance_is_automatic_without_new_cron():
    cron=text('server/team-points/src/CronLoop.php')
    coordinator=text('server/team-points/src/CronMaintenanceCoordinator.php')
    setup=text('CRON_SETUP_v2.8.8.md')
    assert 'captureDailySnapshot()' in coordinator and 'automaticAnomalyScan()' in coordinator
    assert "$this->lane === 'club'" in coordinator and 'ClubIntelligenceService' not in cron
    assert 'no ACAMR, Intelligence, snapshot, anomaly or telemetry CRON entry' in setup


def test_v288_acamr_effectiveness_adaptive_allocation_and_scope():
    plan=text('server/team-points/public/acamr-plan.php')
    repo=text('server/team-points/src/Repository.php')
    telemetry=text('server/team-points/src/RuntimeTelemetry.php')
    auth=text('assets/js/shared/authenticated-member-refresh.js')
    observe=text('server/team-points/public/observe.php')
    assert 'acamrCandidateMembers' in plan and 'priority_score' in plan and 'claim_conflicts' in plan
    candidate=repo[repo.index('public function acamrCandidateMembers'):repo.index('public function recoverFailedBoardsToLane')]
    assert "mm.status IN ('in_progress','finished')" in candidate and "'registered'" not in candidate
    assert "'tournaments'=>false" in plan and "'match_registration'=>false" in plan
    assert 'realOAuthSession(session)' in auth and 'simulated && oauthFlagEnabled()' in auth
    assert "in_array($source,['acamr','client_refresh'],true)" in observe
    assert "'acamr_plan'" in telemetry and "'acamr_observation'" in telemetry

def test_v288_recruitment_confidence_availability_contribution_and_personal_home():
    recruit=text('assets/js/pages/recruit-match.js')
    pool=text('server/team-points/public/recruitment-pool.php')
    dash=text('assets/js/pages/dashboard-v2.js')
    ui=text('ui-v2.html')
    assert 'function recruitmentConfidence' in recruit and 'recommendation confidence' in recruit and 'availabilityScore' in recruit
    for key in ['availability_score','activity_class','current_load']:
        assert key in pool
    assert 'personalizedHome' in ui and 'loadPersonalizedHome' in dash
    assert 'member-intelligence.php' in dash and 'Achievement challenges' in dash and 'operational Member Intelligence remains admin/internal only' in dash


def test_v288_cross_feature_unified_player_profile():
    dash=text('assets/js/pages/dashboard-v2.js')
    tournaments=text('Tournaments.html')
    recruit=text('assets/js/pages/recruit-match.js')
    intelligence=text('assets/js/pages/club-intelligence.js')
    assert 'iframe.dashboard-integrated-frame' in dash and 'p2k-open-player-profile' in dash
    assert "window.parent.postMessage({type:'p2k-open-player-profile',username:name}" in tournaments
    assert 'p2k-open-player-profile' in recruit
    assert 'p2k-open-player-profile' in intelligence


def test_v288_opponent_logo_cache_and_top_graph():
    schema=text('server/team-points/sql/core-schema.sql')
    endpoint=text('server/team-points/public/opponent-icons.php')
    repo=text('server/team-points/src/Repository.php')
    insights=text('assets/js/pages/dashboard-insights.js')
    charts=text('assets/js/pages/dashboard-insights-charts.js')
    admin=text('server/team-points/public/opponents-admin.php')
    assert 'icon_url VARCHAR(500)' in schema and 'icon_checked_at DATETIME' in schema
    assert '30*86400' in endpoint or '30 * 86400' in endpoint
    assert 'storeOpponentProfileSnapshot' in repo and 'opponentProfileSnapshots' in repo
    assert 'opponent-icons.php' in insights and 'searchParams.set("slugs"' in insights
    assert 'nativeSVG("image"' in charts and 'row.icon' in charts
    assert 'storeOpponentProfileSnapshot' in admin


def test_v288_admin_tool_collection_exposes_new_tools():
    dash=text('assets/js/pages/dashboard-v2.js')
    for title in [
        'Team depth visualization','Recruitment confidence','Member contribution profiles','Achievement challenges',
        'Opponent intelligence profiles','Data freshness & coverage',
        'Anomaly detector & action queue','Admin action queue','Performance telemetry','Explainable Club Points forecast',
        'Personalized authenticated home','Unified player intelligence','Historical snapshots / time travel'
    ]:
        assert f'title: "{title}"' in dash


def test_v288_endpoint_telemetry_and_long_lived_runtime_data_are_noncanonical():
    telemetry=text('server/team-points/src/RuntimeTelemetry.php')
    bootstrap=text('server/team-points/src/bootstrap.php')
    architecture=text('ARCHITECTURE_v2.8.8.md')
    assert 'recordRequest' in telemetry and 'p50_ms' in telemetry and 'p95_ms' in telemetry and 'memory_peak_bytes' in telemetry
    assert 'register_shutdown_function' in bootstrap and 'RuntimeTelemetry::recordRequest' in bootstrap
    assert 'not a new source of truth' in architecture.lower()
