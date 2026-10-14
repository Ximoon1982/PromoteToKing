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
