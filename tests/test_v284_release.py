from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8')

def test_split_cron_lanes_and_compatibility():
    registry=text('server/shared/TaskRegistry.php')
    assert "'team-points-club'" in registry and "'expected_interval_seconds' => 300" in registry
    assert "'team-points-player'" in registry and ("'expected_interval_seconds' => 1800" in registry or "'expected_interval_seconds' => 600" in registry)
    assert (ROOT/'server/team-points/public/cron-club.php').is_file()
    assert (ROOT/'server/team-points/public/cron-player.php').is_file()
    cron=text('server/team-points/public/cron.php')
    assert "P2K_TP_CRON_LANE" in cron and "new Worker($pdo,$repository,new ChessApi($repository),$lane)" in cron
    worker=text('server/team-points/src/Worker.php')
    assert "p2k_team_points_worker_" in worker and "boardTargetJobId" in worker

def test_board_parser_and_failed_recovery():
    worker=text('server/team-points/src/Worker.php')
    assert "foreach (['games', 'game'] as $key)" in worker
    assert 'usernameFromPlayer' in worker and "is_string($player)" in worker
    repo=text('server/team-points/src/Repository.php')
    assert 'recoverFailedBoardsToLane' in repo
    assert "recovered_in_release']='2.8.4'" in repo
    assert "if (!empty($payload['recovered_in_release'])) continue" in repo
    assert 'Synchronization incomplete' in worker

def test_player_reconciliation_is_bounded_and_separate():
    worker=text('server/team-points/src/Worker.php')
    assert 'reconcileMembers' in worker and 'player_reconcile_batch_size' in worker
    assert "normalizedLane() !== 'club'" in worker
    config=text('server/team-points/config/config.example.php')
    assert "'club_cron_expected_interval_seconds' => 300" in config
    assert "'player_cron_expected_interval_seconds' => 1800" in config or "'player_cron_expected_interval_seconds' => 600" in config

def test_observations_route_to_server_verified_canonical_followup():
    obs=text('server/team-points/src/ObservationIngestor.php')
    assert "jobId('club')" in obs and "jobId('player')" in obs
    assert "sync_match" in obs and "sync_board" in obs and "sync_roster" in obs
    assert "sync_player_stats" in obs and "sync_player_profile" in obs
    assert 'applyMembersObservation' not in obs and 'storeMemberRatings' not in obs and 'storePlayerProfileSnapshot' not in obs
    assert (ROOT/'server/team-points/public/observe.php').is_file()
    client=text('assets/js/shared/api-client.js')
    assert 'opportunisticObservation' in client or 'observation' in client.lower()

def test_achievement_date_revision2_is_retained():
    builder=text('server/team-points/src/AnalyticsBuilder.php')
    repo=text('server/team-points/src/Repository.php')
    dash=text('assets/js/pages/dashboard-v2.js')
    assert 'tournament-pending' in builder and 'tournament-finish' in builder
    assert 'tournament-achievement-date-v3' in builder
    assert 'Date pending tournament refresh' in dash

def test_achievement_cards_sort_by_count_desc():
    page=text('TournamentAchievementBadgesDemo.html')
    assert "sort:'achievement_count',direction:'desc'" in page
    repo=text('server/team-points/src/Repository.php')
    assert "'achievement_count'" in repo
    assert "strcasecmp((string)$a['username'],(string)$b['username'])" in repo

def test_hall_achievements_first_and_default():
    html=text('ui-v2.html')
    first=html.index('data-hall-subtab="achievements"')
    assert first < html.index('data-hall-subtab="daily"')
    assert 'class="is-active" data-hall-subtab="achievements"' in html
    js=text('assets/js/pages/dashboard-v2.js')
    assert '? requestedHall : "achievements"' in js

def test_tournament_podium_first_and_real_medals():
    page=text('Tournaments.html')
    assert page.index('data-panel="ranking"') < page.index('data-panel="tournaments"')
    assert 'data-panel="ranking">Podium ranking' in page
    assert "params.get('panel')==='tournaments'?'tournaments':'ranking'" in page
    assert '🥇' in page and '🥈' in page and '🥉' in page
    assert '>●<' not in page

def test_no_schema_reset_for_v284():
    repo=text('server/team-points/src/Repository.php')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [6,7,8,9,10,11,12,13,14,15])
    assert any(f'ANALYTICS_SCHEMA_VERSION = {v}' in repo for v in [5,6,7])
