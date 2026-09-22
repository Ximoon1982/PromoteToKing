from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def read(p): return (ROOT/p).read_text(encoding="utf-8")

def test_v2130_mca_scrape_primary_and_health_contracts():
    schema=read("server/team-points/sql/analytics-schema.sql")
    service=read("server/team-points/src/McaResultsCronService.php")
    live=read("server/team-points/src/LiveRanksService.php")
    endpoint=read("server/team-points/public/live-ranks-admin.php")
    admin=read("TeamPointsAdmin.html")
    js=read("assets/js/pages/team-points-features.js")
    health=read("assets/js/admin/admin-session-controller.js")
    ui=read("ui-v2.html")
    assert "discovery_failure_streak" in schema and "last_successful_discovery_at" in schema
    assert "function startFullDiscovery" in service
    assert "function queueHistoricalStatsBackfill" in service
    assert "discovery_failure_streak=discovery_failure_streak+1" in service
    assert "discovery_failure_streak=0" in service
    assert "$playerStatsComplete" in service
    assert "arenaScrapedResultTotals" in live
    assert "sync_full_discovery" in endpoint and "sync_backfill_stats" in endpoint
    assert "Full arena index refresh" in admin
    assert "Backfill historical W/D/L" in admin
    assert "Stored MCA source CSV files / arena inventory" in admin
    assert "<h2>Historical MCA date repair</h2>" not in admin
    assert "<h2>MCA source integrity</h2>" not in admin
    assert "runMcaFullRefresh" in js and "runMcaStatsBackfill" in js
    assert "allFiles.filter(file => file.canonical_source !== false)" in js
    assert "live-ranks-admin.php?action=status" in health
    assert 'name: "MCA arena synchronization"' in health
    assert "processing?.finished_at" not in health
    assert "scraped Player Results" in ui
