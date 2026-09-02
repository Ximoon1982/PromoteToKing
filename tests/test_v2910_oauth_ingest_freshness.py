from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8', errors='ignore')

def block(src, start, end):
    a = src.index(start)
    b = src.index(end, a)
    return src[a:b]


def test_real_oauth_reactivates_from_server_session_on_clean_urls_everywhere():
    real = text('assets/js/shared/real-oauth.js')
    api = text('assets/js/shared/api-client.js')
    guard = text('assets/js/shared/admin-page-guard.js')
    tabs = text('assets/js/pages/site-tabs.js')
    dashboard = text('assets/js/pages/dashboard-v2.js')
    assert 'if (requestedSimulatedOAuth) return;' in real
    assert 'refreshSession();' in real
    assert 'if (session || requestedRealOAuth) activateSurface();' in real
    assert 'window.P2K_REAL_OAUTH_READY = readyPromise;' in real
    assert 'if (explicitMode === "1" || oauthBearerMode) return;' in api
    assert 'window.P2K_REAL_OAUTH_READY' in api
    assert 'awaitRealOAuthReady' in guard and 'window.P2K_REAL_OAUTH_READY' in guard
    assert 'async function authenticatedClubAdmin()' in tabs and 'window.P2K_REAL_OAUTH_READY' in tabs
    assert 'applyOAuthContext' in dashboard
    assert 'applyOAuthContext(new URL(frame.dataset.src' in dashboard


def test_every_api_surface_gets_real_oauth_adapter_before_use():
    offenders = []
    for p in list(ROOT.glob('*.html')) + list(ROOT.glob('*.htm')):
        s = p.read_text(encoding='utf-8', errors='ignore')
        if 'assets/js/shared/api-client.js' in s and 'assets/js/shared/real-oauth.js' not in s:
            offenders.append(p.name)
    assert offenders == []


def test_oauth_cookie_and_server_session_use_seven_day_sliding_retention_and_refresh_tokens():
    oauth = text('server/team-points/src/OAuthSession.php')
    assert 'SESSION_RETENTION_SECONDS = 604800' in oauth
    assert "session.gc_maxlifetime',(string)self::SESSION_RETENTION_SECONDS" in oauth
    assert "'lifetime'=>self::SESSION_RETENTION_SECONDS" in oauth
    assert "'httponly'=>true" in oauth and "'samesite'=>'Lax'" in oauth
    assert 'setcookie(self::SESSION_NAME,session_id()' in oauth
    assert "'expires'=>time()+self::SESSION_RETENTION_SECONDS" in oauth
    access = block(oauth, 'private static function accessToken()', 'private static function scope')
    assert "self::refreshAccessToken($a,$refreshToken)" in access
    assert "$exp<=time()+5" in access and "unset($_SESSION['oauth_access'],$_SESSION['oauth_user']" in access
    refresh = block(oauth, 'private static function refreshAccessToken', 'private static function scope')
    assert "'grant_type'=>'refresh_token'" in refresh
    assert "'refresh_token'=>$refreshToken" in refresh
    assert "session_regenerate_id(true)" in refresh
    assert "$_SESSION['oauth_user']['expires_at']=$expiresAt" in refresh
    # Browser-facing profile/session payload may expose expiry metadata, never the Bearer credential.
    info = block(oauth, 'public static function sessionInfo()', 'public static function login')
    assert "'expires_at'" in info
    assert "access_token" not in info


def test_universal_observation_transport_covers_relevant_p2k_endpoints_and_only_network_refreshes():
    api = text('assets/js/shared/api-client.js')
    useful = block(api, 'function usefulObservation', 'function compactObservationPayload')
    assert '/matches`' in useful and '/members`' in useful
    assert r'^\/pub\/match\/\d+' in useful
    assert '(matches|stats)' in useful
    assert '/games\\/20\\d{2}' in useful
    assert r'^\/pub\/player\/[^/]+\/?$' in useful
    assert '["HIT", "STALE", "STALE_IF_ERROR"].includes(cacheState)' in api
    assert 'completed background' in api and 'cacheState: "REFRESH"' in api
    assert 'batch.length < 48' in api
    observe = text('server/team-points/public/observe.php')
    assert 'count($observations)>48' in observe
    assert "consume($ip,300,60)" in observe


def test_browser_roster_and_club_index_observations_are_non_destructive_but_immediate():
    ingest = text('server/team-points/src/ObservationIngestor.php')
    repo = text('server/team-points/src/Repository.php')
    members = block(ingest, 'private function clubMembers', 'private function playerProfile')
    assert 'markMembersObserved($this->clubSlug,count($seen),false)' in members
    assert "'sync_roster'" in members
    assert "'canonical_membership_written'=>false" in members
    assert 'resetCurrentMembers' not in members
    matches = block(ingest, 'private function clubMatches', 'private function playerMatches')
    assert 'recordObservedClubMatchReference' in matches
    assert 'markClubIndexObserved($this->clubSlug,$counts,false)' in matches
    assert "'canonical_match_status_written'=>false" in matches
    passive = block(repo, 'public function recordObservedClubMatchReference', '/** Record a passive detail observation')
    assert "status,observed_status" in passive
    assert "'unknown',?" in passive
    assert 'first_discovered_at' in passive
    assert 'last_verified_at' in passive and 'NULL' in passive
    assert re.search(r'(?<!observed_)status=VALUES\(observed_status\)', passive) is None


def test_profile_stats_match_and_player_match_observations_preserve_provenance():
    ingest = text('server/team-points/src/ObservationIngestor.php')
    repo = text('server/team-points/src/Repository.php')
    profile = block(ingest, 'private function playerProfile', 'private function playerStats')
    assert 'storeObservedPlayerProfile' in profile and "'sync_player_profile'" in profile
    assert "'canonical_profile_written'=>false" in profile
    stats = block(ingest, 'private function playerStats', 'private function matchDetail')
    assert "'acamr_claim',true" in stats
    assert "'browser_passive',false" in stats
    assert "'sync_player_stats'" in stats
    detail = block(ingest, 'private function matchDetail', 'private function clubMatches')
    assert 'recordObservedClubMatchReference' in detail or 'markMatchPassiveObserved' in detail
    assert "'sync_match'" in detail
    player_matches = block(ingest, 'private function playerMatches', 'private function playerArchiveHint')
    assert 'markPlayerMatchesPassiveObserved' in player_matches
    assert 'recordObservedClubMatchReference' in player_matches
    ratings = block(repo, 'public function storeObservedMemberRatings', 'public function markPlayerMatches')
    assert 'stats_passive_observed_at' in ratings


def test_core12_adds_separate_passive_and_authoritative_freshness_fields():
    repo = text('server/team-points/src/Repository.php')
    schema = text('server/team-points/sql/core-schema.sql')
    migration = text('server/team-points/sql/core-migration-v2.9.10.sql')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [12,13,14,15])
    for needle in [
        'members_last_verified_at','club_index_last_verified_at','members_count_observed_at',
        'player_matches_passive_observed_at','stats_passive_observed_at','profile_observed_at',
        'observed_status','last_observed_at'
    ]:
        assert needle in schema and needle in migration
    assert 'VALUES(12)' in migration


def test_cron_has_hard_hourly_authoritative_freshness_deadlines_that_cannot_starve_or_permafail():
    repo = text('server/team-points/src/Repository.php')
    worker = text('server/team-points/src/Worker.php')
    cron = text('server/team-points/src/CronLoop.php')
    q = block(repo, 'public function queueCronFreshness', '/** Current-member recruitment pool')
    assert '$deadlineSeconds=3600' in q
    assert "$deadlineLeadSeconds=$lane==='club'?300:600" in q
    assert 'club_index_last_verified_at' in q and 'members_last_verified_at' in q
    assert "freshness-deadline:club-index:" in q and "freshness-deadline:roster:" in q
    claim = block(repo, 'public function claimNextItem', 'public function finishItem')
    assert "item_key LIKE 'freshness-deadline:%' THEN -1" in claim
    stale = block(repo, 'public function recoverStaleItems', 'public function claimNextItem')
    assert "item_key LIKE 'freshness-deadline:%' THEN 'retry'" in stale
    process = block(worker, 'private function processItem', 'private function syncClubMatches')
    assert "str_starts_with($key, 'freshness-deadline:')" in process
    assert 'if (!$freshnessDeadline && $attempts >= $retryLimit)' in process
    assert '$freshnessDeadline || $attempts < 3' in process
    assert 'refreshRosterOnly' in cron and 'refreshClubIndexOnly' in cron


def test_only_authoritative_worker_fetches_advance_verified_clocks():
    repo = text('server/team-points/src/Repository.php')
    worker = text('server/team-points/src/Worker.php')
    ingest = text('server/team-points/src/ObservationIngestor.php')
    assert 'markMembersObserved($clubSlug,count($members),true)' in repo
    assert 'markClubIndexObserved($this->clubSlug,$counts,true)' in worker
    assert 'markClubIndexObserved($this->clubSlug,[' in worker and '],true)' in worker
    assert 'markMembersObserved($this->clubSlug,count($seen),false)' in ingest
    assert 'markClubIndexObserved($this->clubSlug,$counts,false)' in ingest


def test_challenge_assistant_parallel_progress_names_the_teams_being_analyzed():
    challenge = text('assets/js/pages/challenge-list-assistant.js')
    assert 'const activeTeams = new Set();' in challenge
    assert 'Analyzing ${names.length} team' in challenge
    assert 'activeTeams.add(item.entry.slug)' in challenge
    assert 'activeTeams.delete(item.entry.slug)' in challenge
    assert 'P2K_API_CLIENT.processPriority' in challenge
    assert 'Evaluating candidates…' not in challenge
