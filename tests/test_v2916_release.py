from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding="utf-8",errors="ignore")
def test_v2916_release_identity_and_scope():
    assert text("VERSION").strip() in {"2.9.16","2.9.17", '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    assert any(f'RUNTIME_VERSION = "{v}"' in text("assets/js/shared/api-client.js") for v in ["2.9.16","2.9.17", '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'])
    assert "PlayerMatchesFallbackState" in text("server/team-points/src/Worker.php")
    assert "browser_claimable_now" in text("server/control/public/api.php")
    assert 'searchParams.set("ui","v2")' in text("assets/js/pages/dashboard-v2.js").replace(" ","")
    assert "result_coverage_percent" in text("server/team-points/src/Repository.php")
    assert "logic:opponent-coverage-results-v1" in text("server/team-points/src/AnalyticsBuilder.php")
    assert any(f'CORE_SCHEMA_VERSION = {v}' in text('server/team-points/src/Repository.php') for v in [13,14,15])
    assert any(f'ANALYTICS_SCHEMA_VERSION = {v}' in text('server/team-points/src/Repository.php') for v in [6,7])
def test_v2916_operational_scripts_target_only_2916_from_2915():
    u=text("update-v2.9.15-to-v2.9.16.sh")
    assert 'CURRENT" == "2.9.16"' in u and 'CURRENT" != "2.9.15"' in u
    assert 'INCREMENTAL_MANIFEST_v2.9.15_to_v2.9.16.json' in u
    c=text("reset-install-cron-v2.9.16.sh")
    assert c.count('cron-dispatch-v2.9.16.sh')>=2 and '# BEGIN PROMOTE TO KING v2.9.16' in c
