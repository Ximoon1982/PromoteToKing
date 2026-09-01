import importlib.util
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNNER = ROOT / "tools/test-suite/p2k_test_suite.py"


def load_runner():
    spec = importlib.util.spec_from_file_location("p2k_test_suite", RUNNER)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def test_suite_inventory_is_parseable_and_policy_backed():
    runner = load_runner()
    report = runner.inventory()
    assert report["python"]["parse_errors"] == []
    assert report["python"]["duplicate_test_names"] == []
    assert runner.validate_policy(report) == []
    assert any(row["path"] == "tests/test_v2107_release.py" and row["kind"] == "standalone" for row in report["python"]["modules"])
    assert any(row["path"] == "tests/test_v2111_persistent_oauth.py" and row["kind"] == "pytest" for row in report["python"]["modules"])


def test_policy_prevents_silent_regression_gate_loss():
    policy = json.loads((ROOT / "tests/test-suite-policy.json").read_text(encoding="utf-8"))
    assert policy["minimum_counts"]["pytest_functions"] >= 791
    assert "tests/test_v2111_persistent_oauth.py" in policy["required_gates"]
    assert "tests/run-tests.js" in policy["required_gates"]
    assert policy["maximum_known_debt"] == {"pytest": 243, "standalone": 17}
    debt = json.loads((ROOT / "tests/known-regression-debt-v2.11.1.json").read_text(encoding="utf-8"))
    assert len(debt["known_failures"]) == 243
    assert len(debt["known_standalone_failures"]) == 17


def test_current_branch_has_one_canonical_regression_workflow():
    workflow = (ROOT / ".github/workflows/p2k-v2111-regression.yml").read_text(encoding="utf-8")
    assert "release/v2.11.1" in workflow
    assert "p2k_test_suite.py audit" in workflow
    assert "p2k_test_suite.py regression" in workflow
    assert "p2k_test_suite.py browser" in workflow
    assert "tests/requirements.txt" in workflow


def test_hardening_release_is_product_behavior_neutral():
    audit = (ROOT / "TEST_SUITE_AUDIT_v2.11.1.md").read_text(encoding="utf-8")
    assert "No intentional product behavior change" in audit
    assert "functional and visual parity" in audit
