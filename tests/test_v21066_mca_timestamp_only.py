from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')


def test_release_identity():
    assert text('VERSION').strip()=='2.10.6.9'
    assert text('MIGRATION_VERSION').strip()=='2.10.6.9'
    assert 'version: "2.10.6.9"' in text('assets/js/site-config.js')


def test_filename_derives_only_known_arena_page_identity():
    php=r'''require "server/team-points/src/LiveRanksService.php";
    $r=new ReflectionClass("P2K\\TeamPoints\\LiveRanksService");$o=$r->newInstanceWithoutConstructor();$m=$r->getMethod("arenaIdentityFromName");$m->setAccessible(true);
    $x=$m->invoke($o,"the-100-rapid-arena-organised-from-the-ge-winner-3463143.csv");
    if(($x['arena_id']??0)!==3463143)exit(11);
    if(($x['arena_url']??'')!=="https://www.chess.com/tournament/live/arena/the-100-rapid-arena-organised-from-the-ge-winner-3463143")exit(12);
    if(array_key_exists('csv_url',$x))exit(13);echo "ok";'''
    p=subprocess.run(['php','-r',php],cwd=ROOT,text=True,capture_output=True)
    assert p.returncode==0,(p.stdout,p.stderr)
    assert p.stdout.strip()=='ok'


def test_visible_arena_header_timestamp_is_parsed():
    php=r'''require "server/team-points/src/LiveRanksService.php";
    $r=new ReflectionClass("P2K\\TeamPoints\\LiveRanksService");$o=$r->newInstanceWithoutConstructor();$m=$r->getMethod("extractArenaStart");$m->setAccessible(true);
    $html='<div>Rating: Open 176 players Jun 22, 2024, 8:59 AM</div><time datetime="2028-08-07T00:00:00Z"></time>';
    $x=$m->invoke($o,$html);if(($x['event_date']??'')!=="2024-06-22")exit(21);if(($x['precision']??'')!=="visible")exit(22);echo "ok";'''
    p=subprocess.run(['php','-r',php],cwd=ROOT,text=True,capture_output=True)
    assert p.returncode==0,(p.stdout,p.stderr)
    assert p.stdout.strip()=='ok'


def test_runtime_contains_no_arena_discovery_or_csv_acquisition():
    svc=text('server/team-points/src/LiveRanksService.php')
    forbidden=[
        'clubs/pastevents', 'discoverArenaEntries', 'discoverArenaLinks',
        'discoverPaginationLinks', 'advanceIndexPagination', 'download-results',
        'extractArenaCsvUrl', 'arenaPageResultRows', 'arenaPageResultsCsv',
        'beginArenaResultPagination', 'advanceArenaResultPagination',
        'storeFetchedCsv', 'possibleRenameArenaIdentities', 'results_pages'
    ]
    for token in forbidden:
        assert token not in svc, token
    assert 'seedTimestampQueue' in svc
    assert "needs_csv=0" in svc
    assert "$page=$this->syncHttpGet((string)$item['arena_url']);$date=$this->extractArenaStart" in svc


def test_start_cycle_uses_only_stored_missing_dates_and_no_network():
    svc=text('server/team-points/src/LiveRanksService.php')
    start=svc[svc.index('public function startAutoSync'):svc.index('public function retryAutoSyncErrors')]
    seed=svc[svc.index('private function seedTimestampQueue'):svc.index('private function normalizeLegacySyncCycle')]
    assert 'syncHttpGet' not in start
    assert 'seedTimestampQueue()' in start
    assert 'storedFileRecords()' in seed
    assert "if(!empty($file['actual_event_date']))continue" in seed
    assert "stage='page'" in seed and 'needs_csv=0' in seed and 'needs_date=1' in seed


def test_cron_does_not_rebuild_dataset_or_download_sources():
    svc=text('server/team-points/src/LiveRanksService.php')
    cron=svc[svc.index('public function runAutoSyncCron'):svc.index('public function statusPayload')]
    assert 'startProcessing' not in cron
    assert 'storeFetchedCsv' not in cron
    js=text('assets/js/pages/team-points-features.js')
    assert 'No arena discovery or CSV download was performed.' in js
    assert 'Scanning the MCA index' not in js
