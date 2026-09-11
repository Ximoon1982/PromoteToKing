from pathlib import Path

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
    assert js.index("recruitment-pool.php") < js.index("/members`") < js.index("processPriority")
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
    assert "current_load" in endpoint and "last_activity" in endpoint

def test_recruitment_v2_immutable_cache_identity():
    html = text("RecruitMatch.html")
    key = "2.12.0-ee41bf0bad9ec"
    assert f"recruit-match.css?v={key}" in html
    assert f"recruit-match-v2-core.js?v={key}" in html
    assert f"recruit-match.js?v={key}" in html
    assert "2.12.0-dev" not in html
