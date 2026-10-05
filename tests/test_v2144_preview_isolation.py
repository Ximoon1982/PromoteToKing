from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

ROUTER = read("PreviewRouter.php")
HTACCESS = read(".htaccess")
ISOLATION = read("server/team-points/src/PreviewIsolation.php")
BOOTSTRAP = read("server/team-points/src/bootstrap.php")
DATABASE = read("server/team-points/src/Database.php")
GREEN = read("server/team-points-green/src/GreenConfig.php")
AUTH = read("server/team-points/src/Auth.php")
SESSION = read("server/team-points/public/session.php")
RC_AUTH = read("server/release-control/src/ReleaseControlAuth.php")
RC_OAUTH = read("server/release-control/public/preview-oauth-session.php")
TASKS = read("server/shared/TaskRegistry.php")
COMMON = read("api/_common.php")
API_ROUTER = read("api/router.php")
STATE = read("server/release-control/src/ReleaseControlState.php")
PAGE = read("ReleaseControl.php")
README = read("server/release-control/README.md")


def test_v2144_identity_and_capabilities():
    assert read("VERSION").strip() == "2.14.4"
    assert "v2.14.4 · Side-effect-isolated candidate preview" in PAGE
    assert "'candidate_side_effect_isolation' => true" in STATE
    assert "'candidate_database_mode' => 'read-only'" in STATE
    assert "'candidate_runtime_sandbox' => true" in STATE
    assert "'candidate_session_sandbox' => true" in STATE
    assert "'promotion' => false" in STATE
    assert "'rollback' => false" in STATE
    assert "'public_slot_routing' => false" in STATE
    assert "'candidate_cron' => false" in STATE


def test_preview_context_redirects_runtime_cache_logs_and_uploads():
    assert "P2K_PREVIEW_SANDBOX" in ISOLATION
    assert "release-control/preview-sandboxes/" in ISOLATION
    for fragment in [
        "$storage['runtime_dir']=$runtime",
        "$storage['cache_dir']=$runtime.'/cache/chesscom'",
        "$storage['logs_dir']=$runtime.'/logs'",
        "$storage['archive_dir']=$sandbox.'/archive'",
        "$app['live_ranks_upload_dir']=$sandbox.'/uploads/live-ranks'",
        "$app['cron_continuous_enabled']=false",
        "$app['cron_self_url']=''",
    ]:
        assert fragment in ISOLATION
    assert "PreviewIsolation::applyConfig($loaded)" in BOOTSTRAP


def test_preview_database_sessions_are_fail_closed_read_only():
    assert "SET SESSION TRANSACTION READ ONLY" in ISOLATION
    assert "SELECT @@tx_read_only" in ISOLATION
    assert "SELECT @@transaction_read_only" in ISOLATION
    assert "did not verify as read-only" in ISOLATION
    assert "PreviewIsolation::enforceReadOnlyPdo($pdo)" in DATABASE
    assert "\\P2K\\TeamPoints\\PreviewIsolation::enforceReadOnlyPdo($pdo)" in GREEN


def test_preview_task_registry_does_not_initialize_or_upsert_schema():
    constructor = TASKS.split("public function __construct", 1)[1].split("public static function ensureSchema", 1)[0]
    assert "PreviewIsolation::active()" in constructor
    assert "return;" in constructor
    assert constructor.index("return;") < constructor.index("self::ensureSchema($pdo)")


def test_preview_admin_session_is_separate_from_public_admin_session():
    assert "P2KTPPREVIEWSESSID" in AUTH
    assert "PreviewIsolation::sessionDirectory('admin')" in AUTH
    assert "self::startSession(true)" in AUTH
    assert "if (PreviewIsolation::active()) return;" in AUTH
    assert "P2KTPSESSID" in AUTH
    assert "PREVIEW_SESSION_UNAVAILABLE" in AUTH


def test_preview_admin_bootstrap_never_opens_production_oauth_for_mutation():
    assert "PreviewIsolation::active()" in SESSION
    assert "PREVIEW_BOOTSTRAP_REQUIRED" in SESSION
    preview_guard = SESSION.split("if ($username === '' && PreviewIsolation::active())", 1)[1].split("if ($username === '') $username = OAuthSession::authenticatedUsername(true);", 1)[0]
    assert "throw new ApiException" in preview_guard
    assert "recovery-plane bootstrap assertion" in preview_guard


def test_recovery_plane_identity_checks_are_non_mutating_for_preview():
    assert "currentUsername(bool $allowMutation = true)" in RC_AUTH
    assert "oauthSessionStatus(bool $allowMutation = true)" in RC_AUTH
    assert "oauthUsername(bool $allowMutation)" in RC_AUTH
    assert RC_AUTH.count("if ($allowMutation && $refreshToken !== ''") == 2
    assert "if ($allowMutation) $this->touchOAuthCookie($cookieId);" in RC_AUTH
    assert "oauthSessionStatus(false)" in RC_OAUTH
    assert "$auth->currentUsername(false)" in ROUTER


def test_preview_auth_requests_are_routed_through_isolation_except_session_status():
    assert "%{QUERY_STRING} (^|&)action=session(&|$)" in HTACCESS
    assert "RewriteRule ^server/team-points/public/oauth\\.php$ /server/release-control/public/preview-oauth-session.php [L]" in HTACCESS
    general = next(line for line in HTACCESS.splitlines() if "auth/callback" in line and "RewriteCond %{REQUEST_URI}" in line)
    assert "server/team-points/public/(?:oauth|session)" not in general
    assert "$relative === 'server/team-points/public/oauth.php'" in ROUTER
    assert "CANDIDATE_PREVIEW_SIDE_EFFECT_BLOCKED" in ROUTER
    assert "$previewSessionBootstrap = $relative === 'server/team-points/public/session.php' && $method === 'POST';" in ROUTER


def test_known_background_repair_and_ingestion_endpoints_fail_closed():
    for path in [
        "api/track-upcoming-league-matches/index.php",
        "server/team-points/public/cron.php",
        "server/team-points/public/cron-club.php",
        "server/team-points/public/cron-player.php",
        "server/team-points/public/consistency-repair.php",
        "server/team-points/public/data-reconciliation.php",
        "server/team-points/public/database-repair.php",
        "server/team-points/public/fair-play-maintenance.php",
        "server/team-points/public/fresh-init.php",
        "server/team-points/public/install.php",
        "server/team-points/public/match-detail-refresh.php",
        "server/team-points/public/observe.php",
        "server/team-points/public/seed-import.php",
    ]:
        assert f"'{path}'" in ROUTER
    assert "maintenance, background work, ingestion, repair or schema/state mutation" in ROUTER


def test_legacy_get_side_effects_are_suppressed_or_sandboxed():
    assert "PreviewIsolation::mapWritablePath($path, root_dir())" in COMMON
    assert "PreviewIsolation::mapWritablePath($dir, root_dir())" in COMMON
    migration = COMMON.split("function migrate_legacy_tracking(): array", 1)[1].split("function read_follow_registry", 1)[0]
    assert "PreviewIsolation::active()" in migration
    assert "$result['previewReadOnly'] = true;" in migration
    assert "PreviewIsolation::active() ? 0 : expire_started_tracking($registry)" in COMMON
    diagnostics = API_ROUTER.split("case 'diagnostics':", 1)[1]
    assert "if (!\\P2K\\TeamPoints\\PreviewIsolation::active())" in diagnostics


def test_release_control_describes_isolation_without_claiming_promotion():
    assert "Candidate database sessions are forced read-only" in PAGE
    assert "protected preview sandbox" in PAGE
    assert "Promotion / rollback" in PAGE and "Disabled in v2.14.4" in PAGE
    assert "v2.14.4 scope: candidate side-effect isolation" in README
    assert "public application on the qualified v2.14.2 direct-root release" in README
