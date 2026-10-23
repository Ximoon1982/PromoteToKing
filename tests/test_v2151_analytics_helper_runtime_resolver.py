from pathlib import Path

INSTALLER = Path("server/team-points/bin/install-analytics-convergence-cron.sh")

def test_active_helper_fallback_uses_install_root_not_config_path_arithmetic():
    text = INSTALLER.read_text(encoding="utf-8")
    assert 'rtrim((string)$argv[2]' in text
    assert '"$CONFIG" "$ROOT"' in text
    assert 'dirname(dirname(dirname($argv[1])))' not in text
