from pathlib import Path
import json, subprocess

ROOT=Path(__file__).resolve().parents[1]


def php_parse_arena(html: str):
    fixture=Path('/tmp/p2k-v21091-date-fixture.html')
    fixture.write_text(html)
    code=(
        "require " + repr(str(ROOT/'server/team-points/src/McaIndexParser.php')) + ";"
        "require " + repr(str(ROOT/'server/team-points/src/McaArenaParser.php')) + ";"
        "$h=file_get_contents(" + repr(str(fixture)) + ");"
        "echo json_encode(\\P2K\\TeamPoints\\McaArenaParser::arenaPage($h));"
    )
    return json.loads(subprocess.check_output(['php','-r',code],text=True))


def test_corrective_release_identity():
    assert (ROOT/'VERSION').read_text().strip()=='2.10.9.1'
    assert (ROOT/'MIGRATION_VERSION').read_text().strip()=='2.10.9.1'
    manifest=json.loads((ROOT/'site-manifest.json').read_text())
    assert manifest['version']=='2.10.9.1'
    assert manifest['release']['corrective'] is True
    assert manifest['release']['sourceBaseline']=='v2.10.9'
    assert manifest['release']['databaseSchemaChange'] is False
    assert manifest['release']['runtimeDataReset'] is False


def test_arena_page_accepts_machine_timestamp_shapes():
    cases=[
        '<script>{"startTime":"2026-08-18T19:30:00Z"}</script>',
        '<script>{"start_date":"2026-08-18T19:30:00+00:00"}</script>',
        '<script>{"start_time":1787081400000}</script>',
        '<div data-start-time="2026-08-18T19:30:00Z"></div>',
        '<time datetime="2026-08-18T19:30:00Z"></time>',
        '<script>var x="{\\"startTime\\":\\"2026-08-18T19:30:00Z\\"}";</script>',
    ]
    for html in cases:
        parsed=php_parse_arena(html)
        assert parsed['event_date']=='2026-08-18',html
        assert parsed['event_start_at']=='2026-08-18 19:30:00',html
        assert parsed['date_precision']=='arena-machine',html


def test_visible_date_remains_preferred():
    parsed=php_parse_arena('<div>Rating: Open 127 players Aug 18, 2026, 7:30\u202fPM</div><script>{"startTime":"2026-08-17T10:00:00Z"}</script>')
    assert parsed['event_date']=='2026-08-18'
    assert parsed['event_start_at']=='2026-08-18 19:30:00'
    assert parsed['date_precision']=='index-visible-time'


def test_historical_date_fallback_does_not_hard_stop_on_arena_id_order():
    service=(ROOT/'server/team-points/src/McaResultsCronService.php').read_text()
    assert '$oldest<$id' not in service
    assert '$oldest<$arenaId' not in service
    assert "if(!$hasNext||$page>=250)" in service
    assert 'Arena date unavailable after exhaustive MCA index search.' in service


def test_legacy_date_errors_are_requeued_once_without_permanent_retry_loop():
    service=(ROOT/'server/team-points/src/McaResultsCronService.php').read_text()
    assert '$this->requeueLegacyDateErrors();' in service
    assert "last_error IN ('Arena date unavailable on arena page and MCA index.','Arena was found on the MCA index but its date was unavailable.')" in service
    assert 'Arena located on MCA index but date remained unavailable after parser recovery.' in service


def test_worker_reports_corrective_version_and_keeps_throttle():
    service=(ROOT/'server/team-points/src/McaResultsCronService.php').read_text()
    cli=(ROOT/'server/team-points/bin/mca-results-sync.php').read_text()
    assert 'PromoteToKing/2.10.9.1 MCA Arena Acquisition' in service
    assert 'if($elapsed<1.0)usleep' in service
    assert "'version'=>'2.10.9.1'" in cli
