from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def text(relative: str) -> str:
    return (ROOT / relative).read_text(encoding="utf-8")


def test_v2114_gate_is_anchored_to_exact_qualified_v2113_and_visuals_are_frozen():
    gate = text("tools/test-suite/structural_parity_v2114.py")
    assert 'BASELINE = "dcd71c8e76c07defacf6270aff4224b10484968b"' in gate
    for path in (
        "assets/js/pages/task-control.js",
        "server/team-points-green/public/api.php",
        "server/team-points-green/src/GreenAnalyticsBootstrap.php",
        "server/team-points-green/src/GreenCompatibility.php",
        "server/team-points-green/src/GreenRepository.php",
        "server/team-points-green/src/GreenWorker.php",
    ):
        assert f'"{path}"' in gate
    assert 'visual_suffixes = (".css"' in gate


def test_v2114_installer_qualifies_every_representative_211_baseline():
    qualification = text("tools/release/qualify-v2114-installer.sh")
    for label, revision in (
        ("v2.11.0-initial", "2ca1fc191aeef444b4886b53e25a54a83820c25c"),
        ("v2.11.0-R4", "8863366bde7cb6989ce0b99aed650c3d0dfd5c01"),
        ("v2.11.0-R6", "93480c852fc4c554c9a404e5d68b0ac51efed04b"),
        ("v2.11.1", "b8bf26c7c41ca1914323717766bca995139291aa"),
        ("v2.11.2", "4ececcc230ca07099b346cb47396ad00bedd5c21"),
        ("v2.11.3", "dcd71c8e76c07defacf6270aff4224b10484968b"),
        ("current-2.11.x", "HEAD"),
    ):
        assert f'"{label}:{revision}"' in qualification
    for invariant in ("protected-before", "crontab", "reinstalled", "P2K_FORCE_INSTALL_FAILURE", "rollback-after"):
        assert invariant in qualification


def test_v2114_workflow_owns_main_prs_and_frozen_v2113_does_not():
    current = text(".github/workflows/p2k-v2114-operational.yml")
    historical = text(".github/workflows/p2k-v2113-runtime.yml")
    assert "branches: [main, release/v2.11.4]" in current
    assert "branches: [release/v2.11.3]" in historical
    assert "branches: [main, release/v2.11.3]" not in historical
    assert "build-v2114-operational-package.sh" in current
    assert "qualify-v2114-installer.sh" in current


def test_v2114_builder_reuses_comprehensive_immutable_cache_key_gate():
    builder = text("tools/release/build-v2114-operational-package.sh")
    assert "static_asset_cache_key.py\" stamp" in builder
    assert "static_asset_cache_key.py\" verify" in builder
    assert "SOURCE_HEAD=$(git -C \"$ROOT\" rev-parse HEAD)" in builder
    assert "BUILD_ID=${P2K_BUILD_ID:-local-" in builder
    for asset in ("admin-shell.js", "admin-session-controller.js", "tool-registry.js", "match-assistant.js", "dashboard-v2.js", "api-client.js"):
        assert asset in builder
