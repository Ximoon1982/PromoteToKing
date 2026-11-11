"""MCA/MIAC reconciliation regression contracts; no live database mutation.

Run: python3 tests/test_v2157_mca_miac_review.py
"""
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
service=(ROOT/'server/team-points/src/McaRenameReviewService.php').read_text()
api=(ROOT/'server/team-points/public/mca-renames.php').read_text()
miac=(ROOT/'server/team-points/src/MiacService.php').read_text()
live=(ROOT/'server/team-points/src/LiveRanksService.php').read_text()
html=(ROOT/'TeamPointsAdmin.html').read_text()
ui=(ROOT/'assets/js/pages/mca-rename-review-v2157.js').read_text()
features=(ROOT/'assets/js/pages/team-points-features.js').read_text()
ci=(ROOT/'assets/js/pages/club-intelligence.js').read_text()

def test_one_shared_miac_identity_authority():
    assert "FROM p2k_miac_canonical_map" in service
    assert "FROM p2k_miac_edges" in service
    assert "p2k_lr_players WHERE club_slug=? AND account_state='possible_renamed'" in service
    assert "private MiacService $miac" in service
    assert "return $this->miac->reviewEdge($old, $new, $decision, 'admin:mca_review')" in service
    assert "recordDefinitiveSubstitution" not in service
    assert "UPDATE p2k_lr_players" not in service
    assert "DELETE FROM p2k_lr" not in service
    assert "INSERT IGNORE INTO p2k_miac_edges" in service
    assert "'candidate','mca_possible_rename'" in service

def test_resolved_aliases_not_in_unresolved_queue():
    assert "empty($identity['conflict']) && ($canonicalKey !== $key || $confirmedTarget !== null)" in service
    assert "'resolved_pending_attribution' => $resolved" in service
    assert "'unresolved' => $unresolved" in service
    assert "if ($isResolved)" in service
    assert "ready_for_miac_review" in service
    assert "identity_map_generation" in service
    assert "p2k_lr_source_rows" in service

def test_human_review_and_identity_protection():
    assert "rejected and cannot be silently proposed again" in service
    assert "MCA_ALIAS_ALREADY_RESOLVED" in service
    assert "if ($old === $new)" in service
    assert "MiacService" in miac
    assert "if($decision==='confirmed' && $this->edgePlayerIdConflict($old,$new))$decision='conflict'" in miac
    assert "MIAC" in ui and "CONFIRM" in ui and "REJECT" in ui
    assert "window.confirm" in ui
    assert "mcaRenameRebuild" in ui and "liveRanksProcess" in ui
    assert "Rebuild MCA identity attribution" in html

def test_write_auth_and_preview_isolation():
    assert "Auth::requireAdmin();" in api
    assert "Http::method('POST')" in api
    assert "PreviewIsolation::active()" in api
    assert "'PREVIEW_READ_ONLY'" in api
    assert "['confirmed', 'rejected']" in api
    assert "exportUnresolvedCsv()" in api

def test_transport_errors_are_not_false_renames():
    step=live.split('public function processProfileBatch(',1)[1].split('public function autoSyncStatus(',1)[0]
    assert "in_array($status, [404, 410], true)" in step
    assert "in_array($httpCode, [404, 410], true)" in step
    assert "$conclusive = false;" in step
    assert "status >= 500" in (ROOT/'server/team-points/public/live-ranks-admin.php').read_text()

def test_ui_and_direct_alias_review_routing():
    assert "mcaRenameRows" in html
    assert "mcaRenamePager" in html
    assert "mca-rename-review-v2157.js" in html
    assert "mca-renames.php" in ui
    assert "mca-renames.php" in features
    assert "mcaRenameAttributionStatus" in html
    assert "miac_search" in ui and "miac_search" in ci
    assert "query.set(\"search\",aliasSearch)" in ci
    assert "raw: true" in features
    assert "const perPage = 25" in ui

if __name__=='__main__':
    tests=[x for k,x in globals().items() if k.startswith('test_') and callable(x)]
    for fn in tests:
        fn()
        print('PASS',fn.__name__)
    print('PASS',len(tests),'MCA/MIAC regression contracts')
