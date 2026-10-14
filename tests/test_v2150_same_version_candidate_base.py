from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BUILDER = (ROOT / "tools/release/v2150/build-incremental.sh").read_text(encoding="utf-8")

def test_current_public_v2150_build_is_accepted_for_candidate_replacement():
    assert '"version":"2.15.0","source_head":"85a75741fd4612ff8d2f1a9cc6de453f2d42a6da"' in BUILDER

def test_previous_supported_public_bases_remain_accepted():
    assert '"version":"2.15.0","source_head":"90a195679e434c15a72a1636cf026eb9070d0b78"' in BUILDER
    assert '"version":"2.14.9","source_head":"2603b3f57f9bde29bbf0f6adf490b2bc533623ab"' in BUILDER
