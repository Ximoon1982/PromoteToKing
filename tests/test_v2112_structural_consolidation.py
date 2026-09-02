import hashlib
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


def test_ui_display_and_javascript_runtime_are_byte_identical():
    parity = json.loads((ROOT / "tests/v2.11.2-ui-parity.json").read_text(encoding="utf-8"))
    for relative, expected in parity["files"].items():
        assert hashlib.sha256((ROOT / relative).read_bytes()).hexdigest() == expected, relative


def test_compatibility_facades_delegate_only_bounded_responsibilities():
    architecture = json.loads((ROOT / "tests/v2.11.2-architecture.json").read_text(encoding="utf-8"))
    for facade, components in architecture["compatibility_facades"].items():
        source = (ROOT / f"server/team-points/src/{facade}.php").read_text(encoding="utf-8")
        for component in components:
            assert component in source
            assert (ROOT / f"server/team-points/src/{component}.php").is_file()
    assert "oauth-session" in architecture["frozen_contracts"]


def test_no_ui_api_schema_scheduler_or_authentication_files_changed():
    if not (ROOT / ".git").exists():
        pytest.skip("Git changed-path comparison is repository-scoped.")
    changed = subprocess.run(["git", "diff", "--name-only", f"{BASELINE}..HEAD"], cwd=ROOT, check=True, text=True, capture_output=True).stdout.splitlines()
    forbidden = ("assets/", "api/", "server/team-points/public/", "server/team-points/sql/", "ui-v2.html", "ClubIntelligence.html", "site-manifest.json", "reset-install-", "install-oauth-")
    assert not [path for path in changed if path.startswith(forbidden)]
