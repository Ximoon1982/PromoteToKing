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


def test_v2130_full_refresh_uses_page_fingerprint_not_generic_row_timestamps():
    schema=read("server/team-points/sql/analytics-schema.sql")
    service=read("server/team-points/src/McaResultsCronService.php")
    assert "last_index_page_fingerprint CHAR(64) NULL" in schema
    assert "'last_index_page_fingerprint'=>\"CHAR(64) NULL AFTER last_successful_discovery_at\"" in service
    fetch=service[service.index("private function fetchDiscoveryIndexPage"):service.index("private function dimensionUrl")]
    assert "hash_equals($previous,$fingerprint)" in fetch
    assert "page_fingerprint" in fetch
    assert "countNotSeenThisCycle" not in fetch
    assert "updated_at>=?" not in fetch
    assert "last_index_page_fingerprint=?" in service
    assert service.count("last_index_page_fingerprint=NULL") >= 3

def test_v2130_discovery_page_fingerprint_rejects_only_repeated_page_content():
    import subprocess
    php=r'''require "server/team-points/src/McaResultsCronService.php";
    $r=new ReflectionClass("P2K\\TeamPoints\\McaResultsCronService");
    $o=$r->newInstanceWithoutConstructor();
    $m=$r->getMethod("discoveryPageFingerprint");$m->setAccessible(true);
    $page1=[["arena_id"=>31390001],["arena_id"=>31390000],["arena_id"=>31389999]];
    $same=[["arena_id"=>31390001],["arena_id"=>31390000],["arena_id"=>31389999]];
    $page2=[["arena_id"=>31389998],["arena_id"=>31389997],["arena_id"=>31389996]];
    $a=$m->invoke($o,$page1);$b=$m->invoke($o,$same);$c=$m->invoke($o,$page2);
    if($a!==$b)exit(11);
    if($a===$c)exit(12);
    if(strlen($a)!==64||strlen($c)!==64)exit(13);
    echo "ok";'''
    p=subprocess.run(["php","-r",php],cwd=ROOT,text=True,capture_output=True)
    assert p.returncode==0,(p.stdout,p.stderr)
    assert p.stdout.strip()=="ok"


def test_v2130_full_refresh_falls_back_to_known_inventory_when_upstream_pagination_repeats():
    service=read("server/team-points/src/McaResultsCronService.php")
    assert "$this->seedHistoricalBacklog();" in service
    assert "$this->requeueKnownInventoryForFullRefresh();" in service
    assert "private function requeueKnownInventoryForFullRefresh(): int" in service
    assert "priority=GREATEST(priority,80)" in service
    assert "'pagination_repeated'=>true" in service
    assert "if($fullReconciliation){$this->completeDiscoveryCycle($error);return false;}" in service
    assert "'discovery_limited'" in service
    assert "Exhaustive historical index discovery is unavailable upstream" in service
    assert "hash_equals($previous,$fingerprint)" in service
