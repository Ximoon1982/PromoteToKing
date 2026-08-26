from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(path):
    return (ROOT / path).read_text(encoding="utf-8")

def test_member_rename_chronology_collapses_transient_lifecycle_events():
    repo=text("server/team-points-green/src/GreenRepository.php")
    assert "private function collapseMemberEvents" in repo
    assert "event_type']??'')!=='name_changed'" in repo
    assert "in_array($type,['discovered','joined'],true)" in repo
    assert "$type==='left'" in repo
    assert "return $this->collapseMemberEvents($events,$limit);" in repo
    assert "DELETE FROM p2k_g_member_events WHERE identity_id=? AND event_type IN ('discovered','joined')" in repo
    assert "recent_false_departure" in repo

def test_admin_member_lookup_is_authorized_and_resolves_known_identity_data():
    api=text("server/team-points-green/public/api.php")
    repo=text("server/team-points-green/src/GreenRepository.php")
    dashboard=text("assets/js/pages/dashboard-v2.js")
    assert "GreenConfig::authorizeAdmin()" in api
    assert "if($action==='member-lookup')" in api
    assert "public function memberLookup(string $username): array" in repo
    for marker in ("p2k_g_player_aliases","p2k_g_identity_map","p2k_g_member_events","p2k_g_point_events","p2k_g_match_players","p2k_an_achievement_unlocks","p2k_lr_players"):
        assert marker in repo
    assert "Member lookup" in dashboard
    assert "adminMemberLookupForm" in dashboard
    assert "action','member-lookup'" in dashboard
    assert "Membership lifecycle" in dashboard and "Activity & freshness" in dashboard

def test_tournament_management_lists_known_tournaments_with_status():
    page=text("TournamentManagement.html")
    assert "Known tournaments" in page
    assert 'id="knownTournaments"' in page
    assert "renderKnownTournaments" in page
    for label in ("Tournament","Status","Period","Type","Players","Updated"):
        assert f"<th>{label}</th>" in page
    for status in ("finished","in_progress","registration","unknown"):
        assert status in page

def test_new_matches_24h_distinguishes_unknown_from_authoritative_zero():
    dashboard=text("assets/js/pages/dashboard-v2.js")
    assert "adminRecentMatches: null" in dashboard
    assert 'state.adminRecentMatches = Array.isArray(recent?.matches) ? recent.matches : []' in dashboard
    assert 'catch (_) { state.adminRecentMatches = null; }' in dashboard
    assert 'state.adminRecentMatches ? number(state.adminRecentMatches.length) : "—"' in dashboard

def test_release_identity_and_cache_markers():
    assert text("VERSION").strip()=="2.10.6.23"
    assert text("MIGRATION_VERSION").strip()=="2.10.6.23"
    site=text("assets/js/site-config.js")
    ui=text("ui-v2.html")
    assert 'version: "2.10.6.23"' in site
    assert "dashboard-v2.css?v=2.10.6.23" in ui
    assert "dashboard-v2.js?v=2.10.6.23" in ui
    assert "site-config.js?v=2.10.6.23" in ui
