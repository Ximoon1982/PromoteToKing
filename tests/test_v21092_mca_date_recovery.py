from pathlib import Path
import json
import subprocess

ROOT = Path(__file__).resolve().parents[1]


def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8')


def php_eval(code, no_ini=False):
    cmd = ['php'] + (['-n'] if no_ini else []) + ['-r', code]
    return subprocess.run(cmd, cwd=ROOT, text=True, capture_output=True)


def test_release_identity_and_no_schema_or_cron_change():
    assert text('VERSION').strip() == '2.10.9.2'
    assert text('MIGRATION_VERSION').strip() == '2.10.9.2'
    manifest = json.loads(text('site-manifest.json'))
    assert manifest['version'] == '2.10.9.2'
    assert manifest['release']['sourceBaseline'] == 'v2.10.9.1'
    assert manifest['release']['databaseSchemaChange'] is False
    release = json.loads(text('RELEASE_v2.10.9.2.json'))
    assert release['cron_change'] is False


def test_arena_page_adjacent_spans_and_narrow_nbsp_are_parsed():
    code = r'''
require "server/team-points/src/McaIndexParser.php";
require "server/team-points/src/McaArenaParser.php";
$h='<div class="tournaments-live-view-content-stats"><span>Rating: Open</span><span>127 players</span><span>Aug 18, 2026, 7:30'."\u{202F}".'PM</span></div>';
$x=\P2K\TeamPoints\McaArenaParser::arenaPage($h);
if(($x['event_date']??'')!=='2026-08-18')exit(11);
if(($x['event_start_at']??'')!=='2026-08-18 19:30:00')exit(12);
echo 'ok';
'''
    p = php_eval(code)
    assert p.returncode == 0, (p.stdout, p.stderr)
    assert p.stdout.strip() == 'ok'


def test_index_row_recovers_date_without_dom_extension():
    code = r'''
require "server/team-points/src/McaIndexParser.php";
$h='<table><tr><td><a href="/tournament/live/arena/kyus-10-ultrabullet-arena-3315960">KYUS 10</a></td><td>Open</td><td>127</td><td>Aug 18, 2026, 7:30'."\u{202F}".'PM</td></tr></table>';
$x=\P2K\TeamPoints\McaIndexParser::parse($h,1);
if(count($x['events'])!==1)exit(21);
$e=$x['events'][0];
if(($e['arena_id']??0)!==3315960)exit(22);
if(($e['event_date']??'')!=='2026-08-18')exit(23);
echo 'ok';
'''
    p = php_eval(code, no_ini=True)
    assert p.returncode == 0, (p.stdout, p.stderr)
    assert p.stdout.strip() == 'ok'


def test_machine_timestamp_remains_supported():
    code = r'''
require "server/team-points/src/McaIndexParser.php";
require "server/team-points/src/McaArenaParser.php";
$h='<script>window.x={"startTime":"2026-08-18T19:30:00Z"}</script><div><span>Rating: Open</span><span>127 players</span></div>';
$x=\P2K\TeamPoints\McaArenaParser::arenaPage($h);
if(($x['event_date']??'')!=='2026-08-18')exit(31);
if(($x['date_precision']??'')!=='arena-machine')exit(32);
echo 'ok';
'''
    p = php_eval(code)
    assert p.returncode == 0, (p.stdout, p.stderr)
    assert p.stdout.strip() == 'ok'


def test_manual_parser_is_only_a_canonical_wrapper():
    svc = text('server/team-points/src/LiveRanksService.php')
    start = svc.index('private function extractArenaStart')
    end = svc.index('private function manualDateIndexUrl', start)
    body = svc[start:end]
    assert 'McaArenaParser::arenaPage($html)' in body
    assert 'preg_match' not in body
    assert 'strip_tags' not in body


def test_manual_backfill_has_resumable_index_fallback():
    svc = text('server/team-points/src/LiveRanksService.php')
    step = svc[svc.index('public function autoSyncStep'):svc.index('public function acknowledgeAutoSyncRebuild')]
    assert "if($stage==='page')" in step
    assert "elseif($stage==='csv')" in step
    assert 'McaIndexParser::parse' in step
    assert 'manualDateIndexUrl(1)' in step
    assert 'manualDateIndexUrl($indexPage+1)' in step
    assert "stage='csv',csv_url=?" in step
    assert 'return $this->autoSyncStatus();' in step
    assert 'Arena date unavailable after exhaustive MCA index search.' in step
    # The request pacing remains centralized and shared by both stages.
    assert 'waitForSyncRequestSlot();' in svc
    assert 'if($elapsed<1.0)usleep' in svc


def test_manual_retry_restarts_page_and_clears_index_cursor():
    svc = text('server/team-points/src/LiveRanksService.php')
    retry = svc[svc.index('public function retryAutoSyncErrors'):svc.index('private function finishSyncItem')]
    assert "stage='page'" in retry
    assert "csv_url=''" in retry


def test_worker_index_lookup_remains_exhaustive():
    svc = text('server/team-points/src/McaResultsCronService.php')
    assert 'if($oldest<$arenaId)' not in svc
    assert 'if(empty($parsed[\'has_next\']))return null' in svc
    assert 'McaIndexParser::parse' in svc


def test_browser_duplicate_filename_maps_to_same_arena_identity():
    code = r'''
require "server/team-points/src/McaSourceCatalogue.php";
use P2K\TeamPoints\McaSourceCatalogue;
$a=McaSourceCatalogue::identityFromName('the-lucky-group-monday-multiclub-blitz-30--31368823 (2).csv');
$b=McaSourceCatalogue::identityFromName('the-lucky-group-monday-multiclub-blitz-30--31368823.csv');
if(($a['arena_id']??0)!==31368823||($b['arena_id']??0)!==31368823)exit(41);
if(($a['arena_slug']??'')!==($b['arena_slug']??''))exit(42);
if(($a['original_name']??'')!==($b['original_name']??''))exit(43);
if(($a['copy_index']??0)!==2)exit(44);
echo 'ok';
'''
    p = php_eval(code)
    assert p.returncode == 0, (p.stdout, p.stderr)
    assert p.stdout.strip() == 'ok'


def test_catalogue_excludes_browser_copies_non_destructively_and_flags_conflicts():
    code = r'''
require "server/team-points/src/McaSourceCatalogue.php";
use P2K\TeamPoints\McaSourceCatalogue;
$rows=[
 ['id'=>1,'original_name'=>'arena-name-123.csv','sha256'=>'aaa','row_count'=>100,'source_origin'=>'manual','uploaded_at'=>'2026-01-01 00:00:00'],
 ['id'=>2,'original_name'=>'arena-name-123 (1).csv','sha256'=>'aaa','row_count'=>100,'source_origin'=>'manual','uploaded_at'=>'2026-01-02 00:00:00'],
 ['id'=>3,'original_name'=>'arena-name-123 (2).csv','sha256'=>'bbb','row_count'=>101,'source_origin'=>'manual','uploaded_at'=>'2026-01-03 00:00:00'],
 ['id'=>4,'original_name'=>'other-arena-456.csv','sha256'=>'ccc','row_count'=>80,'source_origin'=>'manual','uploaded_at'=>'2026-01-04 00:00:00'],
];
$x=McaSourceCatalogue::analyze($rows);
if(($x['stored_records']??0)!==4)exit(51);
if(($x['recognized_arena_sources']??0)!==2)exit(52);
if(($x['canonical_sources']??0)!==2)exit(53);
if(($x['duplicate_records']??0)!==2)exit(54);
if(($x['duplicate_groups']??0)!==1)exit(55);
if(($x['conflicting_duplicate_groups']??0)!==1)exit(56);
if((int)($x['canonical_by_arena'][123]['id']??0)!==1)exit(57); // unsuffixed canonical name wins
if(empty($x['row_meta'][2])||!empty($x['row_meta'][2]['canonical']))exit(58);
echo 'ok';
'''
    p = php_eval(code)
    assert p.returncode == 0, (p.stdout, p.stderr)
    assert p.stdout.strip() == 'ok'


def test_processing_and_manual_dates_use_canonical_catalogue_only():
    svc = text('server/team-points/src/LiveRanksService.php')
    assert "McaSourceCatalogue::analyze($this->allStoredFileRecords())['canonical_rows']" in svc
    assert 'canonicalSourceForArena($arenaId)' in svc
    assert "source_integrity' => $this->sourceIntegrity()" in svc
    cron = text('server/team-points/src/McaResultsCronService.php')
    assert "foreach($this->sourceCatalogue()['canonical_rows'] as $f)" in cron
    assert "duplicate_source_records" in cron
    assert "markSourceIntegrityRebuildIfNeeded" in cron
    assert "Arena date unavailable after exhaustive MCA index search." in cron


def test_admin_surfaces_canonical_vs_stored_source_counts():
    html = text('TeamPointsAdmin.html')
    js = text('assets/js/pages/team-points-features.js')
    assert 'MCA source integrity' in html
    assert 'non-destructive deduplication' in html
    assert 'Canonical MCA sources' in js
    assert 'Stored CSV records' in js
    assert 'Legacy duplicate records' in js
    assert 'Excluded legacy duplicate' in js
