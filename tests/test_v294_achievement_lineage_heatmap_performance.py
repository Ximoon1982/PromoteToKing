from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding="utf-8",errors="ignore")

def keys():
    return re.findall(r"self::item\('([^']+)'", text("server/team-points/src/AchievementCatalog.php"))

def test_current_achievement_catalog_preserves_all_v291_keys_and_adds_five_new_breadth_keys():
    current=keys()
    legacy=(ROOT/"tests/fixtures/v291_achievement_keys.txt").read_text(encoding="utf-8").splitlines()
    added=[f"breadth-groups-{n}" for n in (1,5,10,15,20)]
    assert len(legacy)==128
    assert len(set(legacy))==128
    assert len(current)==162
    assert current[:len(legacy)]==legacy
    assert current[len(legacy):len(legacy)+5]==added
    assert set(legacy).issubset(current)
    assert set(added).issubset(current)
    # The four actually shipped v2.9.0/v2.9.1 breadth identities are historical
    # achievements and must never be removed or reinterpreted.
    for key in ["groups-5","groups-10","groups-15","groups-all"]:
        assert key in current

def test_hall_unified_player_search_uses_full_width_outer_host_and_four_desktop_cards():
    css=text("assets/css/dashboard-v2.css") + "\n" + text("assets/css/responsive-unification.css")
    js=text("assets/js/pages/dashboard-hall.js")
    assert "#hallOfFamePage #hallUnifiedResults{" in css
    assert "display:block!important" in css
    assert "grid-template-columns:none!important" in css
    assert "#hallOfFamePage #hallUnifiedResults .p2k-hall-unified-grid" in css
    assert "grid-template-columns:repeat(4,minmax(0,1fr))!important" in css
    assert "overflow-wrap:anywhere" in css
    assert 'grid.className="p2k-hall-unified-grid"' in js
    assert js.count("hallResultCard(") >= 4

def test_balance_backend_uses_compact_unsorted_history_payload():
    repo=text("server/team-points/src/Repository.php")
    start=repo.index("public function publicOpponentBalance")
    end=repo.index("/** v2.8.8: summary/top chart",start)
    block=repo[start:end]
    assert "'format'=>2" in block
    assert "'opponents'=>$opponents" in block
    assert "'columns'=>['boards','rated_boards','is_league','chess_code','p2k_avg_rating','opponent_avg_rating','opponent_index']" in block
    assert "ORDER BY COALESCE(end_time,start_time)" not in block
    assert "SELECT board_count,rated_board_count,is_league,rules,p2k_avg_rating,opponent_avg_rating,opponent_slug,opponent_name" in block
    assert "match_id,status" not in block

def test_balance_endpoint_cache_is_stable_and_does_not_depend_on_table_options_or_core_generation():
    endpoint=text("server/team-points/public/opponents.php")
    assert "$cacheKey='opponents-balance-v3|'.$club" in endpoint
    assert "$ttl=900" in endpoint and "$stale=3600" in endpoint
    balance_branch=endpoint[endpoint.index("if($section==='balance')"):endpoint.index("}else{",endpoint.index("if($section==='balance')"))]
    assert "json_encode($options)" not in balance_branch
    assert "publicReadGenerationToken" not in endpoint

def test_balance_browser_starts_in_parallel_reuses_session_snapshot_and_minimizes_url():
    js=text("assets/js/pages/dashboard-insights.js")
    fn=js[js.index("function opponentInsightsURL"):js.index("function applyOpponentSummaryPayload")]
    assert 'if (section === "balance")' in fn
    assert 'url.searchParams.set("section", "balance")' in fn
    assert 'return url.href;' in fn
    load=js[js.index("async function loadOpponentInsights"):js.index("return Object.freeze",js.index("async function loadOpponentInsights"))]
    assert 'snapshotGet?.(cacheKey,6*3600000)' in load
    assert 'snapshotSet?.(cacheKey,payload)' in load
    assert "void loadBalance();" in load
    assert load.index("void loadBalance();") < load.index('loadJSON(opponentInsightsURL(state.opponentsTableState,{section:"summary"})')

def test_balance_renderer_normalizes_once_and_bins_aggregates_not_row_arrays():
    js=text("assets/js/pages/opponent-balance-analyzer.js")
    assert "const normalizedCache = new WeakMap();" in js
    assert "Number(data.format) === 2" in js
    assert "_boardLog: Math.log10(boards)" in js
    assert "{ n: 0, deltaSum: 0, coverageSum: 0 }" in js
    assert "bin.deltaSum +=" in js and "bin.coverageSum +=" in js
    assert "bin.rows.push" not in js
    assert "opponents.slice(0, 100)" in js
