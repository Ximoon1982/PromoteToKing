from pathlib import Path
import importlib.util
import re

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "ReleaseControl.php").read_text(encoding="utf-8")
ROUTER = (ROOT / "PreviewRouter.php").read_text(encoding="utf-8")
SESSION = (ROOT / "server/release-control/src/ReleasePreviewSession.php").read_text(encoding="utf-8")
TREE = (ROOT / "server/release-control/src/ReleasePreviewTree.php").read_text(encoding="utf-8")
STATE = (ROOT / "server/release-control/src/ReleaseControlState.php").read_text(encoding="utf-8")
AUTH = (ROOT / "server/release-control/src/ReleaseControlAuth.php").read_text(encoding="utf-8")
HTACCESS = (ROOT / ".htaccess").read_text(encoding="utf-8")
POLICY = (ROOT / "server/release-control/src/ReleaseSlotPolicy.php").read_text(encoding="utf-8")
REAL_OAUTH = (ROOT / "assets/js/shared/real-oauth.js").read_text(encoding="utf-8")
SITE_CONFIG = (ROOT / "assets/js/site-config.js").read_text(encoding="utf-8")
TP_CLIENT = (ROOT / "assets/js/shared/team-points-client.js").read_text(encoding="utf-8")
SELECTOR_PATH = ROOT / "server/release-control/tools/release-slot-paths.py"


def selector_module():
    spec = importlib.util.spec_from_file_location("p2k_release_slot_paths_v2143", SELECTOR_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


def test_v2143_identity_and_control_plane_contract():
    assert (ROOT / "VERSION").read_text().strip() in {"2.14.3", "2.14.4", "2.14.5", "2.14.6"}
    assert "candidate preview" in PAGE.lower() or "promotion and rollback" in PAGE.lower()
    assert "direct-root" in PAGE
    assert "Preview candidate for me" in PAGE
    assert "Stop preview" in PAGE
    assert 'href="/index.html">Open candidate site</a>' in PAGE
    assert "Promote candidate" in PAGE
    assert "Rollback" in PAGE
    assert "'personal_preview' => true" in STATE
    version = tuple(int(value) for value in (ROOT / "VERSION").read_text().strip().split("."))
    if version >= (2, 14, 5):
        assert "'promotion'=>$candidateValid && $candidatePreviewValid && $publicValid" in STATE
        assert "'rollback'=>$mode === 'slots'" in STATE
        assert "'public_slot_routing'=>true" in STATE
    else:
        assert "'promotion' => false" in STATE
        assert "'rollback' => false" in STATE
        assert "'public_slot_routing' => false" in STATE
    assert "'candidate_cron' => false" in STATE


def test_preview_enable_uses_prebuilt_tree_and_is_not_a_heavy_web_operation():
    assert "describeExisting($releaseId)" in SESSION
    assert "Candidate preview tree is not prepared" in SESSION
    assert "->prepare($releaseId)" not in SESSION
    assert "Preview tree" in PAGE
    assert "previewTreeReady" in PAGE


def test_preview_cookie_is_signed_identity_bound_and_http_only():
    assert "P2KRC_PREVIEW" in SESSION
    assert "hash_hmac('sha256'" in SESSION
    assert "'secure'=>true" in SESSION
    assert "'httponly'=>true" in SESSION
    assert "'samesite'=>'Lax'" in SESSION
    assert "identity_mismatch" in SESSION
    assert "candidate_changed" in SESSION
    assert "preview-secret.key" in SESSION
    assert "random_bytes(32)" in SESSION


def test_preview_control_uses_authenticated_csrf():
    version = tuple(int(value) for value in (ROOT / "VERSION").read_text().strip().split("."))
    if version >= (2, 14, 6):
        assert "controlCsrfToken" in AUTH
        assert "validateControlCsrfToken" in AUTH
        assert "$auth->validateControlCsrfToken" in PAGE
        assert "$auth->currentCsrfToken()" not in PAGE
    else:
        assert "currentCsrfToken" in AUTH
        assert "hash_equals($expectedCsrf, $providedCsrf)" in PAGE
    assert 'name="csrf"' in PAGE
    assert 'name="preview_user"' in PAGE
    assert 'name="action" value="enable-preview"' in PAGE
    assert 'name="action" value="disable-preview"' in PAGE


def test_release_control_reuses_refreshable_oauth_identity_without_full_login():
    assert "refreshOAuthSession" in AUTH
    assert "'grant_type'=>'refresh_token'" in AUTH
    assert "oauth_refresh_retry_at" in AUTH
    assert "server/team-points/config/oauth.local.php" in AUTH
    assert "PromoteToKing-ReleaseControl/2.14." in AUTH
    assert "server/team-points/src/bootstrap.php" not in AUTH


def test_explicit_enable_intent_survives_real_reauthentication():
    assert "P2KRC_PREVIEW_PENDING" in SESSION
    assert "beginPendingEnable" in SESSION
    assert "consumePendingEnable" in SESSION
    assert "resume_preview=1" in PAGE
    assert "$previewSession->enable($username)" in PAGE
    assert "$auth->loginUrl('/ReleaseControl.php?resume_preview=1')" in PAGE


def test_preview_oauth_session_status_uses_recovery_plane_not_candidate_php():
    assert (ROOT / "server/release-control/public/preview-oauth-session.php").is_file()
    assert "oauthSessionStatus" in AUTH
    assert "adminBootstrapAssertion" in AUTH
    assert "SECRET_ACCESS_TOKEN" not in AUTH
    assert "/server/release-control/public/preview-oauth-session.php" in HTACCESS
    special = HTACCESS.index("%{QUERY_STRING} (^|&)action=session(&|$)")
    special_rule = HTACCESS.index("RewriteRule ^server/team-points/public/oauth\\.php$ /server/release-control/public/preview-oauth-session.php [L]")
    general_preview = HTACCESS.index("RewriteRule ^(?!PreviewRouter\\.php$)(.*)$ /PreviewRouter.php [L]")
    assert special < special_rule < general_preview


def test_preview_reauthentication_preserves_signed_intent_until_identity_returns():
    assert "public function loginUrl(string $returnTo = '/ReleaseControl.php')" in AUTH
    assert "!empty($_COOKIE[ReleasePreviewSession::COOKIE])" in ROUTER
    assert "$auth->loginUrl($originalUri" in ROUTER
    assert "CANDIDATE_PREVIEW_REAUTH_REQUIRED" in ROUTER
    reauth_block = ROUTER.split("if ($username === '' && !empty($_COOKIE[ReleasePreviewSession::COOKIE]))", 1)[1].split("$status =", 1)[0]
    assert "clearInvalidCookie" not in reauth_block
    assert "identity_mismatch" in SESSION
    assert "candidate_changed" in SESSION


def test_preview_auth_endpoints_are_site_root_absolute():
    assert 'new URL("/server/team-points/public/oauth.php", window.location.href)' in REAL_OAUTH
    assert '"/server/team-points/public/session.php"' in SITE_CONFIG
    assert '"/server/team-points/public/session.php"' in TP_CLIENT


def test_preview_router_recovers_original_uri_after_apache_internal_rewrite():
    assert "p2k_preview_original_uri($_SERVER)" in ROUTER
    assert "THE_REQUEST" in ROUTER
    assert "P2K_PREVIEW_ORIGINAL_PATH" in ROUTER
    assert "RewriteBase /" in HTACCESS
    assert "RewriteRule ^(?!PreviewRouter\\.php$)(.*)$ /PreviewRouter.php [L]" in HTACCESS


def test_preview_router_is_cookie_gated_and_keeps_recovery_shared_paths_out():
    assert "P2KRC_PREVIEW" in HTACCESS
    assert "PreviewRouter.php" in HTACCESS
    assert "server/release-control" in HTACCESS
    assert "auth/callback" in HTACCESS
    assert "%{QUERY_STRING} (^|&)action=session(&|$)" in HTACCESS
    assert "RewriteRule ^server/team-points/public/oauth\\.php$ /server/release-control/public/preview-oauth-session.php [L]" in HTACCESS
    assert "data" in HTACCESS and "logs" in HTACCESS and "storage" in HTACCESS
    assert "ReleaseSlotPolicy::isReleaseOwnedPath" in ROUTER
    assert "X-P2K-Candidate-Preview" in ROUTER
    assert "['GET','HEAD']" in ROUTER
    assert "P2K_TP_CONFIG" in ROUTER
    assert "p2k-candidate-preview-banner" in ROUTER
    assert "X-P2K-Preview-Error" in ROUTER
    assert "candidate-file-missing" in ROUTER


def test_preview_scope_does_not_capture_sibling_projects():
    scope_line = next(
        line.strip() for line in HTACCESS.splitlines()
        if "artwork-masters" in line and "RewriteCond %{REQUEST_URI}" in line
    )
    prefix = "RewriteCond %{REQUEST_URI} "
    assert scope_line.startswith(prefix) and scope_line.endswith(" [NC]")
    pattern = scope_line[len(prefix):-len(" [NC]")]
    compiled = re.compile(pattern, re.IGNORECASE)

    for uri in [
        "/", "/ui-v2.html", "/assets/js/site-config.js", "/api/foo/index.php",
        "/server/team-points/public/api.php", "/trophies/example.png",
    ]:
        assert compiled.search(uri), uri

    for uri in [
        "/ClubWarsLive/", "/ClubWarsLive/index.php", "/TGP/Widget/",
        "/PlayerDiscovery/", "/GalacticConflict/", "/DailyMatchAtlas/",
        "/ReleaseControl.php", "/data/runtime-v280/state.json",
    ]:
        assert not compiled.search(uri), uri


def test_preview_tree_uses_candidate_files_and_only_explicit_shared_links():
    assert "inspectSlot($releaseId, true)" in TREE
    assert "symlink_supported" in TREE
    assert "atomic_rename_supported" in TREE
    assert "@link($source, $dest)" in TREE
    assert "@copy($source, $dest)" in TREE
    assert "['data', 'logs', 'storage']" in TREE
    assert "str_contains($name, '.local.')" in TREE
    assert "server/release-control/" in TREE
    assert "@rename($tmp, $target)" in TREE


def test_recovery_files_are_outside_policy_v2_but_legacy_policy_still_supported():
    version = tuple(int(value) for value in (ROOT / "VERSION").read_text().strip().split("."))
    assert ("POLICY_VERSION = 3" in POLICY) if version >= (2, 14, 5) else ("POLICY_VERSION = 2" in POLICY)
    assert "isReleaseOwnedPathForVersion" in POLICY
    mod = selector_module()
    assert not mod.included(".htaccess")
    assert not mod.included("ReleaseControl.php")
    assert not mod.included("PreviewRouter.php")
    if version >= (2, 14, 5):
        assert not mod.included("PublicRouter.php")
    assert not mod.included("server/release-control/src/ReleasePreviewSession.php")
    assert mod.included("VERSION")
    assert mod.included("ui-v2.html")
