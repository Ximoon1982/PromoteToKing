from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]

def text(path):
    return (ROOT / path).read_text(encoding="utf-8")

def test_recruitment_v2_surface_and_db_first_pipeline():
    html = text("RecruitMatch.html")
    js = text("assets/js/pages/recruit-match.js")
    core = text("assets/js/pages/recruit-match-v2-core.js")
    assert 'id="p2kOnlineDays"' in html and 'value="1"' in html
    assert 'id="p2kTimeoutRate"' in html and 'value="5"' in html
    assert "recruitment-pool.php" in js
    scan = js[js.index("async function scan"):]
    assert scan.index("recruitment-pool.php") < scan.index("C.preselect") < scan.index("/members`") < scan.index("processPriority")
    assert "partialValues" in js and "unverified" in core
    assert "P2K_API_CLIENT.processPriority" in js
    assert "p2kProgressDetails" in html and "p2kFunnel" in html
    assert "Export eligible CSV" in js and 'data-sort="rating"' in js

def test_recruitment_v2_does_not_scan_live_before_db_filter():
    js = text("assets/js/pages/recruit-match.js")
    scan = js[js.index("async function scan") :]
    assert scan.index("C.preselect") < scan.index("processPriority")
    assert "pub/club/${encodeURIComponent(slug(b[1]))}/members" in scan
    assert "pub/player/${encodeURIComponent(row.username)}" in js

def test_recruitment_pool_remains_current_member_db_source():
    repo = text("server/team-points/src/Repository.php")
    endpoint = text("server/team-points/public/recruitment-pool.php")
    assert "current_member=1" in repo
    assert "recruitmentRatingPool" in endpoint
    assert "ClubIntelligenceService" not in endpoint and "memberActivity" not in endpoint
    assert "PublicReadDatabase::analytics" not in endpoint
    assert all(field in repo for field in ["rating_updated_at", "rating_source", "rating_verified", "current_member=1"])

def test_hard_roster_freshness_timeout_and_optional_load_contracts():
    js = text("assets/js/pages/recruit-match.js")
    core = text("assets/js/pages/recruit-match-v2-core.js")
    assert 'members`,ctl.signal,"no-store"' in js
    assert 'api(base,signal,"no-store")' in js and 'api(`${base}/stats`,signal,"no-store")' in js
    assert "Opponent membership could not be verified from a current roster" in js
    assert "Chess.com Daily record timeout_percent" in js
    assert "stats?.chess_daily?.record" in js and "timeout_percent" in js
    assert "current_match_load_error" in js
    assert "async function enrichLoads" in js and "void enrichLoads(" in js
    assert "row.live?.current_match_load" in core and "row.current_load" not in core

def test_profile_actions_are_delegated_across_redraws():
    js = text("assets/js/pages/recruit-match.js")
    assert 'n.results.addEventListener("click"' in js
    assert 'closest?.("[data-profile]")' in js
    assert 'postMessage({type:"p2k-open-player-profile",username},location.origin)' in js
    assert "n.results.querySelectorAll(\"[data-profile]\")" not in js

def test_recruitment_v2_immutable_cache_identity():
    html = text("RecruitMatch.html")
    key = "p2k-2.12.0-71d3da9b1ba6-c168bd00d65ee5ee"
    assert f"recruit-match.css?v={key}" in html
    assert f"recruit-match-v2-core.js?v={key}" in html
    assert f"recruit-match.js?v={key}" in html
    assert "2.12.0-dev" not in html
    assert "p2k-2.12.0-edef0aa06996-c9834356f27757e6" not in html

def test_recruitment_v2_cache_identity_is_canonically_derived():
    import importlib.util
    path = ROOT / "tools/release/static_asset_cache_key.py"
    spec = importlib.util.spec_from_file_location("p2k_cache_key", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    source = "71d3da9b1ba63801a421ac8d56180a8af5158ba5"
    assert subprocess.run(["git", "cat-file", "-e", f"{source}^{{commit}}"], cwd=ROOT).returncode == 0
    assert module.make_key("2.12.0", source, "match-recruitment-final-corrective-1") == "p2k-2.12.0-71d3da9b1ba6-c168bd00d65ee5ee"
