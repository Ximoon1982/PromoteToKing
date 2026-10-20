from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BUILDER = (ROOT / "tools/release/v2150/build-incremental.sh").read_text(encoding="utf-8")

def test_current_public_v2150_build_is_accepted_for_candidate_replacement():
    assert '"version":"2.15.0","source_head":"85a75741fd4612ff8d2f1a9cc6de453f2d42a6da"' in BUILDER

def test_previous_supported_public_bases_remain_accepted():
    assert '"version":"2.15.0","source_head":"90a195679e434c15a72a1636cf026eb9070d0b78"' in BUILDER
    assert '"version":"2.14.9","source_head":"2603b3f57f9bde29bbf0f6adf490b2bc533623ab"' in BUILDER


def test_latest_promoted_v2150_build_is_accepted():
    assert '"version":"2.15.0","source_head":"d97cbb6832750aed633f55d450bbc87a0ab43458"' in BUILDER


def test_current_promoted_v2150_build_is_accepted():
    assert '"version":"2.15.0","source_head":"05229163a970e16e443cb88c1df3eaeaf677b268"' in BUILDER


def test_green_public_v2150_build_is_accepted():
    assert '"version":"2.15.0","source_head":"1720731cea4f7b26b27ab5e7dd88f7272e658a20"' in BUILDER


def test_all_changed_v2150_runtime_files_are_packaged():
    required = [
        "TeamPointsAdmin.html",
        "assets/js/pages/team-points-admin.js",
        "server/team-points-green/public/api.php",
        "server/team-points-green/sql/core-schema.sql",
        "server/team-points-green/src/GreenCompatibility.php",
    ]
    for path in required:
        assert f"  {path}\\n" in BUILDER
