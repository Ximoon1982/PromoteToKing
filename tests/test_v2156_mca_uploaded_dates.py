"""Regression checks for the non-destructive MCA CSV date repair (v2.15.6).

Run with Python 3, without external test framework or database credentials.
"""
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
LIVE=(ROOT/'server/team-points/src/LiveRanksService.php').read_text()
CRON=(ROOT/'server/team-points/src/McaResultsCronService.php').read_text()

def test_upload_recalculation_uses_index_date_not_upload_time():
    assert "LEFT JOIN p2k_lr_arena_acquisition a" in LIVE
    assert "a.club_slug=f.club_slug AND a.arena_id=f.arena_id" in LIVE
    assert "if($actual!==''){$effective=$actual;$precision='known';}" in LIVE
    assert "elseif($credible){$effective=$discovered;$precision=$source;}" in LIVE
    assert "else{$effective=substr((string)$row['uploaded_at'],0,10);$precision='upload-fallback';}" in LIVE
    assert "'index-visible-time','index-visible-date','index-machine','arena-machine','known'" in LIVE
    assert "'event_date_approximate' => !in_array" in LIVE

def test_existing_csvs_repaired_by_regular_acquisition_cron():
    assert "private function reconcileStoredSourceDatesFromAcquisition(): int" in CRON
    assert "$dateRepairs=$this->reconcileStoredSourceDatesFromAcquisition()" in CRON
    assert "'source_dates_repaired'=>$dateRepairs" in CRON
    assert "SET effective_event_date=actual_event_date,event_date_precision='known'" in CRON
    assert "WHERE f.club_slug=? AND f.actual_event_date IS NULL" in CRON
    assert "a.date_precision IN ('index-visible-time','index-visible-date','index-machine','arena-machine','known')" in CRON
    assert "f.event_date_precision IN ('unknown','upload-fallback')" in CRON

def test_results_integrity_and_scheduling_preserved():
    assert 'private function nextAcquisitionItem(): ?array' in CRON
    assert "f.status='processed' AND f.row_count>0" in CRON
    assert 'public function runHydration(int $maxSeconds=55)' in CRON
    assert 'private function processResults(' in CRON
    assert "requeueKnownInventoryForFullRefresh();" not in CRON.split("public function startFullDiscovery(): array",1)[1].split("public function queueHistoricalStatsBackfill(): array",1)[0]
    date_fix=CRON.split('private function reconcileStoredSourceDatesFromAcquisition(): int',1)[1].split('private function reconcileAcquisitionDatesFromCanonical(): int',1)[0]
    assert 'DELETE FROM' not in date_fix
    assert 'p2k_lr_arena_stats' not in date_fix
    assert 'p2k_lr_arena_games' not in date_fix

if __name__ == '__main__':
    for test in (test_upload_recalculation_uses_index_date_not_upload_time,
                 test_existing_csvs_repaired_by_regular_acquisition_cron,
                 test_results_integrity_and_scheduling_preserved):
        test()
        print("PASS",test.__name__)
