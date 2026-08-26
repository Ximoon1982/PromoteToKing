from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_identity():
    assert text('VERSION').strip()=='2.10.6.2'
    assert text('MIGRATION_VERSION').strip()=='2.10.6.2'
    assert 'version: "2.10.6.2"' in text('assets/js/site-config.js')

def test_mca_no_longer_invents_dot_csv_url_and_surfaces_errors():
    svc=text('server/team-points/src/LiveRanksService.php')
    adm=text('server/team-points/public/live-ranks-admin.php')
    assert "'csv_url'=>'','original_name'=>$slug.'.csv'" in svc
    assert 'extractArenaCsvUrl' in svc and 'arenaPageResultsCsv' in svc
    assert 'Player Results table was incomplete' in svc
    assert "status='error' ORDER BY updated_at DESC" in svc
    assert 'retryAutoSyncErrors' in svc
    assert "$action === 'sync_retry_errors'" in adm

def test_mca_page_fallback_fixture_is_complete_and_csv_compatible():
    php=r'''require "server/team-points/src/LiveRanksService.php";
    $r=new ReflectionClass("P2K\\TeamPoints\\LiveRanksService");$o=$r->newInstanceWithoutConstructor();
    $m=$r->getMethod("arenaPageResultsCsv");$m->setAccessible(true);
    $html='<div>Rating: Open 2 players Aug 18, 2026, 7:30 PM</div><table><tr><td>#1 <a href="/member/alice">Alice</a> (1800)</td><td><a href="/club/promote-to-king">Promote to King</a></td><td>101 4 / 39 W / 3 D / 42 L</td></tr><tr><td>#2 <a href="/member/bob">Bob</a></td><td><a href="/club/foo">Foo</a></td><td>50 2 / 10 W / 2 D / 8 L</td></tr></table>';
    $x=$m->invoke($o,$html);if(($x['rows']??0)!==2||($x['expected']??0)!==2||!str_contains((string)($x['csv']??''),'Username,Club,Score'))exit(11);
    $bad=$m->invoke($o,str_replace('2 players','3 players',$html));if(($bad['csv']??null)!==null||!str_contains((string)($bad['reason']??''),'incomplete'))exit(12);echo 'ok';'''
    p=subprocess.run(['php','-r',php],cwd=ROOT,text=True,capture_output=True)
    assert p.returncode==0,(p.stdout,p.stderr)
    assert p.stdout.strip()=='ok'

def test_blue_green_mca_copy_is_scoped_validated_and_atomic():
    sync=text('server/team-points/src/McaBlueGreenSync.php')
    adm=text('server/team-points/public/live-ranks-admin.php')
    for table in ['p2k_lr_files','p2k_lr_players','p2k_lr_processing_state','p2k_lr_sync_state','p2k_lr_sync_queue','p2k_lr_arena_stats','p2k_lr_source_rows','p2k_lr_attributions']:
        assert table in sync
    assert "hash_file('sha256'" in sync
    assert 'beginTransaction()' in sync and 'rollBack()' in sync and 'commit()' in sync
    assert 'row-count validation failed' in sync
    assert "$action === 'sync_blue_to_green'" in adm

def test_admin_controls_expose_errors_retry_and_blue_green():
    html=text('TeamPointsAdmin.html');js=text('assets/js/pages/team-points-features.js')
    assert 'Retry failed events' in html and 'Sync MCA Blue → Green' in html and 'Failed source events' in html
    assert 'liveRanksSyncErrorRows' in js and 'sync_retry_errors' in js and 'sync_blue_to_green' in js
    assert 'source_files_verified' in js
