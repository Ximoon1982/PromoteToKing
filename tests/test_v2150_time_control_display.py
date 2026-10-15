from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DASH = (ROOT / "assets/js/pages/dashboard-v2.js").read_text(encoding="utf-8")
REPO = (ROOT / "server/team-points/src/Repository.php").read_text(encoding="utf-8")
BUILDER = (ROOT / "tools/release/v2150/build-incremental.sh").read_text(encoding="utf-8")

def test_dashboard_humanizes_moves_per_seconds():
    assert "const ratio = raw.match(" in DASH
    assert "seconds / moves / 86400" in DASH
    assert "maximumFractionDigits: 3" in DASH

def test_insights_distribution_humanizes_moves_per_seconds():
    assert "(int)$matches[2] / (int)$matches[1] / 86400" in REPO
    assert "number_format($days,3" in REPO

def test_bare_seconds_still_supported():
    assert "else if (/^\\d+$/.test(raw))" in DASH
    assert "(int)$raw / 86400" in REPO

def test_release_notes_cover_time_control_display():
    assert "days per move" in BUILDER
