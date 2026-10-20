from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
GREEN=(ROOT/"server/team-points-green/src/GreenRepository.php").read_text(encoding="utf-8")
ADMIN=(ROOT/"assets/js/admin/admin-shell.js").read_text(encoding="utf-8")
BUILDER=(ROOT/"tools/release/v2150/build-incremental.sh").read_text(encoding="utf-8")

def test_lookup_reuses_canonical_member_chronology():
    assert "$events=$this->memberEvents(1000,['member'=>$input]);" in GREEN

def test_lookup_surfaces_alias_only_name_changes_transparently():
    assert "lookup_alias_evidence" in GREEN
    assert "inferred_from_alias" in GREEN
    assert "previous_username" in GREEN and "new_username" in GREEN

def test_lookup_no_longer_truncates_history_to_16():
    assert "events.slice(0,16)" not in ADMIN
    assert "events.map(e=>" in ADMIN

def test_lookup_explicitly_displays_known_names_and_lifecycle_counts():
    assert "<h5>Known names</h5>" in ADMIN
    assert "Previous / known name" in ADMIN
    assert "Joins / rejoins" in ADMIN
    assert "Departures" in ADMIN
    assert "known alias evidence" in ADMIN

def test_incremental_contains_member_lookup_backend_and_ui():
    assert "assets/js/admin/admin-shell.js" in BUILDER
    assert "server/team-points-green/src/GreenRepository.php" in BUILDER


def test_lookup_wdl_uses_normalized_point_events_not_native_result_labels():
    block = GREEN[GREEN.index("public function memberLookup"):GREEN.index("public function nextProfileDue")]
    assert "SUM(points=1) wins" in block
    assert "SUM(points=0.5) draws" in block
    assert "SUM(points=0) losses" in block
    assert "LOWER(result) IN ('draw'" not in block
    assert "LOWER(result) IN ('loss'" not in block


def test_member_chronology_is_server_paginated_and_account_filterable():
    api=(ROOT/"server/team-points-green/public/api.php").read_text(encoding="utf-8")
    ui=(ROOT/"assets/js/pages/team-points-admin.js").read_text(encoding="utf-8")
    html=(ROOT/"TeamPointsAdmin.html").read_text(encoding="utf-8")
    assert "memberEventsPage($page,$perPage,$filters)" in api
    assert "per_page:100" in ui
    assert "memberChronologyPrevious" in ui and "memberChronologyNext" in ui
    assert "memberChronologyAccountProfile" in html
    assert "account_profiles" in GREEN
    assert "profile_closure_reason" in GREEN


def test_green_title_profile_pipeline_and_csv_export():
    green=(ROOT/"server/team-points-green/src/GreenRepository.php").read_text(encoding="utf-8")
    schema=(ROOT/"server/team-points-green/sql/core-schema.sql").read_text(encoding="utf-8")
    compat=(ROOT/"server/team-points-green/src/GreenCompatibility.php").read_text(encoding="utf-8")
    export=(ROOT/"server/team-points/public/members-insights-export.php").read_text(encoding="utf-8")
    assert "title VARCHAR(8) NULL" in schema
    assert "$p['title']" in green
    assert "title=NULLIF(?,''" in green
    assert "profile_checked_at IS NULL OR title IS NULL" in green
    assert "p.account_status,p.title" in compat
    assert "PublicReadDatabase::source() === 'green'" in export
    assert "FROM p2k_g_players WHERE username_key IN" in export
    assert "account_status AS profile_status,title" in export
