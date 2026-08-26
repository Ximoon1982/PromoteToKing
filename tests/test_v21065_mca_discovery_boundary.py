from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_identity():
    assert text('VERSION').strip()=='2.10.6.5'
    assert text('MIGRATION_VERSION').strip()=='2.10.6.5'
    assert 'version: "2.10.6.5"' in text('assets/js/site-config.js')

def test_arena_identity_derives_page_and_csv_urls_from_filename():
    php=r'''require "server/team-points/src/LiveRanksService.php";
    $r=new ReflectionClass("P2K\\TeamPoints\\LiveRanksService");$o=$r->newInstanceWithoutConstructor();$m=$r->getMethod("arenaIdentityFromName");$m->setAccessible(true);
    $x=$m->invoke($o,"les-amigos-de-bruxelles-24-hours-32-blitz-bonanza-31357259.csv");
    if(($x['arena_id']??0)!==31357259)exit(11);
    if(($x['arena_url']??'')!=="https://www.chess.com/tournament/live/arena/les-amigos-de-bruxelles-24-hours-32-blitz-bonanza-31357259")exit(12);
    if(($x['csv_url']??'')!=="https://www.chess.com/tournament/live/arena/les-amigos-de-bruxelles-24-hours-32-blitz-bonanza-31357259.csv")exit(13);echo "ok";'''
    p=subprocess.run(['php','-r',php],cwd=ROOT,text=True,capture_output=True)
    assert p.returncode==0,(p.stdout,p.stderr)
    assert p.stdout.strip()=='ok'

def test_index_is_identity_only_and_csv_is_deterministic():
    php=r'''require "server/team-points/src/LiveRanksService.php";
    $r=new ReflectionClass("P2K\\TeamPoints\\LiveRanksService");$o=$r->newInstanceWithoutConstructor();$m=$r->getMethod("discoverArenaEntries");$m->setAccessible(true);
    $html='<article><a href="/tournament/live/arena/alpha-31319871">Alpha</a><a href="/some/wrong/export.csv">CSV</a></article>';
    $x=$m->invoke($o,$html,'https://www.chess.com/clubs/pastevents/promote-to-king?type=multi-club-arena');
    if(count($x)!==1)exit(21);if(($x[0]['arena_id']??0)!==31319871)exit(22);
    if(($x[0]['csv_url']??'')!=="https://www.chess.com/tournament/live/arena/alpha-31319871.csv")exit(23);echo "ok";'''
    p=subprocess.run(['php','-r',php],cwd=ROOT,text=True,capture_output=True)
    assert p.returncode==0,(p.stdout,p.stderr)
    assert p.stdout.strip()=='ok'

def test_index_pagination_is_only_needed_until_latest_stored_boundary():
    php=r'''require "server/team-points/src/LiveRanksService.php";
    $r=new ReflectionClass("P2K\\TeamPoints\\LiveRanksService");$o=$r->newInstanceWithoutConstructor();$m=$r->getMethod("indexBoundaryReached");$m->setAccessible(true);
    $front=[['arena_id'=>120],['arena_id'=>119],['arena_id'=>118]];
    if(!$m->invoke($o,$front,119))exit(31);          // exact latest stored is on page 1
    if(!$m->invoke($o,$front,121))exit(32);          // page 1 already crossed it
    if($m->invoke($o,$front,110))exit(33);           // all page-1 IDs are newer -> paginate
    if(!$m->invoke($o,$front,0))exit(34);            // no history -> page 1 is enough
    echo "ok";'''
    p=subprocess.run(['php','-r',php],cwd=ROOT,text=True,capture_output=True)
    assert p.returncode==0,(p.stdout,p.stderr)
    assert p.stdout.strip()=='ok'

def test_normal_scan_does_not_force_refresh_recent_stored_events():
    svc=text('server/team-points/src/LiveRanksService.php')
    assert 'array_slice($discovered,0,40)' not in svc
    assert "$arena['force_csv_refresh']=true" not in svc
    assert "possibleRenameArenaIdentities" in svc and "force_csv_refresh" in svc
    assert "!is_array($existing)||!empty($arena['force_csv_refresh'])" in svc

def test_csv_and_date_routes_are_independent_of_index_pagination():
    svc=text('server/team-points/src/LiveRanksService.php')
    assert "$url.'.csv'" in svc
    assert "if($csvUrl==='')$csvUrl=rtrim((string)$item['arena_url'],'/').'.csv'" in svc
    assert "$page=$this->syncHttpGet((string)$item['arena_url']);$date=$this->extractArenaStart" in svc
    assert 'No Results CSV export was exposed and paginated page fallback was refused' not in svc
    assert "latest_stored_arena_id" in svc
