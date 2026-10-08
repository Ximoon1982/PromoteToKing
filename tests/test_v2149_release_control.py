from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "ReleaseControl.php").read_text(encoding="utf-8")
BOOTSTRAP = (ROOT / "server/release-control/src/bootstrap.php").read_text(encoding="utf-8")
AUDIT = (ROOT / "server/release-control/src/FilesystemAuditManager.php").read_text(encoding="utf-8")
CLEANUP = (ROOT / "server/release-control/src/FilesystemCleanupManager.php").read_text(encoding="utf-8")
MEMBERS = (ROOT / "server/team-points/public/members-insights-export.php").read_text(encoding="utf-8")
OPPONENTS = (ROOT / "server/team-points/public/opponents-export.php").read_text(encoding="utf-8")
TP_ADMIN = (ROOT / "TeamPointsAdmin.html").read_text(encoding="utf-8")
TP_JS = (ROOT / "assets/js/pages/team-points-admin.js").read_text(encoding="utf-8")
CI_JS = (ROOT / "assets/js/pages/club-intelligence.js").read_text(encoding="utf-8")
BUILDER = (ROOT / "tools/release/v2149/build-universal-2.13x.sh").read_text(encoding="utf-8")


def test_v2149_identity_and_audit_ui():
    assert (ROOT / "VERSION").read_text().strip() == "2.14.9"
    assert "v2.14.9 · filesystem audit + CSV exports" in PAGE
    assert 'id="filesystem-audit"' in PAGE
    assert "Start full filesystem audit" in PAGE
    assert "Pause audit" in PAGE
    assert "Resume audit" in PAGE
    assert "Restart from zero" in PAGE
    assert 'role="progressbar"' in PAGE
    assert 'http-equiv="refresh"' in PAGE
    assert "Audit findings never grant deletion rights." in PAGE
    assert "FilesystemAuditManager.php" in BOOTSTRAP


def test_filesystem_audit_is_recursive_hardlink_aware_and_protective():
    assert "DEFAULT_BATCH_ENTRIES" in AUDIT
    assert "MAX_BATCH_SECONDS" in AUDIT
    assert "filesystem-audit-v2.json" in AUDIT
    assert "public function pause()" in AUDIT
    assert "public function resume()" in AUDIT
    assert "public function step(" in AUDIT
    assert "lstat" in AUDIT
    assert "unique_file_inodes" in AUDIT
    assert "unique_allocated_bytes" in AUDIT
    assert "estimated_reclaimable_bytes" in AUDIT
    assert "hardlink_reference_entries" in AUDIT
    assert ".p2k-preserve" in AUDIT
    assert "Unknown top-level content is protected by default." in AUDIT
    assert "deletion_authorized'=>false" in AUDIT
    assert "FilesystemCleanupManager" in AUDIT
    assert "conservative-p2k-maintenance-artifacts-only" in CLEANUP


def test_members_admin_export_contains_joined_utc_and_epoch():
    assert "Auth::requireAdmin()" in MEMBERS
    assert "'filter'=>'all'" in MEMBERS
    assert "'joined_utc','joined_epoch'" in MEMBERS
    assert "p2k_members_csv_utc" in MEMBERS
    assert "p2k_members_csv_epoch" in MEMBERS
    assert 'id="membersFullCsvExport"' in TP_ADMIN
    assert "members-insights-export.php" in TP_JS


def test_opponent_intelligence_export_is_admin_only_and_rich():
    assert "Auth::requireAdmin()" in OPPONENTS
    for field in [
        "result_coverage_percent", "avg_boards", "avg_opponent_rating",
        "league_matches", "friendly_matches", "matches_last_90d", "avg_score_margin",
    ]:
        assert field in OPPONENTS
    assert 'id="ciOpponentCsvExport"' in CI_JS
    assert "opponents-export.php" in CI_JS


def test_v2149_builder_pins_v2148_and_packages_new_surface():
    assert "v2149-filesystem-audit-admin-csv" in BUILDER
    assert "ab3af15ed65325c74558ac1f50d6e18d3935e7c5" in BUILDER
    assert 'emit_baselines "2.14.8" REFS_2148' in BUILDER
    assert 'emit_baselines "2.14.9" REFS_2149' in BUILDER
    for path in [
        "TeamPointsAdmin.html",
        "ClubIntelligence.html",
        "assets/js/pages/team-points-admin.js",
        "assets/js/pages/club-intelligence.js",
        "server/team-points/public/members-insights-export.php",
        "server/team-points/public/opponents-export.php",
        "server/release-control/src/FilesystemAuditManager.php",
    ]:
        assert path in BUILDER


def test_trophy_gallery_uses_physical_shared_data_root_under_release_routing():
    store = (ROOT / "server/trophy-gallery/src/TrophyGalleryStore.php").read_text(encoding="utf-8")
    editor = (ROOT / "server/trophy-gallery/public/editor-meta.php").read_text(encoding="utf-8")
    assert "P2K_PUBLIC_ROOT" in store
    assert "P2K_PREVIEW_PUBLIC_ROOT" in store
    assert "P2K_PUBLIC_ROOT" in editor
    assert "P2K_PREVIEW_PUBLIC_ROOT" in editor
    assert "server/trophy-gallery/public/editor-meta.php" in BUILDER
