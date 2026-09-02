import json
import re
import subprocess
from pathlib import Path
import pytest

ROOT = Path(__file__).resolve().parents[1]
BASELINE = "b8bf26c7c41ca1914323717766bca995139291aa"
FACADES = ["ClubIntelligenceService.php", "AnalyticsBuilder.php", "AchievementCatalog.php"]


def public_signatures(source: str) -> list[str]:
    return re.findall(r"public\s+(?:static\s+)?function\s+\w+\s*\([^)]*\)\s*(?::\s*[^\s{]+)?", source)


def test_public_php_facade_signatures_match_v2111_exactly():
    if not (ROOT / ".git").exists():
        pytest.skip("Git baseline comparison is repository-scoped; artifact parity is hash-scoped.")
    for name in FACADES:
        relative = f"server/team-points/src/{name}"
        baseline = subprocess.run(["git", "show", f"{BASELINE}:{relative}"], cwd=ROOT, check=True, text=True, capture_output=True).stdout
        current = (ROOT / relative).read_text(encoding="utf-8")
        assert public_signatures(current) == public_signatures(baseline), relative


def test_frontend_split_changes_no_visual_asset_or_stylesheet():
    if not (ROOT / ".git").exists():
        pytest.skip("Git baseline comparison is repository-scoped.")
    changed = subprocess.run(["git", "diff", "--name-only", f"{BASELINE}..HEAD"], cwd=ROOT, check=True, text=True, capture_output=True).stdout.splitlines()
    visual = [path for path in changed if path.endswith((".css", ".png", ".jpg", ".jpeg", ".gif", ".svg", ".webp", ".ico"))]
    assert visual == []
    graph = json.loads((ROOT / "tests/v2.11.2-frontend-boundaries.json").read_text(encoding="utf-8"))
    assert graph["entrypoints"]["ui-v2.html"][-1] == "dashboard/facade"
    assert graph["entrypoints"]["index.html"][-1] == "admin/facade"


def test_compatibility_facades_delegate_only_bounded_responsibilities():
    architecture = json.loads((ROOT / "tests/v2.11.2-architecture.json").read_text(encoding="utf-8"))
    for facade, components in architecture["compatibility_facades"].items():
        source = (ROOT / f"server/team-points/src/{facade}.php").read_text(encoding="utf-8")
        for component in components:
            assert component in source
            assert (ROOT / f"server/team-points/src/{component}.php").is_file()
    assert "oauth-session" in architecture["frozen_contracts"]


def test_no_api_schema_scheduler_or_authentication_contract_files_changed():
    if not (ROOT / ".git").exists():
        pytest.skip("Git changed-path comparison is repository-scoped.")
    changed = subprocess.run(["git", "diff", "--name-only", f"{BASELINE}..HEAD"], cwd=ROOT, check=True, text=True, capture_output=True).stdout.splitlines()
    forbidden = ("api/", "server/team-points/public/", "server/team-points/sql/", "ClubIntelligence.html", "site-manifest.json", "reset-install-", "install-oauth-")
    assert not [path for path in changed if path.startswith(forbidden)]


def test_installer_accepts_any_v211x_and_preserves_cron_contract():
    builder = (ROOT / "tools/release/build-v2112-structural-package.sh").read_text(encoding="utf-8")
    workflow = (ROOT / ".github/workflows/p2k-v2112-structural.yml").read_text(encoding="utf-8")
    instructions = (ROOT / "INSTALL_v2.11.2.md").read_text(encoding="utf-8")
    assert 'case "$INSTALLED" in 2.11|2.11.*)' in builder
    assert 'crontab.before' in builder and 'crontab.after' in builder and 'cmp -s' in builder
    assert "install-promote-to-king-2.11.2.sh" in builder and "INSTALL_v2.11.2.txt" in builder
    assert "install-promote-to-king-2.11.2.sh" in workflow
    assert "./install-promote-to-king-2.11.2.sh /absolute/path/to/promote-to-king" in instructions
