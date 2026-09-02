import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_admin_job_contract_is_complete_and_non_scheduling():
    state = (ROOT / "server/team-points/src/AdminJob/JobState.php").read_text(encoding="utf-8")
    for field in ["job_id", "type", "state", "cursor", "completed", "total", "rate", "eta", "started_at", "updated_at", "checkpoint_backlog", "last_error"]:
        assert f"'{field}'" in state
    runner = (ROOT / "server/team-points/src/AdminJob/JobRunner.php").read_text(encoding="utf-8")
    assert "function observe" in runner
    assert "function run" not in runner and "function schedule" not in runner


def test_persistence_ownership_freezes_formats_and_cron():
    ownership = (ROOT / "PERSISTENCE_OWNERSHIP_v2.11.2.md").read_text(encoding="utf-8")
    for term in ["Core SQL", "Analytics SQL", "Blue/Green SQL", "Filesystem cache", "Session/OAuth", "Checkpoints", "Protected local configuration", "CRON"]:
        assert term in ownership
    assert "no schema" in ownership and "byte-identical entries" in ownership


def test_async_inventory_does_not_create_universal_policy_engine():
    data = json.loads((ROOT / "tests/v2.11.2-async-orchestration-inventory.json").read_text(encoding="utf-8"))
    assert len(data) >= 4
    assert all(row["policy_owner"] and row["consolidation"] != "universal engine" for row in data.values())


def test_corrective_layers_have_no_unproven_deletions():
    data = json.loads((ROOT / "tests/v2.11.2-corrective-layer-inventory.json").read_text(encoding="utf-8"))
    assert data["classifications"]["superseded_pending_proof"] == []
    assert "Old version names are not removal evidence" in data["policy"]


def test_frontend_activation_is_blocked_until_rendered_and_race_parity():
    data = json.loads((ROOT / "tests/v2.11.2-frontend-boundaries.json").read_text(encoding="utf-8"))
    assert data["layers"] == {"shared": 0, "feature": 1, "page": 2}
    assert "DOM, rendered and initialization-race parity" in data["activation_rule"]


def test_public_endpoint_inventory_is_exact():
    contract = json.loads((ROOT / "tests/v2.11.2-endpoint-inventory.json").read_text(encoding="utf-8"))
    actual = sorted(
        path.relative_to(ROOT).as_posix()
        for root in contract["roots"]
        for path in (ROOT / root).rglob("*.php")
    )
    assert actual == contract["files"]


def test_structural_metrics_are_current_and_frontend_is_unchanged():
    metrics = json.loads((ROOT / "tests/v2.11.2-structural-metrics.json").read_text(encoding="utf-8"))
    for path, values in metrics["frontend_bytes"].items():
        assert (ROOT / path).stat().st_size == values["current"] == values["baseline"]
    for path, values in metrics["facade_bytes"].items():
        assert (ROOT / path).stat().st_size == values["current"]
        assert values["current"] <= values["baseline"]
