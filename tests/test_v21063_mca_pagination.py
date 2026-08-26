from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_identity():
    assert text('VERSION').strip()=='2.10.6.3'
    assert text('MIGRATION_VERSION').strip()=='2.10.6.3'
    assert 'version: "2.10.6.3"' in text('assets/js/site-config.js')

def test_pagination_links_are_discovered_from_navigation_not_hardcoded():
    php=r'''require "server/team-points/src/LiveRanksService.php";
    $r=new ReflectionClass("P2K\\TeamPoints\\LiveRanksService");$o=$r->newInstanceWithoutConstructor();
    $m=$r->getMethod("discoverPaginationLinks");$m->setAccessible(true);
    $index='https://www.chess.com/clubs/pastevents/promote-to-king?type=multi-club-arena';
    $html='<nav><a href="?type=multi-club-arena&page=2">2</a><a class="pagination-next" href="?type=multi-club-arena&offset=50">Next</a><a href="?type=multi-club-arena&tab=games">Games</a></nav>';
    $links=$m->invoke($o,$html,$index);if(count($links)!==2)exit(11);$joined=implode("\n",$links);if(!str_contains($joined,'page=2')||!str_contains($joined,'offset=50')||str_contains($joined,'tab=games'))exit(12);
    $arena='https://www.chess.com/tournament/live/arena/example-arena-123';
    $html2='<a href="?page=2">2</a><a rel="next" href="?page=3">Next</a><a href="?tab=games">Games</a>';
    $links2=$m->invoke($o,$html2,$arena);if(count($links2)!==2||!str_contains(implode("\n",$links2),'page=3'))exit(13);echo 'ok';'''
    p=subprocess.run(['php','-r',php],cwd=ROOT,text=True,capture_output=True)
    assert p.returncode==0,(p.stdout,p.stderr)
    assert p.stdout.strip()=='ok'

def test_results_pagination_is_durable_and_one_page_per_step():
    svc=text('server/team-points/src/LiveRanksService.php')
    assert "phase='index_pages'" in svc
    assert "stage='results_pages'" in svc
    assert "writeSyncScratch('index'" in svc and "writeSyncScratch('arena'" in svc
    assert 'advanceIndexPagination' in svc and 'advanceArenaResultPagination' in svc
    assert "array_shift($pending);$visited[$url]=true;$page=$this->syncHttpGet($url)" in svc
    assert 'MCA Player Results pagination ended early' in svc
    assert '200-page safety limit' in svc

def test_visible_past_event_date_beats_unrelated_future_time():
    php=r'''require "server/team-points/src/LiveRanksService.php";
    $r=new ReflectionClass("P2K\\TeamPoints\\LiveRanksService");$o=$r->newInstanceWithoutConstructor();$m=$r->getMethod("extractArenaStart");$m->setAccessible(true);
    $html='<time datetime="2028-08-07T10:00:00Z"></time><div>Rating: Open 127 players Aug 18, 2026, 7:30 PM</div>';
    $x=$m->invoke($o,$html);if(($x['event_date']??'')!=='2026-08-18'||($x['precision']??'')!=='visible')exit(21);echo 'ok';'''
    p=subprocess.run(['php','-r',php],cwd=ROOT,text=True,capture_output=True)
    assert p.returncode==0,(p.stdout,p.stderr)
    assert p.stdout.strip()=='ok'

def test_future_dates_are_requeued_for_backfill_and_ui_shows_pagination():
    svc=text('server/team-points/src/LiveRanksService.php');html=text('TeamPointsAdmin.html');js=text('assets/js/pages/team-points-features.js')
    assert 'clearImpossibleFutureEventDates' in svc and 'actual_event_date>UTC_DATE()' in svc
    assert 'Walks every paginated page' in html
    assert 'Index pages' in js and 'Result pages' in js and 'Players collected' in js
