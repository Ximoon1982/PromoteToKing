"""GAB reconciliation: track trusted identity disagreement without guessing aliases."""
from pathlib import Path

src = (Path(__file__).resolve().parents[1] / "server/team-points-green/src/GreenCompatibility.php").read_text()

def test_projector_tracks_conflicting_authorities_without_auto_merging():
    projector = src.split("public function projectMatch(",1)[1].split("public function projectMatchBatch(",1)[0]
    assert "mi.canonical_username_key<>im.canonical_username_key" in projector
    assert "im.trusted=1" in projector
    assert "'identity_authority_conflict'" in projector
    assert "canonicalGameProjection($matchId" in projector
    assert "DELETE FROM p2k_g_gab_unresolved_boards" in projector

def test_reconciliation_suppresses_only_current_active_conflict_games():
    recon = src.split("public function reconcileCompatibilityBatch(",1)[1].split("public function rebuildAnalytics(",1)[0]
    assert recon.count("ui.reason='identity_authority_conflict'")==2
    assert recon.count("ix.trusted=1")==2
    assert recon.count("ix.canonical_username_key<>mx.canonical_username_key")==2
    assert recon.count("COUNT(DISTINCT mp2.board_no)")==2
    assert recon.count("COUNT(DISTINCT CONCAT(gx.board_no")==2

def test_no_authority_overwrite():
    assert "UPDATE p2k_miac_canonical_map" not in src
    assert "UPDATE p2k_g_identity_map" not in src
