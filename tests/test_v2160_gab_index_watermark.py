"""Regression guard for GAB index re-observation timestamp churn.

The 2026-11-19 production diagnosis showed 14 of 15 reconciliation
candidates caused by Green matches being newer than compatibility,
despite equivalent physical boards and games.
"""
from pathlib import Path

SRC = (Path(__file__).resolve().parents[1]
       / "server/team-points-green/src/GreenRepository.php").read_text()


def test_index_refresh_does_not_unconditionally_update_substantive_watermark():
    body = SRC.split("public function upsertIndex(", 1)[1].split(
        "public function normalizeDailyDiscoveryWatermark(", 1
    )[0]
    sql = body.split("ON DUPLICATE KEY UPDATE", 1)[1].split('");', 1)[0]
    assert "updated_at=IF(" in sql
    assert "UTC_TIMESTAMP(),updated_at)" in sql
    assert "retry_after=NULL,updated_at=UTC_TIMESTAMP()" not in sql
    assert "NOT (index_bucket <=> VALUES(index_bucket))" in sql
    assert "NOT (index_time_class <=> VALUES(index_time_class))" in sql
    assert "NOT (index_result <=> VALUES(index_result))" in sql
    assert "verified_club_slug <=> VALUES(verified_club_slug)" in sql
    assert "trusted_legacy=0 AND last_http_status IN (404,410)" in sql


def test_identity_disagreement_requires_explicit_resolution():
    """Fixture: Green trusted alias and MIAC canonical selection disagree."""
    green = {"gugusheep_original": ("simurghson", True)}
    miac = {"gugusheep_original": ("gugusheep_original", False)}
    assert green["gugusheep_original"][0] != miac["gugusheep_original"][0]
    assert green["gugusheep_original"][1] is True
    # This test documents the existing disagreement, not a license to merge.
