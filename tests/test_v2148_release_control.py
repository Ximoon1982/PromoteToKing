from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = (ROOT / "ReleaseControl.php").read_text(encoding="utf-8")
BOOTSTRAP = (ROOT / "server/release-control/src/bootstrap.php").read_text(encoding="utf-8")
UPLOADER = (ROOT / "server/release-control/src/ReleasePackageUploadInstaller.php").read_text(encoding="utf-8")
CLEANUP = (ROOT / "server/release-control/src/FilesystemCleanupManager.php").read_text(encoding="utf-8")
BUILDER = (ROOT / "tools/release/v2148/build-universal-2.13x.sh").read_text(encoding="utf-8")
ADMIN = (ROOT / "assets/js/admin/admin-shell.js").read_text(encoding="utf-8")


def test_v2148_identity_and_release_control_ui():
    assert (ROOT / "VERSION").read_text().strip() in {"2.14.8", "2.14.9"}
    assert ("v2.14.8 · ZIP install + filesystem cleanup" in PAGE) or ("v2.14.9 · filesystem audit + CSV exports" in PAGE)
    assert 'id="package-upload"' in PAGE
    assert 'enctype="multipart/form-data"' in PAGE
    assert 'name="action" value="upload-release-package"' in PAGE
    assert "Public traffic is never promoted by this action." in PAGE
    assert 'id="filesystem-cleanup"' in PAGE
    assert "Filesystem deletion preview — nothing has been deleted yet." in PAGE
    assert 'name="action" value="delete-filesystem-artifact"' in PAGE
    assert "$filesystemPageSize = 10;" in PAGE


def test_browser_package_install_is_manifested_bounded_and_non_promoting():
    assert "ZipArchive" in UPLOADER
    assert "MAX_ZIP_BYTES = 134217728" in UPLOADER
    assert "MAX_EXTRACTED_BYTES = 536870912" in UPLOADER
    assert "MAX_ENTRIES = 20000" in UPLOADER
    assert "is_uploaded_file" in UPLOADER
    assert "zipEntryIsSymlink" in UPLOADER
    assert "normalizeRelativePath" in UPLOADER
    assert "RECOVERY_PLANE.sha256" in UPLOADER
    assert "ReleaseCandidatePackage" in UPLOADER
    assert "ReleaseCandidateInstaller" in UPLOADER
    assert "ReleasePreviewTree" in UPLOADER
    assert "'public_changed'=>false" in UPLOADER
    assert "promote" not in UPLOADER.lower()


def test_recovery_plane_activation_is_allowlisted_backed_up_and_htaccess_last():
    assert "isRecoveryPlanePath" in UPLOADER
    assert "storage/release-backups/web-upload-v" in UPLOADER
    assert "Recovery-plane activation failed and was rolled back" in UPLOADER
    assert "if ($lower === '.htaccess') return 100;" in UPLOADER
    assert "server/release-control/" in UPLOADER
    assert ".env" in UPLOADER and ".local." in UPLOADER
    assert "TOKEN_PARSE" in UPLOADER


def test_cleanup_crawler_is_conservative_and_revalidated():
    assert "conservative-p2k-maintenance-artifacts-only" in CLEANUP
    assert "Unknown top-level files and directories are ignored" in CLEANUP
    assert "BACKUP_MIN_AGE = 604800" in CLEANUP
    assert "STAGING_MIN_AGE = 86400" in CLEANUP
    assert "KEEP_NEWEST_BACKUPS = 3" in CLEANUP
    assert "physicalBaselineTopLevelPaths" in CLEANUP
    assert "isInstallerArchiveName" in CLEANUP
    assert "isInstallerExtractionName" in CLEANUP
    assert "isReleaseBackupName" in CLEANUP
    assert "Reclassify immediately before deletion" in CLEANUP
    assert "scan_errors" in CLEANUP
    assert "cleanup_ready'=>$ready && !is_link($path) && empty($stats['scan_errors'])" in CLEANUP
    assert "estimated_reclaimable_bytes" in CLEANUP
    assert "filesystem-cleanup.log" in CLEANUP


def test_v2148_bootstrap_package_contract_and_admin_card():
    assert "ReleasePackageUploadInstaller.php" in BOOTSTRAP
    assert "FilesystemCleanupManager.php" in BOOTSTRAP
    assert "RECOVERY_PLANE.sha256" in BUILDER
    assert "v2148-release-zip-filesystem-cleanup" in BUILDER
    assert '"version":"2.14.8"' in BUILDER
    assert "4b76f2763d3df0a4bb5b256cab48b0bc44e6efaa" in BUILDER
    assert "Upload qualified release ZIPs" in ADMIN
