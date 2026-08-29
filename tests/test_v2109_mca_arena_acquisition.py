from pathlib import Path
import subprocess, json, tempfile, os, textwrap, base64

ROOT=Path(__file__).resolve().parents[1]

def test_release_identity():
    assert (ROOT/'VERSION').read_text().strip()=='2.10.9'
    assert (ROOT/'MIGRATION_VERSION').read_text().strip()=='2.10.9'
    manifest=json.loads((ROOT/'site-manifest.json').read_text())
    assert manifest['version']=='2.10.9'
    assert manifest['release']['databaseSchemaChange'] is True

def test_schema_10_and_arena_tables():
    repo=(ROOT/'server/team-points/src/Repository.php').read_text()
    assert 'ANALYTICS_SCHEMA_VERSION = 10' in repo
    mig=(ROOT/'server/team-points/sql/analytics-migration-v2.10.9.sql').read_text()
    for table in ['p2k_lr_arena_acquisition','p2k_lr_arena_clubs','p2k_lr_arena_players','p2k_lr_arena_games']:
        assert f'CREATE TABLE IF NOT EXISTS {table}' in mig
    assert 'VALUES(10)' in mig
    assert 'arena_backfill_seeded_at' in mig
    assert 'date_index_next_page' in mig

def test_worker_is_resumable_prioritized_and_throttled():
    s=(ROOT/'server/team-points/src/McaResultsCronService.php').read_text()
    assert "priority=GREATEST(priority,100)" in s
    assert "ORDER BY priority DESC,arena_id DESC" in s
    for stage in ["$stage==='arena'","$stage==='results'","$stage==='clubs'","$stage==='players'","$stage==='pairings'","$stage==='date_index'","$stage==='done'"]:
        assert stage in s
    assert 'pairings_next_page' in s and 'players_next_page' in s and 'clubs_next_page' in s and 'date_index_next_page' in s
    assert 'if($elapsed<1.0)usleep' in s
    assert "DATE_ADD(UTC_TIMESTAMP(),INTERVAL 12 HOUR)" in s
    assert "source_kind,priority,status,stage" in s
    assert "'historical',10,'pending','arena'" in s
    assert 'arena_backfill_seeded_at' in s
    assert "if(!empty($state['arena_backfill_seeded_at']))return" in s
    assert 'CURLOPT_TIMEOUT=>$timeout' in s and 'max(1,min(8' in s

def test_results_primary_html_fallback_pairings_always():
    s=(ROOT/'server/team-points/src/McaResultsCronService.php').read_text()
    assert 'McaArenaParser::resultsCsv' in s
    assert "'html-fallback'" in s
    assert "McaArenaParser::clubRows" in s
    assert "McaArenaParser::playerRows" in s
    assert "McaArenaParser::pairingRows" in s
    assert "stage=IF(needs_players=1,'players','pairings')" in s
    assert "stage='pairings'" in s

def test_schema_upgrade_cli_exists_and_is_network_free():
    s=(ROOT/'server/team-points/bin/upgrade-schema-v2.10.9.php').read_text()
    assert 'upgradeExistingSchema' in s
    assert 'schemaInstalled' in s
    assert 'McaResultsCronService' not in s and 'ChessApi' not in s and 'http' not in s.lower()

def test_cron_every_minute_and_preservation_logic():
    s=(ROOT/'reset-install-mca-cron-v2.10.9.sh').read_text()
    assert '* * * * * P2K_SITE_ROOT=' in s
    assert '$RUNNER 55' in s and 'cron-mca-arena-v2.10.9.sh' in s
    assert 'cron-mca-results-v2\\.10\\.6' in s
    assert 'crontab "$TMP"' in s
    assert 'restoring previous crontab' in s
    runner=(ROOT/'cron-mca-arena-v2.10.9.sh').read_text()
    assert 'mca-results-sync.php' in runner

def test_parser_synthetic_html_and_two_csv_tables():
    html='''<!doctype html><html><body>
    <div class="tournaments-live-view-content-stats"><span>Rating: Open</span><span>2 players (12 max eligible scorers per club)</span><span>Aug 27, 2026, 7:30 PM</span></div>
    <a href="https://www.chess.com/tournament/live/arena/sample-31369035/download-results" title="Download Results">x</a>
    <div id="clubs-pagination-bottom" data-total-pages="2"></div><div id="players-pagination-bottom" data-total-pages="3"></div><div id="pairings-pagination-bottom" data-total-pages="4"></div>
    Club Results<table><tbody><tr><td>#1</td><td><a href="https://www.chess.com/club/promote-to-king"><img></a><a href="https://www.chess.com/club/promote-to-king" title="Promote to King">Promote to King</a></td><td class="table-text-right"> 2 </td><td class="table-text-right"> 30 </td></tr></tbody></table>
    Player Results<table><tbody><tr class="tournaments-live-view-results-row"><td><span>#1</span></td><td><a href="https://www.chess.com/member/alpha" data-test-element="user-tagline-username">Alpha</a><span class="user-rating">(1500)</span></td><td><a href="https://www.chess.com/club/promote-to-king">Promote to King</a></td><td><div class="tournaments-live-view-total-score" v-tooltip="3 wins, 1 draws, 0 bye">20</div><span class="lost">2 L</span><span class="tournaments-live-view-streak-record">4</span></td></tr></tbody></table>
    <h2>Pairings</h2><table><tbody><tr><td><a href="https://www.chess.com/game/live/123"></a><a href="https://www.chess.com/member/alpha" data-test-element="user-tagline-username">Alpha</a><span class="user-rating">(1500)</span></td><td>1 - 0</td><td><a href="https://www.chess.com/member/beta" data-test-element="user-tagline-username">Beta</a><span class="user-rating">(1400)</span></td></tr></tbody></table>
    </body></html>'''
    csv='rank,federation,title,username,name,club,score,longest streak,most wins\n1,FR,,Alpha,,Promote to King,20,4,3\n\nrank,club,total players,score\n1,Promote to King,2,30\n'
    php='''require %s; require %s; $h=file_get_contents(%s); $c=\\P2K\\TeamPoints\\McaArenaParser::clubRows($h); $p=\\P2K\\TeamPoints\\McaArenaParser::playerRows($h); $g=\\P2K\\TeamPoints\\McaArenaParser::pairingRows($h); $m=\\P2K\\TeamPoints\\McaArenaParser::arenaPage($h); $x=\\P2K\\TeamPoints\\McaArenaParser::resultsCsv(base64_decode(%s)); echo json_encode([$m,$c,$p,$g,$x]);''' % (
        repr(str(ROOT/'server/team-points/src/McaIndexParser.php')),
        repr(str(ROOT/'server/team-points/src/McaArenaParser.php')),
        repr('/tmp/p2k-v2109-parser-fixture.html'),repr(base64.b64encode(csv.encode()).decode()))
    Path('/tmp/p2k-v2109-parser-fixture.html').write_text(html)
    out=subprocess.check_output(['php','-r',php], text=True)
    m,c,p,g,x=json.loads(out)
    assert m['clubs_pages']==2 and m['players_pages']==3 and m['pairings_pages']==4
    assert c[0]['club']=='Promote to King' and c[0]['total_players']==2
    assert p[0]['username']=='Alpha' and p[0]['wins']==3 and p[0]['draws']==1 and p[0]['losses']==2 and p[0]['streak']==4
    assert g[0]['game_id']==123 and g[0]['white']=='Alpha' and g[0]['black']=='Beta' and g[0]['result']=='1 - 0'
    assert len(x['players'])==1 and len(x['clubs'])==1


def test_cron_installer_preserves_unrelated_entries(tmp_path):
    fakebin=tmp_path/'bin'; fakebin.mkdir()
    state=tmp_path/'crontab.txt'
    state.write_text('MAILTO=admin@example.com\n*/5 * * * * /opt/unrelated.sh\n# P2K_MCA_RESULTS_SYNC_BEGIN\n17 0,12 * * * /old/cron-mca-results-v2.10.6.25.sh\n# P2K_MCA_RESULTS_SYNC_END\n0 * * * * /opt/p2k-other.sh\n')
    fake=fakebin/'crontab'
    fake.write_text('#!/usr/bin/env bash\nset -e\nS="${FAKE_CRONTAB_FILE:?}"\nif [[ "${1:-}" == "-l" ]]; then [[ -f "$S" ]] && cat "$S"; exit 0; fi\nif [[ "${1:-}" == "-r" ]]; then rm -f "$S"; exit 0; fi\nif [[ $# -eq 1 && "$1" != "-" ]]; then cp "$1" "$S"; exit 0; fi\ncat > "$S"\n')
    fake.chmod(0o755)
    env=os.environ.copy(); env['PATH']=str(fakebin)+os.pathsep+env['PATH']; env['FAKE_CRONTAB_FILE']=str(state)
    r=subprocess.run(['bash',str(ROOT/'reset-install-mca-cron-v2.10.9.sh'),str(ROOT)],env=env,text=True,capture_output=True)
    assert r.returncode==0,(r.stdout,r.stderr)
    out=state.read_text()
    assert '*/5 * * * * /opt/unrelated.sh' in out
    assert '0 * * * * /opt/p2k-other.sh' in out
    assert 'cron-mca-results-v2.10.6.25.sh' not in out
    assert out.count('cron-mca-arena-v2.10.9.sh')==1
    assert '* * * * * P2K_SITE_ROOT=' in out
