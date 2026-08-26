from pathlib import Path
import os
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8', errors='ignore')


def test_release_identity_and_additive_core5_migration():
    assert text('VERSION').strip() in {'2.8.5','2.8.6','2.8.7','2.8.8','2.8.8.1', '2.8.8.2', '2.8.8.3','2.8.9','2.8.10','2.8.11','2.9.0','2.9.1','2.9.2','2.9.4','2.9.5','2.9.7','2.9.8','2.9.9','2.9.10','2.9.11','2.9.12','2.9.13','2.9.14', '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    repo = text('server/team-points/src/Repository.php')
    migration = text('server/team-points/sql/core-migration-v2.8.5.sql')
    schema = text('server/team-points/sql/core-schema.sql')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [6,7,8,9,10,11,12,13,14,15]) and any(f'ANALYTICS_SCHEMA_VERSION = {v}' in repo for v in [5,6,7])
    assert 'core-migration-v2.8.5.sql' in repo
    for marker in ['avatar_url','profile_url','country_code','profile_status','avatar_checked_at','profile_updated_at','p2k_tp_state','core_generation','members_last_observed_at','club_index_last_observed_at']:
        assert marker in migration and marker in schema
    assert 'VALUES(5)' in migration and 'VALUES(5)' in schema
    for destructive in ['DROP TABLE','TRUNCATE TABLE','DELETE FROM p2k_tp_members','DELETE FROM p2k_tp_match_metadata']:
        assert destructive not in migration.upper()


def test_cron_freshness_is_injected_inside_long_running_jobs():
    repo = text('server/team-points/src/Repository.php')
    loop = text('server/team-points/src/CronLoop.php')
    assert '$now%300' in repo and "'sync_club_matches'" in repo and "priority-discovery:cron-club-index:" in repo
    assert '$now%1800' in repo and "'sync_roster'" in repo and "priority-discovery:cron-roster:" in repo
    assert 'queueCronFreshness' in loop
    assert loop.index('queueCronFreshness') < loop.index('$this->worker->run')


def test_worker_runs_before_analytics_and_historical_recovery_is_player_maintenance():
    cron = text('server/team-points/public/cron.php')
    coordinator = text('server/team-points/src/CronMaintenanceCoordinator.php')
    worker = text('server/team-points/src/Worker.php')
    run = "$loop->execute($chainId,'cron',$workerBudget)"
    assert cron.index(run) < cron.index('(new CronMaintenanceCoordinator')
    assert 'refreshIfDue' in coordinator and 'refreshAchievementsIfDue' in coordinator
    assert "normalizedLane() !== 'club'" in worker
    assert 'match_summary_backfill_batch_size' in worker and 'min(25' in worker
    sync_members = worker[worker.index('private function syncMembers'):worker.index('private function syncPlayer')]
    assert '$networkOnly = !empty($payload' in sync_members
    assert '$this->api->json($url, $networkOnly)' in sync_members


def test_staggered_cron_schedule_and_task_expectations():
    cron = text('CRON_SETUP_v2.8.5.md')
    registry = text('server/shared/TaskRegistry.php')
    assert '*/5 * * * *' in cron
    assert '2-59/10 * * * *' in cron
    assert '7,37 * * * *' in cron
    assert '17 * * * *' in cron
    assert "'team-points-club'" in registry and "'expected_interval_seconds' => 300" in registry
    tournament = registry[registry.index("'tournaments'"):]
    assert "'expected_interval_seconds' => 600" in tournament


def test_crontab_reset_script_is_destructive_only_on_explicit_execution_and_installs_four_jobs(tmp_path):
    script = ROOT / 'reset-install-cron-v2.8.5.sh'
    assert os.access(script, os.X_OK)
    source = script.read_text(encoding='utf-8')
    assert '$CRONTAB_BIN -r' in source
    assert 'cron-club.php' in source and 'cron-player.php' in source
    fake = tmp_path / 'bin'; fake.mkdir()
    state = tmp_path / 'crontab.txt'
    (fake / 'curl').write_text('#!/usr/bin/env bash\nexit 0\n')
    (fake / 'crontab').write_text(f'''#!/usr/bin/env bash\nset -e\nSTATE={state!s}\nif [[ "${{1:-}}" == "-r" ]]; then rm -f "$STATE"; exit 0; fi\nif [[ "${{1:-}}" == "-l" ]]; then cat "$STATE" 2>/dev/null || true; exit 0; fi\ncp "$1" "$STATE"\n''')
    os.chmod(fake/'curl', 0o755); os.chmod(fake/'crontab', 0o755)
    env = dict(os.environ); env['PATH'] = str(fake) + os.pathsep + env.get('PATH','')
    result = subprocess.run([str(script),'TEAMTOKEN','SHAREDTOKEN','https://example.test'], cwd=ROOT, env=env, text=True, capture_output=True)
    assert result.returncode == 0, result.stderr
    installed = state.read_text()
    assert installed.count('/usr/bin/curl') == 0  # fake resolved curl path is intentionally used
    assert installed.count('curl') >= 4
    assert installed.count('cron-club.php') == 1 and installed.count('cron-player.php') == 1
    assert installed.count('server/tournaments/public/cron.php') == 1
    assert installed.count('track-upcoming-league-matches') == 1
    assert 'TEAMTOKEN' in installed and 'SHAREDTOKEN' in installed


def test_browser_observations_prioritize_server_verification_without_writing_canonical_facts():
    obs = text('server/team-points/src/ObservationIngestor.php')
    worker = text('server/team-points/src/Worker.php')
    assert "'verification'=>'server_required'" in obs
    assert "'sync_roster'" in obs and "'sync_player_stats'" in obs and "'sync_player_profile'" in obs
    assert 'applyMembersObservation' not in obs
    assert 'storeMemberRatings' not in obs and 'storePlayerProfileSnapshot' not in obs
    assert 'upsertPointEvent' not in obs and 'upsertMatchMetadata' not in obs
    assert "'sync_player_profile' => $this->syncPlayerProfile($payload)" in worker
    assert 'storePlayerProfileSnapshot' in worker
    assert 'competition_points' not in obs.lower()
    assert 'win_probability' not in obs.lower() and 'forecast' not in obs.lower()


def test_monthly_archive_observation_uses_payload_for_relevant_hints_then_server_refetches():
    obs = text('server/team-points/src/ObservationIngestor.php')
    block = obs[obs.index('private function playerArchiveHint'):obs.index('private function usernameFromPlayer')]
    assert "is_array($payload['games']??null)" in block
    assert 'isKnownMatch' in block
    assert "'sync_player_archive'" in block and "'sync_match'" in block
    assert 'upsertPointEvent' not in block and 'refreshBoardStateFromEvents' not in block
    assert "'verification'=>'server_required'" in block


def test_browser_observation_dedupe_is_signature_and_ttl_based():
    js = text('assets/js/shared/api-client.js')
    assert 'const observedUrls = new Map()' in js
    assert 'OBSERVATION_REUSE_MS = 10 * 60 * 1000' in js
    assert 'previous.signature === signature' in js
    assert 'timestamp - previous.at < OBSERVATION_REUSE_MS' in js
    assert 'compactObservationPayload' in js and '.filter(game => game' in js


def test_avatar_strategy_is_visible_batch_only_persistent_and_long_lived():
    page = text('TournamentAchievementBadgesDemo.html')
    cards = text('server/team-points/public/player-cards.php')
    repo = text('server/team-points/src/Repository.php')
    dash = text('assets/js/pages/dashboard-v2.js')
    oauth = text('assets/js/shared/simulated-oauth.js')
    assert 'const PAGE_SIZE=12' in page and 'page_size:String(PAGE_SIZE)' in page
    assert 'Promise.allSettled([fetchAvatars(newRows),hydrateMedals(newRows)])' in page
    assert '30*86400000' in page
    assert 'item.miniature||item.icon' in page  # prefer browser-optimized achievement miniatures
    assert 'count($usernames)>12' in cards
    assert '$refreshAge=30*86400' in cards
    assert 'playerProfileSnapshots' in cards and 'storePlayerProfileSnapshot' in cards
    assert 'gateway->status()' not in cards
    assert 'avatar_url' in repo and 'avatar_checked_at' in repo
    assert "avatar_url=CASE WHEN ?=1 THEN NULLIF(?,'')" in repo  # authoritative profile checks can detect avatar removal
    assert 'cacheMode: "network-only"' in oauth
    profile_block = dash[dash.index('async function openUnifiedPlayerProfile'):dash.index('  function membersTableColumns', dash.index('async function openUnifiedPlayerProfile'))]
    assert ('cacheMode:"network-only"' in profile_block or 'cacheMode: "network-only"' in profile_block)
    assert profile_block.index('showInsightsModal') < min(i for i in [profile_block.find('cacheMode:"network-only"'), profile_block.find('cacheMode: "network-only"')] if i >= 0)
    assert "cacheMode:'network-only'" in page and 'window.P2K_API_CLIENT?.json' in page


def test_public_reads_do_not_migrate_or_trigger_read_time_rebuilds():
    repo = text('server/team-points/src/Repository.php')
    assert repo.count('refreshAnalyticsForRead(') == 1
    assert repo.count('refreshAchievementsForRead(') == 1
    public_reads = [
        'team-insights.php','members-insights.php','matches-insights.php','opponents.php',
        'achievements.php','achievement-players.php','player-profile.php','opponent-profile.php',
        'league-seasons.php','match-detail.php','player-cards.php'
    ]
    for name in public_reads:
        s = text('server/team-points/public/' + name)
        assert 'upgradeExistingSchema' not in s, name
        assert 'refreshAnalyticsForRead' not in s, name
        assert 'refreshAchievementsForRead' not in s, name


def test_generation_freshness_is_constant_time_after_core5_upgrade():
    builder = text('server/team-points/src/AnalyticsBuilder.php')
    repo = text('server/team-points/src/Repository.php')
    function = builder[builder.index('public function sourceWatermark'):builder.index('/** Achievement sources')]
    assert 'SELECT core_generation FROM p2k_tp_state' in function
    assert "return 'generation:' . $generation" in function or "return 'generation:' . $generation . '|logic:" in function
    assert 'touchCoreGeneration' in repo
    assert 'p2k_tp_state' in repo


def test_materialized_members_and_public_insights_are_cached_and_sql_paginated():
    repo = text('server/team-points/src/Repository.php')
    materialized = repo[repo.index('private function publicMemberInsightsMaterialized'):repo.index('public function publicPlayerProfile')]
    assert 'p2k_an_player_totals' in materialized
    assert ' LIMIT ' in materialized and ' OFFSET ' in materialized
    for endpoint in ['team-insights.php','members-insights.php','matches-insights.php','opponents.php']:
        s = text('server/team-points/public/'+endpoint)
        assert 'ResponseCache' in s and 'jsonCacheable' in s
    opponent = repo[repo.index('public function publicOpponentStats'):repo.index('public function publicAchievementCatalog')]
    assert 'LIMIT 15' in opponent


def test_gateway_status_and_public_response_cache_are_bounded():
    fs = text('server/shared/FilesystemCache.php')
    stats = fs[fs.index('public function stats()'):fs.index('public function root()')]
    assert 'gzdecode' not in stats and 'file_get_contents($path)' not in stats
    assert '.stats-cache.json' in stats and "'inventory_mode'=>'cached_size_only'" in stats
    rc = text('server/team-points/src/ResponseCache.php')
    assert 'LOCK_EX|LOCK_NB' in rc
    assert 'staleUsable' in rc and "purge(14*86400,$this->maxBytes,$this->maxEntries)" in rc
    assert "glob($this->root.'/*.lock')" in rc


def test_tournament_public_archive_is_etag_cacheable():
    s = text('server/tournaments/public/tournaments.php')
    assert "header('ETag: ' . $etag)" in s
    assert "header('Last-Modified: '" in s
    assert 'stale-while-revalidate=600' in s
    assert 'HTTP_IF_NONE_MATCH' in s and 'http_response_code(304)' in s


def test_same_origin_rejects_missing_origin_and_referer():
    auth = text('server/team-points/src/Auth.php')
    assert auth.count("'ORIGIN_REQUIRED'") >= 2
    assert "$rejectMissingOrigin && $origin === '' && $referer === ''" in auth


def test_void_zero_zero_matches_are_raw_but_excluded_from_all_analytics():
    builder = text('server/team-points/src/AnalyticsBuilder.php')
    repo = text('server/team-points/src/Repository.php')
    # Raw match facts intentionally retain is_void for traceability.
    facts = builder[builder.index('private function rebuildMatchFacts'):builder.index('private function rebuildPlayerMonthly')]
    assert 'is_void' in facts
    # Every derived materialization must exclude void records.
    for fn, nxt in [
        ('private function rebuildPlayerMonthly','private function rebuildPlayers'),
        ('private function rebuildPlayers','private function rebuildClubTotals'),
        ('private function rebuildClubTotals','private function rebuildDaily'),
        ('private function rebuildDaily','private function rebuildOpponents'),
        ('private function rebuildOpponents','private function rebuildAchievements'),
    ]:
        block = builder[builder.index(fn):builder.index(nxt)]
        assert (
            'is_void=0' in block or 'is_void = 0' in block or "m.is_void=0" in block
            or 'p2k_tp_match_summaries' in block
        )
    achievements = builder[builder.index('private function rebuildAchievements'):]
    assert achievements.count('m.is_void=0') >= 2
    # Public match analytics, profile participation, opponents and league seasons exclude void records.
    assert repo.count('is_void=0') + repo.count('is_void = 0') >= 15
    match_insights = repo[repo.index('public function publicMatchInsights'):repo.index('public function publicMemberInsights')]
    assert 'm.is_void=0' in match_insights
    profile = repo[repo.index('public function publicPlayerProfile'):repo.index('public function publicAchievementCatalog')]
    assert 'is_void' in profile
    leagues = repo[repo.index('public function publicLeagueSeasons'):]
    assert 'metadata.is_void=0' in leagues


def test_static_images_have_long_cache_without_caching_html():
    ht = text('.htaccess')
    assert r'png|jpe?g|webp|gif|svg|ico' in ht
    assert 'max-age=2592000' in ht
    assert r'html?|json' in ht and 'no-store' in ht
