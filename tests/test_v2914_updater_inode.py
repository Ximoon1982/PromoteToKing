from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')

def test_v2914_updater_uses_compact_archives_not_recursive_site_backup():
    u=text('update-v2.9.13-to-v2.9.14.sh')
    assert 'p2k-state-backup.sh' in u
    assert 'release-files-v2.9.13-to-v2.9.14.tar.gz' in u
    assert 'cp -a "$SITE_ROOT/data"' not in u
    assert 'cp -a "$SITE_ROOT/config"' not in u
    assert 'files-before' not in u
    assert 'P2K_BACKUP_RETENTION' in u
    assert 'p2k-consolidate-legacy-backups.sh' in u

def test_compact_state_backup_excludes_reconstructible_high_inode_runtime_families():
    s=text('tools/release/p2k-state-backup.sh')
    for path in ('data/runtime-v280/cache','data/runtime-v280/public-response-cache','data/runtime-v280/browser-observations','data/runtime-v280/acamr','data/runtime-v280/telemetry','data/runtime-v280/fresh-init','data/runtime-v280/cron-shell'):
        assert path in s
    assert "-name '*_state-*.tar.gz'" in s

def test_v2914_updater_preserves_protected_config_and_four_crons():
    u=text('update-v2.9.13-to-v2.9.14.sh')
    for rel in ('server/team-points/config/config.local.php','server/team-points/config/oauth.local.php','data/server-config.json'):
        assert rel in u
    assert "grep -c 'cron-dispatch-v2.9.14.sh'" in u
    assert '[[ "$COUNT" -eq 4 ]]' in u

def test_v2914_updater_supports_verified_removals_with_rollback():
    u=text('update-v2.9.13-to-v2.9.14.sh')
    assert 'REMOVED_LIST=' in u
    assert '$m["removed"]' in u
    assert 'removed-files.txt' in u
    assert 'Files intentionally removed by this release are also captured' in u
    assert 'rm -f -- "$SITE_ROOT/$rel"' in u
    assert 'Removed file still present:' in u
    # Historical Python test caches are explicitly disposable if present on production.
    assert "-name '__pycache__'" in u
    assert "-name '.pytest_cache'" in u
