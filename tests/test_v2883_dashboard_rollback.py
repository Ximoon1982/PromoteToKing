from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding="utf-8",errors="ignore")
def block(src,start,end):
    a=src.index(start); b=src.index(end,a); return src[a:b]

def test_v2883_emergency_rollback_is_superseded_by_v289_root_fix():
    assert tuple(map(int,text("VERSION").strip().split("."))) >= (2,8,9)
    js=text("assets/js/pages/dashboard-v2.js")
    assert "integratedFrameIds" not in js
    assert 'document.querySelectorAll("iframe.dashboard-integrated-frame[id]")' in js
    assert 'clubMembersAPI' not in js

def test_v286_dashboard_contract_is_still_used_but_complete():
    js=text("assets/js/pages/dashboard-v2.js")
    load=block(js,"async function loadTeamData","function renderGauge")
    assert 'await (window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("team")' in load
    assert "clubMembersAPI" not in load and "loadJSON(clubProfileAPI)" not in load and "clubMatchesAPI" in load
    assert 'setMatchMetric("finished"' not in load

def test_acamr_is_reenabled_without_touching_dashboard_startup():
    auth=text("assets/js/shared/authenticated-member-refresh.js")
    plan=text("server/team-points/public/acamr-plan.php")
    assert "realOAuthSession(session)" in auth
    assert "simulated && oauthFlagEnabled()" in auth
    assert "acamrCandidateMembers" in plan
    assert "'club_points'=>true" in plan and "'member_points'=>true" in plan
    assert "'tournaments'=>false" in plan and "'match_registration'=>false" in plan
