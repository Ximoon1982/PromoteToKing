"""MCA regression: index refresh is non-destructive; missing parsed Results come first.

Run: python3 tests/test_v2155_mca_results_priority.py
No external dependencies or database needed.
"""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'server/team-points/src/McaResultsCronService.php').read_text(encoding='utf-8')


def test_index_refresh_does_not_reset_stored_arena_acquisition():
    body = SOURCE.split('public function startFullDiscovery(): array', 1)[1].split('public function queueHistoricalStatsBackfill(): array', 1)[0]
    assert 'requeueKnownInventoryForFullRefresh' not in body, 'Index-only refresh must not restart all Results/Pairings stages'
    assert "current_stage='index:1'" in body, 'Full index traversal must still start from page 1'
    assert "high_water_arena_id=1" in body, 'Full index traversal must still be explicit'


def test_missing_results_are_prioritized_over_requeued_enrichment():
    body = SOURCE.split('private function nextAcquisitionItem(): ?array', 1)[1].split('private function acquisitionStep(', 1)[0]
    assert 'NOT EXISTS' in body
    assert "f.status='processed' AND f.row_count>0" in body, 'Uploaded but unprocessed CSV is not sufficient'
    assert 'f.club_slug=a.club_slug AND f.arena_id=a.arena_id' in body
    assert re.search(r'THEN 0 ELSE 1 END ASC,\s*a\.priority DESC,\s*a\.arena_id DESC', body)
    assert 'LIMIT 1' in body, 'Worker must be bounded'
    assert "WHERE a.club_slug=? AND a.status='pending'" in body


def test_existing_worker_and_scoring_contracts_are_preserved():
    assert 'public function runHydration(int $maxSeconds=55)' in SOURCE
    assert "if(count($events)<25)" in SOURCE
    assert 'function completeDiscoveryCycle' in SOURCE
    assert 'function processResults' in SOURCE
    assert "'discovery_strategy'=>'index-occurrence-exhaustive'" in SOURCE
    assert "status='error',last_error=?" in SOURCE


if __name__ == '__main__':
    tests = [test_index_refresh_does_not_reset_stored_arena_acquisition,
             test_missing_results_are_prioritized_over_requeued_enrichment,
             test_existing_worker_and_scoring_contracts_are_preserved]
    for test in tests:
        test()
        print('PASS', test.__name__)
