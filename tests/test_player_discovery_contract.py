from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HTML = (ROOT / "player-discovery/index.html").read_text()
JS = (ROOT / "player-discovery/app.js").read_text()
PHP = (ROOT / "player-discovery/lib.php").read_text()
API = (ROOT / "player-discovery/api.php").read_text()


def test_default_is_two_rolling_months():
    assert 'id="monthsInput" type="number" min="1" max="24" value="2"' in HTML
    assert "(int)($body['months']??2)" in API


def test_chess_reads_are_direct_browser_pubapi():
    assert 'const CHESS = "https://api.chess.com/pub"' in JS
    assert 'state.scheduler.request(`${CHESS}/player/' in JS
    assert 'P2K_API_CLIENT' not in JS


def test_discovery_is_one_hop_seed_only():
    assert "WHERE job_id=? AND is_seed=1 AND duplicate_of IS NULL" in PHP
    assert "VALUES(?,?,?,0,1,1,?,'not_applicable','pending'" in PHP


def test_union_is_deduplicated_by_job_and_normalized_username():
    assert 'PRIMARY KEY(job_id,username_key)' in PHP
    assert 'ON DUPLICATE KEY UPDATE is_discovered=1' in PHP
    assert "strtolower(trim($value))" in PHP


def test_jobs_are_resumable_and_leased():
    assert 'lease_token VARCHAR(64) NULL' in PHP
    assert 'lease_owner VARCHAR(64) NULL' in PHP
    assert "discovery_state='in_progress'" in PHP
    assert 'client_id' in API
    assert 'p2k-player-discovery-client' in JS


def test_profile_stats_and_clubs_are_enriched():
    assert '/stats`' in JS
    assert '/clubs`' in JS
    for field in ['daily_rating', 'last_online_epoch', 'club_count', 'daily_timeout_percent', 'followers', 'fide']:
        assert field in PHP


def test_write_api_requires_oauth_csrf():
    assert 'HTTP_X_P2K_OAUTH_CSRF' in API
    assert 'CSRF_VALIDATION_FAILED' in API


def test_csv_is_streamed_server_side():
    assert "Content-Type: text/csv" in API
    assert 'foreach ($rows as $row)' in API
    assert 'fputcsv' in API


def test_adaptive_scheduler_reacts_to_429():
    assert 'response.status===429' in JS
    assert 'Math.floor(this.current/2)' in JS
    assert 'this.current++' in JS
    assert 'retry-after' in JS
