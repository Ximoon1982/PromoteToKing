from pathlib import Path
import json, os
ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding="utf-8",errors="ignore")
def block(src,start,end):
    a=src.index(start); b=src.index(end,a); return src[a:b]

def test_v289_identity_and_operational_contract():
    assert text("VERSION").strip() in {"2.8.9","2.8.10","2.8.11","2.9.0","2.9.1","2.9.2","2.9.4","2.9.5","2.9.7","2.9.8","2.9.9","2.9.10","2.9.11","2.9.12","2.9.12","2.9.13","2.9.14", '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    repo=text("server/team-points/src/Repository.php")
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [6,7,8,9,10,11,12,13,14,15]) and any(f'ANALYTICS_SCHEMA_VERSION = {v}' in repo for v in [5,6,7])
    script=ROOT/"reset-install-cron-v2.8.9.sh"
    assert script.is_file() and os.access(script,os.X_OK)
    src=script.read_text()
    for token in ["*/5 * * * *","2-59/10 * * * *","7,37 * * * *","17 * * * *"]: assert token in src

def test_startup_has_no_lazy_module_owned_free_identifier():
    js=text("assets/js/pages/dashboard-v2.js")
    assert "integratedFrameIds" not in js
    assert "integratedFrameIds" not in text("assets/js/pages/dashboard-insights.js")
    activity=block(js,"function integratedFrames()","function ensureIntegratedFrame")
    assert 'document.querySelectorAll("iframe.dashboard-integrated-frame[id]")' in activity
    assert "frame.id === activeId" in activity

def test_v286_dashboard_second_wave_dependency_is_complete():
    js=text("assets/js/pages/dashboard-v2.js")
    header=js[:1200]
    load=block(js,"async function loadTeamData","function renderGauge")
    assert 'clubMembersAPI' not in header
    assert 'clubMatchesAPI' in load and 'loadJSON(clubProfileAPI)' not in load
    assert 'await (window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("team")' in load

def test_admin_recognition_is_not_remote_gated():
    js=text("assets/js/pages/dashboard-v2.js")
    verify=block(js,"async function verifyAdmin","function adminPanelMarkup")
    assert "configuredAdminUsernames().has(username)" in verify
    assert "if (fallbackAllowed)" in verify and "setAdmin(true, run)" in verify
    assert verify.index("if (fallbackAllowed)") < verify.index("await loadJSON(clubProfileAPI")
    guard=text("assets/js/shared/admin-page-guard.js")
    assert guard.index("if (fallbackAllowed)") < guard.index("await fetch(endpoint")
    assert "window.parent?.P2K_ADMIN_MODE === true" in guard

def test_acamr_reenabled_with_original_scope_boundary():
    auth=text("assets/js/shared/authenticated-member-refresh.js")
    plan=text("server/team-points/public/acamr-plan.php")
    observe=text("server/team-points/public/observe.php")
    api=text("assets/js/shared/api-client.js")+text("assets/js/shared/api-request-coordinator.js")
    assert "realOAuthSession(session)" in auth and "simulated && oauthFlagEnabled()" in auth
    assert "'club_points'=>true" in plan and "'member_points'=>true" in plan
    assert "'tournaments'=>false" in plan and "'match_registration'=>false" in plan
    assert "in_array($source,['acamr','client_refresh'],true)" in observe
    assert 'result.observationSource = options.observationSource' in api
    assert '["acamr","client_refresh"].includes(detail.observationSource)' in api and '${source}:${claimToken}:${url}' in api

def test_acamr_is_loaded_on_all_simulated_auth_surfaces():
    offenders=[]
    for p in list(ROOT.glob("*.html"))+list(ROOT.glob("*.htm")):
        s=p.read_text(encoding="utf-8",errors="ignore")
        if "assets/js/shared/simulated-oauth.js" in s and "assets/js/shared/authenticated-member-refresh.js" not in s: offenders.append(p.name)
    assert offenders==[]

def test_v289_manifest_contract_after_finalization():
    manifest=json.loads(text("site-manifest.json"))
    assert manifest.get("version") in {"2.8.9","2.8.10","2.8.11","2.9.0","2.9.1","2.9.2","2.9.4","2.9.5","2.9.7","2.9.8","2.9.9","2.9.10","2.9.11","2.9.12","2.9.12","2.9.13","2.9.14", '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'} and manifest.get("releaseVersion")==manifest.get("version")
    acamr=manifest.get("acamr",{})
    assert acamr.get("enabled") is True
    assert acamr.get("tournaments") is False and acamr.get("matchRegistration") is False
