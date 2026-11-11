"""MCA re-download / MIAC automatic identity attribution integration guard."""
from pathlib import Path
R=Path(__file__).resolve().parents[1]
SERVICE=(R/'server/team-points/src/McaRenameRecheckService.php').read_text()
CRON=(R/'server/team-points/bin/mca-results-sync.php').read_text()
API=(R/'server/team-points/public/mca-renames.php').read_text()
UI=(R/'assets/js/pages/mca-rename-review-v2157.js').read_text()
HTML=(R/'TeamPointsAdmin.html').read_text()
MIAC=(R/'server/team-points/src/MiacService.php').read_text()
REVIEW=(R/'server/team-points/src/McaRenameReviewService.php').read_text()

def test_worker_downloads_only_known_missing_case_sources():
    assert "p.account_state='possible_renamed'" in SERVICE
    assert "r.raw_username_key=p.username_key" in SERVICE
    assert "f.status='processed' AND f.arena_id>0" in SERVICE
    assert "McaSourceCatalogue::identityFromRow($file)" in SERVICE
    assert "hash('sha256',$oldCsv)!==strtolower((string)$file['sha256'])" in SERVICE
    assert "/download-results" in SERVICE
    assert "McaArenaParser::resultsCsv($oldCsv)" in SERVICE
    assert "McaArenaParser::resultsCsv($latest)" in SERVICE
    assert "SELECT GET_LOCK(?,0)" in SERVICE
    assert "last_request_at=UTC_TIMESTAMP(6)" in SERVICE
    assert "one name" not in SERVICE or "more than one" in SERVICE.lower()

def test_only_definitive_correlations_auto_link():
    assert "count($changed)!==1" in SERVICE
    assert "$rankBefore<=0 || $rankBefore!==$rankAfter" in SERVICE
    assert "abs((float)$scoreBefore-(float)$scoreAfter)>0.000001" in SERVICE
    assert "in_array($b,$oldNames,true)" in SERVICE
    assert "'status'=>'correlated'" in SERVICE
    assert "recordDefinitiveSubstitution(" in SERVICE
    assert "'mca_substitution'" in SERVICE
    assert "'original_source_unchanged'=>true" in SERVICE
    assert "if($existingStatus==='rejected')" not in SERVICE  # delegated to MIAC
    assert "$existingStatus==='rejected'?'rejected'" in MIAC
    assert "edgePlayerIdConflict($old,$new)" in MIAC
    assert "MIAC" in REVIEW

def test_preserves_source_and_limits_fanout():
    assert "file_put_contents($path,$latest)" not in SERVICE
    assert "UPDATE p2k_lr_files SET" not in SERVICE
    assert "DELETE FROM p2k_lr" not in SERVICE
    assert "LIMIT 3000" in SERVICE
    assert "'next_after'=>time()+3600" in SERVICE
    assert "$record['next_after'] = time()+365*86400" in SERVICE
    assert "flock($lock, LOCK_EX | LOCK_NB)" in SERVICE
    assert "rename($tmp,$this->stateFile)" in SERVICE
    assert "data/live-ranks/uploads" in SERVICE

def test_is_automatic_and_visible_and_preview_isolated():
    assert "new McaRenameRecheckService(" in CRON
    assert "$renames->runStep(8)" in CRON
    assert "$seconds-(microtime(true)-$started)" in CRON
    assert "catch (Throwable $error)" in CRON
    assert "if ($action === 'recheck')" in API
    assert "PreviewIsolation::active()" in API
    assert "'csv_rechecks'" in API
    assert "mcaRenameCsvCheckStatus" in HTML
    assert "mcaRenameRecheck" in HTML
    assert "recheckNext()" in UI
    assert "call('recheck'" in UI
    assert "automatic" in UI

if __name__=='__main__':
    tests=[v for k,v in globals().items() if k.startswith('test_') and callable(v)]
    for t in tests:
        t()
        print('PASS',t.__name__)
