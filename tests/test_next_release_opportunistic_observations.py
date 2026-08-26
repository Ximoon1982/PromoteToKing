from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(path): return (ROOT / path).read_text(encoding='utf-8')

def test_shared_client_posts_useful_chess_payloads_non_blocking():
    s=text('assets/js/shared/api-client.js')
    assert 'opportunisticObservationEndpoint' in s
    assert 'function usefulObservation' in s
    assert '/pub/club/${club}/matches' in s
    assert 'queueObservation(normalizedUrl, result.data, result);' in s
    assert 'keepalive: body.length < 60000' in s
    assert 'const observedUrls = new Map()' in s
    assert 'OBSERVATION_REUSE_MS = 10 * 60 * 1000' in s
    assert 'previous.signature === signature' in s

def test_observation_endpoint_is_same_origin_bounded_and_rate_limited():
    s=text('server/team-points/public/observe.php')
    assert 'Auth::enforceSameOrigin()' in s
    assert '2097152' in s
    assert 'count($observations)>48' in s
    assert "browser-observations" in s
    assert "rate_limited" in s

def test_browser_payloads_only_trigger_server_verified_canonical_work():
    s=text('server/team-points/src/ObservationIngestor.php')
    assert "'sync_match'" in s and "'sync_board'" in s
    assert "'sync_roster'" in s and "'sync_player_stats'" in s and "'sync_player_profile'" in s
    assert "'verification'=>'server_required'" in s
    # A browser user can fabricate POSTed JSON, so canonical facts are never
    # written directly from observation payloads.
    assert 'applyMembersObservation' not in s
    assert 'storePlayerProfileSnapshot' not in s
    assert 'storeMemberRatings' not in s
    assert 'upsertMatchMetadata' not in s
    assert 'upsertPointEvent' not in s
    assert 'competition_points' not in s.lower()
    assert 'win_probability' not in s.lower()
    assert 'forecast' not in s.lower()

def test_player_matches_only_accept_configured_club_entries():
    s=text('server/team-points/src/ObservationIngestor.php')
    assert "$target='https://api.chess.com/pub/club/'.$this->clubSlug" in s
    assert "rtrim(strtolower((string)($entry['club']??'')),'/')!==$target" in s

def test_repository_checks_refresh_due_without_writing_hint_status():
    s=text('server/team-points/src/Repository.php')
    start=s.index('public function matchDetailDueFromObservation')
    end=s.index('/** Record a passive club-index reference', start)
    block=s[start:end]
    assert 'SELECT status,last_verified_at' in block
    assert 'INSERT' not in block
    assert 'UPDATE' not in block

def test_worker_verifies_rating_snapshot_server_side():
    s=text('server/team-points/src/Worker.php')
    assert "'sync_player_stats' => $this->syncPlayerStats($payload)" in s
    assert "'/stats'" in s
    assert 'Repository::ratingsFromStats($stats)' in s
    assert 'storeMemberRatings' in s

def test_site_config_exposes_observation_endpoint():
    s=text('assets/js/site-config.js')
    assert 'opportunisticObservationEndpoint' in s
    assert 'server/team-points/public/observe.php' in s

def test_integration_documented_as_no_schema_change():
    s=text('NEXT_RELEASE_INTEGRATION_OPPORTUNISTIC_CHESS_OBSERVATIONS.md')
    assert 'No schema change' in s
    assert 'authoritative server worker' in s
