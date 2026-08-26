from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_identity():
    assert text('VERSION').strip()=='2.10.6.4'
    assert text('MIGRATION_VERSION').strip()=='2.10.6.4'
    assert 'version: "2.10.6.4"' in text('assets/js/site-config.js')

def test_index_exposed_csv_is_bound_to_arena():
    php=r'''require "server/team-points/src/LiveRanksService.php";
    $r=new ReflectionClass("P2K\\TeamPoints\\LiveRanksService");$o=$r->newInstanceWithoutConstructor();$m=$r->getMethod("discoverArenaEntries");$m->setAccessible(true);
    $base='https://www.chess.com/clubs/pastevents/promote-to-king?type=multi-club-arena&page=2';
    $html='<section><a href="/tournament/live/arena/alpha-31319871">Alpha</a><a class="download" href="/tournament/export/31319871/results?format=csv">Download CSV</a></section>'
         .'<section><a href="/tournament/live/arena/beta-31320427">Beta</a><a data-role="csv" href="/downloads/results.csv?event=31320427">CSV</a></section>';
    $x=$m->invoke($o,$html,$base);if(count($x)!==2)exit(11);$by=[];foreach($x as $row)$by[$row['arena_id']]=$row;
    if(($by[31319871]['csv_url']??'')!=='https://www.chess.com/tournament/export/31319871/results?format=csv')exit(12);
    if(($by[31320427]['csv_url']??'')!=='https://www.chess.com/downloads/results.csv?event=31320427')exit(13);echo 'ok';'''
    p=subprocess.run(['php','-r',php],cwd=ROOT,text=True,capture_output=True)
    assert p.returncode==0,(p.stdout,p.stderr)
    assert p.stdout.strip()=='ok'

def test_nearby_csv_without_id_can_be_bound_to_index_event():
    php=r'''require "server/team-points/src/LiveRanksService.php";
    $r=new ReflectionClass("P2K\\TeamPoints\\LiveRanksService");$o=$r->newInstanceWithoutConstructor();$m=$r->getMethod("discoverArenaEntries");$m->setAccessible(true);
    $base='https://www.chess.com/clubs/pastevents/promote-to-king?type=multi-club-arena';
    $html='<article><a href="/tournament/live/arena/alpha-31319871">Alpha</a><a href="/download/results?format=csv">Download CSV</a></article>';
    $x=$m->invoke($o,$html,$base);if(count($x)!==1||empty($x[0]['csv_url'])||!str_contains($x[0]['csv_url'],'format=csv'))exit(21);echo 'ok';'''
    p=subprocess.run(['php','-r',php],cwd=ROOT,text=True,capture_output=True)
    assert p.returncode==0,(p.stdout,p.stderr)
    assert p.stdout.strip()=='ok'

def test_index_csv_is_primary_route_and_retry_preserves_it():
    svc=text('server/team-points/src/LiveRanksService.php')
    assert 'discoverArenaEntries((string)$index[\'body\'],$indexUrl)' in svc
    assert 'discoverArenaEntries((string)$page[\'body\'],$url)' in svc
    assert "$queuedCsv!==''?$queuedCsv:$this->extractArenaCsvUrl" in svc
    assert "stage=CASE WHEN needs_csv=1 AND csv_url<>'' AND needs_date=0 THEN 'csv' ELSE 'page' END" in svc
    assert "CASE WHEN VALUES(csv_url)<>'' THEN VALUES(csv_url) ELSE csv_url END" in svc

def test_existing_pagination_and_completeness_fallback_remain_available():
    svc=text('server/team-points/src/LiveRanksService.php')
    assert 'discoverPaginationLinks' in svc
    assert 'beginArenaResultPagination' in svc
    assert 'advanceArenaResultPagination' in svc
    assert 'advertised player count' in svc
